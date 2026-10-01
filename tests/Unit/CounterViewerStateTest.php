<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Engagement\CounterService;
use PHPUnit\Framework\TestCase;

/**
 * The read side of the like/rating dedupe entries: the transients the
 * write path records double as the visitor's own state, feeding the
 * detail projection's viewerLiked/viewerRating (0.100.0). The rating
 * entry stores the vote value, not a flag.
 */
final class CounterViewerStateTest extends TestCase
{
    private CounterService $counters;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        global $wpdb;
        $wpdb = new \wpdb();
        $this->counters = new CounterService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testHasLikeFollowsTheDedupeEntry(): void
    {
        self::assertFalse($this->counters->hasLike(274, 'u5'));

        set_transient('aiya_core_like_' . md5('274|u5'), 1, 60);

        self::assertTrue($this->counters->hasLike(274, 'u5'));
        self::assertFalse($this->counters->hasLike(274, 'u9'), "another visitor's entry never leaks");
    }

    public function testRatingVoteAnswersTheStoredValueOrNull(): void
    {
        self::assertNull($this->counters->ratingVote(253, 'u5'));

        set_transient('aiya_core_rating_' . md5('253|u5'), 8, 60);

        self::assertSame(8, $this->counters->ratingVote(253, 'u5'));
        self::assertNull($this->counters->ratingVote(253, 'u9'));
    }

    public function testRegisterRatingStoresTheVoteValueForTheReadSide(): void
    {
        $post = new \WP_Post((object) [
            'ID' => 253,
            'post_type' => 'resource',
            'post_status' => 'publish',
        ]);
        $GLOBALS['__aiya_test_posts'][253] = $post;

        $result = $this->counters->registerRating(253, 8, 'u5');

        $this->assertIsArray($result);
        self::assertFalse($result['already']);
        self::assertSame(8, $this->counters->ratingVote(253, 'u5'));

        // A second vote is deduplicated and never overwrites the stored value.
        $again = $this->counters->registerRating(253, 2, 'u5');
        $this->assertIsArray($again);
        self::assertTrue($again['already']);
        self::assertSame(8, $this->counters->ratingVote(253, 'u5'));
    }
}
