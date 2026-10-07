<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\ChatStore;
use PHPUnit\Framework\TestCase;

/**
 * The support-relay storage: a visitor row lands in its account session,
 * the bot's owner-chat copy binds to it (the reply key), an owner reply
 * lands in the session the replied-to message belongs to, and the thread
 * page reads newest first through the wpdb double's real order/window
 * simulation.
 */
final class TelegramChatStoreTest extends TestCase
{
    private ChatStore $store;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_chat_messages'] = [];

        $this->store = new ChatStore();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testAVisitorPostLandsInItsAccountSession(): void
    {
        $row = $this->store->post(7, 'hello');

        self::assertSame('u7', $row['session_id'], 'v1: one conversation per account, derived server-side');
        self::assertSame(ChatStore::SENDER_VISITOR, (int) $row['sender']);
        self::assertSame('hello', $row['body']);
        self::assertNull($row['tg_chat_id'], 'an unbound row carries no Telegram pair yet');
        self::assertNull($row['tg_message_id']);
    }

    public function testTheOwnerCopyBindsToTheRow(): void
    {
        $row = $this->store->post(7, 'hello');
        $this->store->bindTelegram((int) $row['id'], 777, 500);

        global $wpdb;
        $bound = $wpdb->aiya_test_rows['wp_aiya_chat_messages'][0];
        self::assertSame(777, (int) $bound['tg_chat_id']);
        self::assertSame(500, (int) $bound['tg_message_id']);
    }

    public function testAnOwnerReplyLandsInTheRelayedSession(): void
    {
        $row = $this->store->post(7, 'hello');
        $this->store->bindTelegram((int) $row['id'], 777, 500);

        $reply = $this->store->storeOwnerReply(777, 500, 'answer');

        self::assertNotNull($reply);
        self::assertSame('u7', $reply['session_id'], 'the reply follows the replied-to message\'s session');
        self::assertSame(ChatStore::SENDER_STAFF, (int) $reply['sender']);
        self::assertSame('answer', $reply['body']);
        self::assertCount(2, $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_chat_messages'] : []);
    }

    public function testAReplyToNothingIsDropped(): void
    {
        self::assertNull($this->store->storeOwnerReply(777, 999, 'phantom'));
        self::assertSame([], $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_chat_messages'] : []);
    }

    public function testTheThreadPageReadsNewestFirstInWindows(): void
    {
        $this->store->post(7, 'one');
        $this->store->post(7, 'two');
        $this->store->post(7, 'three');
        $this->store->post(8, 'other session');

        self::assertSame(3, $this->store->countSession('u7'), 'sessions do not bleed');
        self::assertSame(1, $this->store->countSession('u8'));

        $first = $this->store->messages('u7', 2, 0);
        $second = $this->store->messages('u7', 2, 2);

        self::assertSame(['three', 'two'], array_column($first, 'body'), 'newest first');
        self::assertSame(['one'], array_column($second, 'body'), 'the window walks back');
    }
}
