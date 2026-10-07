<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\Pusher;
use Aiya\Core\Domain\Telegram\TelegramBot;
use PHPUnit\Framework\TestCase;
use WP_Post;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

/**
 * The publish-push route: one text message per resource post, updates edit
 * that message in place through the stored post→message mapping, failures
 * back off through single-event retries (a 429's retry_after honoured,
 * three attempts total, the funnel hears every failure), and the
 * special-shaped errors resolve locally — an unchanged edit is success, a
 * gone message drops the dead binding. The wire is the suite's shared
 * wp_remote double: responses are staged, calls are recorded.
 */
final class TelegramPusherTest extends TestCase
{
    private Pusher $pusher;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_http'] = [];
        $GLOBALS['__aiya_test_http_response'] = null;
        unset($GLOBALS['__aiya_test_http_responder']);
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_cron'] = [];
        $GLOBALS['__aiya_test_filters'] = [];

        $this->pusher = new Pusher();
        $this->seedPost();
        $this->configure();
    }

    private function configure(array $overrides = []): void
    {
        $GLOBALS['__aiya_test_options']['telegram'] = array_merge([
            'tg_push_enabled' => true,
            'tg_push_chat_id' => '@channel',
            'tg_bot_token' => 'TOK',
            'tg_push_link_template' => '/resource/{slug}/',
        ], $overrides);
    }

    private function seedPost(array $overrides = []): void
    {
        $GLOBALS['__aiya_test_posts'][5] = new WP_Post((object) array_merge([
            'ID' => 5,
            'post_type' => 'resource',
            'post_status' => 'publish',
            'post_title' => 'Hello <b>World</b>',
            'post_name' => 'hello',
            'post_excerpt' => '',
            'post_content' => "<p>  first\n\nsecond </p>",
        ], $overrides));
    }

    private function seedMapping(): void
    {
        $GLOBALS['__aiya_test_post_meta'][5]['aiya_core_telegram'] = [
            'chat_id' => '@channel',
            'message_id' => 77,
            'pushed_at' => 100,
        ];
    }

    /** @param array<string, mixed> $body */
    private function stage(array $body, int $status = 200): void
    {
        $GLOBALS['__aiya_test_http_response'] = [
            'response' => ['code' => $status],
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- staged wire payload, raw JSON like the platform speaks
            'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return list<array{method: string, url: string, args: array<string, mixed>}> */
    private function calls(): array
    {
        return $GLOBALS['__aiya_test_http'];
    }

    /** @return array<string, mixed> */
    private function lastBody(): array
    {
        $calls = $this->calls();

        /** @var array<string, mixed> */
        return (array) json_decode((string) $calls[count($calls) - 1]['args']['body'], true);
    }

    /** Registers the funnel listener; appends into the caller's array. */
    private function captureErrors(array &$reported): void
    {
        add_action(TelegramBot::ERROR_ACTION, static function (string $route, string $code, string $message, array $context) use (&$reported): void {
            $reported[] = [$route, $code, $message, $context];
        }, 10, 4);
    }

    // ---- the gates ------------------------------------------------------------

    public function testAPublishSendsTheNoticeAndStoresTheMapping(): void
    {
        $this->stage(['ok' => true, 'result' => ['message_id' => 77]]);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        $calls = $this->calls();
        self::assertCount(1, $calls);
        self::assertStringEndsWith('/botTOK/sendMessage', $calls[0]['url']);

        $body = $this->lastBody();
        self::assertSame('@channel', $body['chat_id']);
        self::assertSame('HTML', $body['parse_mode']);
        self::assertSame(
            '<a href="https://aiya.test/resource/hello/">Hello World</a>' . "\n\n" . 'first second',
            $body['text'],
            'the title link rides the front-end domain + template, the content excerpt is stripped and collapsed'
        );

        $mapping = $GLOBALS['__aiya_test_post_meta'][5]['aiya_core_telegram'];
        self::assertSame('@channel', $mapping['chat_id']);
        self::assertSame(77, $mapping['message_id']);
        self::assertGreaterThan(0, $mapping['pushed_at']);
    }

    public function testANonResourcePostIsIgnored(): void
    {
        $this->seedPost(['post_type' => 'post']);
        $this->stage(['ok' => true, 'result' => ['message_id' => 1]]);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        self::assertSame([], $this->calls());
    }

    public function testTheRouteGatesOnItsSettings(): void
    {
        $this->stage(['ok' => true, 'result' => ['message_id' => 1]]);

        $this->configure(['tg_push_enabled' => false]);
        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);
        self::assertSame([], $this->calls(), 'the route switch gates the whole path');

        $this->configure(['tg_push_chat_id' => '']);
        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);
        self::assertSame([], $this->calls(), 'no target, no send');

        $this->configure(['tg_bot_token' => '']);
        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);
        self::assertSame([], $this->calls(), 'no token, no send');
    }

    // ---- the edit path ----------------------------------------------------------

    public function testAnUpdateEditsTheSameMessageInPlace(): void
    {
        $this->seedMapping();
        $this->stage(['ok' => true, 'result' => ['message_id' => 77]]);

        $this->pusher->onUpdated($GLOBALS['__aiya_test_posts'][5]);

        $calls = $this->calls();
        self::assertCount(1, $calls);
        self::assertStringEndsWith('/botTOK/editMessageText', $calls[0]['url']);
        self::assertSame(77, $this->lastBody()['message_id']);
        self::assertGreaterThanOrEqual(100, $GLOBALS['__aiya_test_post_meta'][5]['aiya_core_telegram']['pushed_at'], 'the binding stays, the stamp refreshes');
    }

    public function testAnUpdateWithoutAMappingStaysSilent(): void
    {
        $this->stage(['ok' => true, 'result' => ['message_id' => 1]]);

        $this->pusher->onUpdated($GLOBALS['__aiya_test_posts'][5]);

        self::assertSame([], $this->calls(), 'an update is not an announcement');
    }

    public function testARepublishEditsTheExistingMessage(): void
    {
        $this->seedMapping();
        $this->stage(['ok' => true, 'result' => ['message_id' => 77]]);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        self::assertStringEndsWith('/botTOK/editMessageText', $this->calls()[0]['url'], 'the funnel consults the mapping first');
    }

    public function testAnUnchangedEditIsSwallowedAsSuccess(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        $this->seedMapping();
        $this->stage(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified']);

        $this->pusher->onUpdated($GLOBALS['__aiya_test_posts'][5]);

        $calls = $this->calls();
        self::assertCount(1, $calls);
        self::assertStringEndsWith('/botTOK/editMessageText', $calls[0]['url'], 'the edit went out once');
        self::assertSame([], $reported, 'no change is not a failure');
        self::assertSame([], $GLOBALS['__aiya_test_cron'], 'and it schedules no retry');
        self::assertArrayHasKey('aiya_core_telegram', $GLOBALS['__aiya_test_post_meta'][5], 'the binding stays');
    }

    public function testAGoneMessageDropsTheDeadBinding(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        $this->seedMapping();
        $this->stage(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found']);

        $this->pusher->onUpdated($GLOBALS['__aiya_test_posts'][5]);

        self::assertArrayNotHasKey('aiya_core_telegram', $GLOBALS['__aiya_test_post_meta'][5], 'the dead binding is dropped');
        self::assertCount(1, $reported);
        self::assertSame('push', $reported[0][0]);
        self::assertSame('edit-gone', $reported[0][3]['phase']);
        self::assertSame([], $GLOBALS['__aiya_test_cron'], 'a dead binding does not retry');
    }

    // ---- the failure path --------------------------------------------------------

    public function testASendFailureReportsAndSchedulesTheBackedRetry(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        $this->stage(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        self::assertCount(1, $reported);
        self::assertSame('push', $reported[0][0]);
        self::assertSame(1, $reported[0][3]['attempt']);
        self::assertSame('send', $reported[0][3]['phase']);

        $events = $GLOBALS['__aiya_test_cron'];
        self::assertCount(1, $events);
        self::assertSame('aiya_core_tg_push_retry', $events[0]['hook']);
        self::assertSame([5, 2], $events[0]['args'], 'the next attempt is queued with its number');
        self::assertEqualsWithDelta(time() + 60, $events[0]['timestamp'], 3, 'the default backoff is one minute');
        self::assertArrayNotHasKey('aiya_core_telegram', $GLOBALS['__aiya_test_post_meta'][5] ?? [], 'a failed send stores no binding');
    }

    public function testAFloodControlDelaysTheRetryByRetryAfter(): void
    {
        $this->stage(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]]);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        $events = $GLOBALS['__aiya_test_cron'];
        self::assertCount(1, $events);
        self::assertEqualsWithDelta(time() + 9, $events[0]['timestamp'], 3, 'the platform\'s retry_after leads, plus a small buffer');
    }

    public function testTheThirdFailedAttemptIsTerminal(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        $this->stage(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']);

        $this->pusher->onRetry(5, 3);

        self::assertCount(1, $reported, 'the failure is heard once more');
        self::assertSame([], $GLOBALS['__aiya_test_cron'], 'three attempts and the schedule stays quiet');
    }

    // ---- the message shape --------------------------------------------------------

    public function testALongExcerptYieldsToTheCharacterBudget(): void
    {
        $this->seedPost(['post_excerpt' => '', 'post_content' => str_repeat('字', 1200)]);
        $this->stage(['ok' => true, 'result' => ['message_id' => 1]]);

        $this->pusher->onPublished($GLOBALS['__aiya_test_posts'][5]);

        $text = (string) $this->lastBody()['text'];
        self::assertLessThanOrEqual(4096, mb_strlen($text), 'the platform limit holds');
        self::assertStringStartsWith('<a href="https://aiya.test/resource/hello/">', $text, 'the link line survives untouched');
        self::assertStringEndsWith('…', $text, 'the cut excerpt carries the ellipsis');
    }
}
