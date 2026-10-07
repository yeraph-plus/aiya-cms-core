<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\ChatStore;
use Aiya\Core\Domain\Telegram\Relay;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

/**
 * The relay orchestration: a visitor message stores on the web first (the
 * source of truth), then lands in the owner chat carrying the sender's
 * identity, and the stored copy's binding is what routes the owner's
 * reply back. Unconfigured bots keep rows web-only, failed deliveries
 * report through the funnel unbound, and the owner side speaks replies
 * only.
 */
final class TelegramRelayTest extends TestCase
{
    private Relay $relay;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_http'] = [];
        $GLOBALS['__aiya_test_http_response'] = null;
        unset($GLOBALS['__aiya_test_http_responder']);
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [7 => ['display_name' => 'Alice']];
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_chat_messages'] = [];

        $this->relay = new Relay(new ChatStore());
        $this->configure();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function configure(array $overrides = []): void
    {
        $GLOBALS['__aiya_test_options']['telegram'] = array_merge([
            'tg_relay_enabled' => true,
            'tg_relay_owner_chat_id' => '777',
            'tg_bot_token' => 'TOK',
        ], $overrides);
    }

    private function captureErrors(array &$reported): void
    {
        add_action(\Aiya\Core\Domain\Telegram\TelegramBot::ERROR_ACTION, static function (string $route, string $code, string $message, array $context) use (&$reported): void {
            $reported[] = [$route, $code, $message, $context];
        }, 10, 4);
    }

    public function testAVisitorMessageStoresThenDeliversWithIdentity(): void
    {
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":500}}'];

        $row = $this->relay->submitVisitorMessage(7, 'hello there');

        self::assertSame('hello there', $row['body'], 'the web row is the source of truth');
        $call = $GLOBALS['__aiya_test_http'][0];
        self::assertStringEndsWith('/botTOK/sendMessage', $call['url']);
        $body = (array) json_decode((string) $call['args']['body'], true);
        self::assertSame(777, $body['chat_id']);
        self::assertSame("From Alice (#7):\nhello there", $body['text'], 'the owner sees who is talking');
        global $wpdb;
        self::assertSame(500, (int) ($wpdb->aiya_test_rows['wp_aiya_chat_messages'][0]['tg_message_id'] ?? 0), 'the copy binds back to the stored row');
    }

    public function testAnUnconfiguredBotKeepsTheRowWebOnly(): void
    {
        $this->configure(['tg_bot_token' => '']);

        $row = $this->relay->submitVisitorMessage(7, 'hello');

        self::assertSame([], $GLOBALS['__aiya_test_http'], 'nothing goes out');
        self::assertSame('hello', $row['body'], 'the web row stands regardless');
        self::assertNull($row['tg_message_id']);
    }

    public function testADeliveryFailureReportsAndLeavesTheRowUnbound(): void
    {
        $reported = [];
        $this->captureErrors($reported);
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 400], 'body' => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}'];

        $row = $this->relay->submitVisitorMessage(7, 'hello');

        self::assertCount(1, $reported);
        self::assertSame('relay', $reported[0][0]);
        self::assertSame('hello', $row['body'], 'the web row stands');
        self::assertNull($row['tg_message_id'] ?? null, 'a failed delivery binds nothing');
    }

    public function testAnOwnerReplyLandsInTheRelayedSession(): void
    {
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":500}}'];
        $this->relay->submitVisitorMessage(7, 'hello');

        $this->relay->onOwnerMessage(777, [
            'message_id' => 501,
            'chat' => ['id' => 777, 'type' => 'private'],
            'reply_to_message' => ['message_id' => 500],
            'text' => 'on it',
        ]);

        global $wpdb;
        $rows = $wpdb->aiya_test_rows['wp_aiya_chat_messages'];
        self::assertCount(2, $rows);
        self::assertSame(ChatStore::SENDER_STAFF, (int) $rows[1]['sender']);
        self::assertSame('u7', $rows[1]['session_id'], 'the reply follows the relayed message\'s session');
        self::assertSame('on it', $rows[1]['body']);
    }

    public function testABareOwnerMessageIsNotRoutable(): void
    {
        $this->relay->submitVisitorMessage(7, 'hello');

        $this->relay->onOwnerMessage(777, [
            'message_id' => 502,
            'chat' => ['id' => 777, 'type' => 'private'],
            'text' => 'not a reply',
        ]);

        global $wpdb;
        self::assertCount(1, $wpdb->aiya_test_rows['wp_aiya_chat_messages'], 'a bare message has no destination');
    }

    public function testAMediaOnlyReplyIsNotRelayed(): void
    {
        $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":500}}'];
        $this->relay->submitVisitorMessage(7, 'hello');

        $this->relay->onOwnerMessage(777, [
            'message_id' => 503,
            'chat' => ['id' => 777, 'type' => 'private'],
            'reply_to_message' => ['message_id' => 500],
            'sticker' => ['file_id' => 'STK'],
        ]);

        global $wpdb;
        self::assertCount(1, $wpdb->aiya_test_rows['wp_aiya_chat_messages'], 'media replies are a later iteration');
    }
}
