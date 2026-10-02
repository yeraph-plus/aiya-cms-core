<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\HotPostsQuery;
use PHPUnit\Framework\TestCase;

/**
 * The leaderboard's score expression, pure: the type's metric set decides
 * which counter JOIN aliases appear, the rating term folds the vote count
 * in capped (one 10-point vote must not outrank a fifty-vote 9), weights
 * ride the filter seam, and the tiebreak is the shared date/id order.
 */
final class HotPostsQueryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_filters'] = [];
    }

    public function testPostScoreBlendsLikesAndViews(): void
    {
        $clause = HotPostsQuery::orderByClause(['like' => true, 'view' => true, 'rating' => false]);

        self::assertStringContainsString('hot_likes.meta_value', $clause);
        self::assertStringContainsString('hot_views.meta_value', $clause);
        self::assertStringNotContainsString('hot_rating', $clause);
        self::assertStringContainsString('post_date_gmt DESC', $clause, 'the date tiebreak mirrors the lists');
    }

    public function testResourceScoreFoldsTheRatingCountCap(): void
    {
        $clause = HotPostsQuery::orderByClause(['like' => false, 'view' => true, 'rating' => true]);

        self::assertStringContainsString('LEAST(COALESCE(CAST(hot_rating_count.meta_value AS UNSIGNED), 0), 20)', $clause);
        self::assertStringContainsString('hot_rating.meta_value', $clause);
        self::assertStringNotContainsString('hot_likes', $clause);
    }

    public function testEmptyMetricSetDegradesToTheDateTiebreak(): void
    {
        self::assertSame(
            '0 DESC, wp_posts.post_date_gmt DESC, wp_posts.ID DESC',
            HotPostsQuery::orderByClause(['like' => false, 'view' => false, 'rating' => false])
        );
    }

    public function testWeightsRideTheFilterSeam(): void
    {
        add_filter('aiya_core_hot_weights', static fn (): array => ['like' => 10, 'view' => 2, 'rating' => 5, 'rating_cap' => 7]);

        $clause = HotPostsQuery::orderByClause(['like' => true, 'view' => true, 'rating' => true]);

        self::assertStringContainsString(') * 10', $clause);
        self::assertStringContainsString(') * 2', $clause);
        self::assertStringContainsString(', 7)', $clause);
        self::assertStringContainsString(') * 5', $clause);
    }
}
