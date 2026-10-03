<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\DiscussionLikeService;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The community like relation over the wpdb double: the unique actor key
 * makes repeat likes the counted-once no-op, unlike floors the count at
 * zero, closed threads refuse new likes but never lock an unlike, and the
 * batch reads (counts / likedBy) key by thread id for the presenter.
 */
final class DiscussionLikeTest extends TestCase
{
    private DiscussionLikeService $likes;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_discussions'] = [
            ['id' => 1, 'status' => 'open', 'like_count' => 0],
            ['id' => 2, 'status' => 'closed', 'like_count' => 0],
        ];
        $this->likes = new DiscussionLikeService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testLikeCountsOncePerActor(): void
    {
        $first = $this->likes->like(1, 7);
        self::assertNotInstanceOf(WP_Error::class, $first);
        self::assertFalse($first['already']);
        self::assertSame(1, $first['likes']);

        $repeat = $this->likes->like(1, 7);
        self::assertTrue($repeat['already']);
        self::assertSame(1, $repeat['likes'], 'the actor key keeps the count at one');

        $second = $this->likes->like(1, 9);
        self::assertFalse($second['already']);
        self::assertSame(2, $second['likes']);
    }

    public function testUnlikeRemovesAndFloorsAtZero(): void
    {
        $this->likes->like(1, 7);

        $removed = $this->likes->unlike(1, 7);
        self::assertFalse($removed['viewerLiked']);
        self::assertSame(0, $removed['likes']);

        $absent = $this->likes->unlike(1, 7);
        self::assertSame(0, $absent['likes'], 'unliking an absent row is a no-op');
    }

    public function testClosedThreadsRefuseLikesButNotUnlikes(): void
    {
        $refused = $this->likes->like(2, 7);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_thread_closed', $refused->get_error_code());

        global $wpdb;
        $wpdb->aiya_test_rows['wp_aiya_discussion_likes'] = [
            ['id' => 1, 'thread_id' => 2, 'user_id' => 7, 'created_at' => '2026-10-03 00:00:00'],
        ];

        $removed = $this->likes->unlike(2, 7);
        self::assertNotInstanceOf(WP_Error::class, $removed);
        self::assertSame(0, $removed['likes'], 'a reader can always correct their own state');
    }

    public function testMissingThreadsAnswerNotFound(): void
    {
        self::assertSame('aiya_not_found', $this->likes->like(99, 7)->get_error_code());
        self::assertSame('aiya_not_found', $this->likes->unlike(99, 7)->get_error_code());
    }

    public function testBatchReadsKeyByThreadId(): void
    {
        global $wpdb;
        $wpdb->aiya_test_rows['wp_aiya_discussion_likes'] = [
            ['id' => 1, 'thread_id' => 1, 'user_id' => 7, 'created_at' => '2026-10-03 00:00:00'],
            ['id' => 2, 'thread_id' => 2, 'user_id' => 7, 'created_at' => '2026-10-03 00:00:00'],
        ];
        $wpdb->aiya_test_rows['wp_aiya_discussions'][0]['like_count'] = 3;

        self::assertSame([1 => 3, 2 => 0], $this->likes->counts([1, 2]));
        self::assertSame([2 => 0], $this->likes->counts([2, 99]), 'ids without a row are simply missing from the map');

        self::assertSame([1 => true, 2 => true], $this->likes->likedBy(7, [1, 2]));
        self::assertSame([], $this->likes->likedBy(9, [1, 2]));
        self::assertTrue($this->likes->has(1, 7));
        self::assertFalse($this->likes->has(1, 9));
        self::assertFalse($this->likes->has(1, 0), 'guests never carry viewer state');
    }
}
