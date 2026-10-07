<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\FeedIngestor;
use Aiya\Core\Domain\Telegram\PollIntake;
use Aiya\Core\Domain\Telegram\Relay;
use Aiya\Core\Domain\Telegram\TelegramBot;
use Aiya\Core\Domain\Telegram\UpdateProcessor;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

/**
 * The site-side poll intake: one tick is a gate (an unconfigured bot
 * sleeps — scheduling itself is the mu-plugin's business), one short poll, every update through the same
 * funnel the webhook serves, and the offset persisted only when it moved.
 * A platform rejection is reported and survived, not fatal — the next
 * tick retries by construction.
 */
final class TelegramPollIntakeTest extends TestCase
{
    private FeedIngestor $feed;

    private Relay $relay;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_http'] = [];
        $GLOBALS['__aiya_test_http_response'] = null;
        unset($GLOBALS['__aiya_test_http_responder']);
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];

        $this->feed = new class extends FeedIngestor {
            /** @var list<array{int, array<string, mixed>, bool}> */
            public array $ingested = [];

            public function ingest(int $chatId, array $post, bool $isEdit = false): string
            {
                $this->ingested[] = [$chatId, $post, $isEdit];

                return 'stored';
            }
        };
        $this->relay = new class extends Relay {
            /** @var list<array{int, array<string, mixed>}> */
            public array $received = [];

            public function onOwnerMessage(int $chatId, array $message): void
            {
                $this->received[] = [$chatId, $message];
            }
        };
        $this->configure();
    }

    private function configure(array $overrides = []): void
    {
        $GLOBALS['__aiya_test_options']['telegram'] = array_merge([
            'tg_bot_token' => 'TOK',
            'tg_mirror_enabled' => true,
            'tg_mirror_source_chat_ids' => '-100111',
        ], $overrides);
    }

    private function intake(): PollIntake
    {
        return new PollIntake(new UpdateProcessor($this->feed, $this->relay));
    }

    private function captureErrors(array &$reported): void
    {
        add_action(TelegramBot::ERROR_ACTION, static function (string $route, string $code, string $message, array $context) use (&$reported): void {
            $reported[] = [$route, $code, $message, $context];
        }, 10, 4);
    }

    /** @param list<array<string, mixed>> $updates */
    private function stage(array $updates): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- staged wire payload
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 200], 'body' => (string) json_encode(['ok' => true, 'result' => $updates])];
    }

    public function testAnUnconfiguredBotSleeps(): void
    {
        $this->configure(['tg_bot_token' => '']);
        $this->stage([['update_id' => 1]]);

        $this->intake()->tick();

        self::assertSame([], $GLOBALS['__aiya_test_http']);
    }

    public function testATickRidesUpdatesThroughTheFunnelAndAdvancesTheOffset(): void
    {
        $GLOBALS['__aiya_test_options']['telegram']['tg_relay_owner_chat_id'] = '777';
        $GLOBALS['__aiya_test_options']['telegram']['tg_relay_enabled'] = true;
        $this->stage([
            ['update_id' => 40, 'channel_post' => ['message_id' => 5, 'chat' => ['id' => -100111, 'type' => 'channel'], 'text' => 'hi']],
            ['update_id' => 41, 'message' => ['message_id' => 6, 'chat' => ['id' => 777, 'type' => 'private'], 'text' => 'owner says']],
        ]);

        $this->intake()->tick();

        self::assertCount(1, $this->feed->ingested, 'the channel post reached the mirror route');
        self::assertCount(1, $this->relay->received, 'the owner message reached the relay route');
        self::assertSame(42, (int) get_option('aiya_core_tg_poll_offset'), 'the offset sits past the last update');

        // A short poll returns quickly: the wire call carried the shared
        // offset and no hold window.
        self::assertSame('{"offset":0,"timeout":0,"limit":10,"allowed_updates":["message","channel_post","edited_channel_post","my_chat_member"]}', (string) $GLOBALS['__aiya_test_http'][0]['args']['body']);

        // The second tick with nothing pending leaves the offset alone.
        $this->stage([]);
        $this->intake()->tick();
        self::assertSame(42, (int) get_option('aiya_core_tg_poll_offset'));
    }

    public function testAPlatformRejectionIsReportedAndSurvived(): void
    {
        $reported = [];
        add_action(TelegramBot::ERROR_ACTION, static function (string $route, string $code, string $message, array $context) use (&$reported): void {
            $reported[] = [$route, $code, $message, $context];
        }, 10, 4);
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 409], 'body' => '{"ok":false,"error_code":409,"description":"Conflict: terminated by other getUpdates request"}'];

        $this->intake()->tick();

        self::assertCount(1, $reported);
        self::assertSame('poll-cron', $reported[0][0]);
        self::assertNull(get_option('aiya_core_tg_poll_offset', null), 'a rejected tick moves nothing');
        self::assertSame([], $this->feed->ingested);
    }

    public function testAWireFailureIsReportedAndSurvived(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        // No staged response: the double answers the dead-transport error.

        $this->intake()->tick();

        self::assertCount(1, $reported);
        self::assertSame('poll-cron', $reported[0][0]);
        self::assertSame([], $this->feed->ingested);
    }
}
