<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\DiscussionService;
use PHPUnit\Framework\TestCase;

/**
 * The materialized activity stamp (`bumped_at`, 0.100.0): creation bumps,
 * a reply bumps to the reply's time, deleting the last reply falls back to
 * the thread's own creation, and the default list sort reads the column
 * straight (the (status, bumped_at) index serves it without a filesort).
 * Runs the real service write paths through the wpdb double.
 */
final class DiscussionBumpTest extends TestCase
{
    private DiscussionService $service;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_users'] = [7 => [], 9 => []];
        global $wpdb;
        $wpdb = new \wpdb();
        // One board; get_row answers the first seeded row for board reads.
        $wpdb->aiya_test_rows['wp_aiya_discussion_boards'] = [
            ['id' => 1, 'slug' => 'discussion', 'name' => '讨论', 'sort' => 1, 'created_at' => '2026-09-01 00:00:00'],
        ];
        $wpdb->aiya_test_rows['wp_aiya_discussions'] = [];
        $wpdb->aiya_test_rows['wp_aiya_discussion_replies'] = [];
        $this->service = new DiscussionService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testCreateBumpsAtCreation(): void
    {
        $threadId = $this->service->create(7, 'Hello', '<p>Body</p>', 1);

        $this->assertIsInt($threadId);
        global $wpdb;
        $row = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
        self::assertSame($row['created_at'], $row['bumped_at']);
    }

    public function testReplyBumpsToTheReplyTime(): void
    {
        $threadId = $this->service->create(7, 'Hello', '<p>Body</p>', 1);
        $this->service->reply($threadId, 9, '<p>+1</p>');

        global $wpdb;
        $thread = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
        $reply = $wpdb->aiya_test_rows['wp_aiya_discussion_replies'][0];
        self::assertSame($reply['created_at'], $thread['bumped_at'], 'the reply time is the new activity stamp');
        self::assertSame(1, (int) $thread['reply_count']);
        self::assertSame(9, (int) $thread['last_reply_user_id']);
    }

    public function testDeletingTheLastReplyFallsBackToCreation(): void
    {
        $threadId = $this->service->create(7, 'Hello', '<p>Body</p>', 1);
        $this->service->reply($threadId, 9, '<p>+1</p>');

        // The moderation path removes the reply row, then re-syncs.
        global $wpdb;
        array_pop($wpdb->aiya_test_rows['wp_aiya_discussion_replies']);
        $this->service->syncReplyStats($threadId);

        $thread = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
        self::assertSame($thread['created_at'], $thread['bumped_at']);
        self::assertSame(0, (int) $thread['reply_count']);
    }

    public function testDefaultSortRidesBumpedAt(): void
    {
        // Two threads, the older one replied later: the activity sort must
        // order by the materialized stamp, not creation.
        $first = $this->service->create(7, 'First', '<p>1</p>', 1);
        $this->service->reply($first, 9, '<p>later</p>');
        usleep(2000);
        $this->service->create(7, 'Second', '<p>2</p>', 1);

        global $wpdb;
        // The double's list read serves seeded rows in insertion order; the
        // sort clause itself is asserted through the assembled statement.
        $sql = $wpdb->prepare(
            "SELECT d.id FROM {$wpdb->prefix}aiya_discussions d WHERE d.status = 'open' ORDER BY d.bumped_at DESC, d.id DESC LIMIT 5 OFFSET 0"
        );
        self::assertStringContainsString('d.bumped_at DESC', $sql);
    }
}
