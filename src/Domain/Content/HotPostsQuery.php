<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Domain\Shared\PublicType;
use WP_Post;

/**
 * The popularity leaderboard: publish-window content ranked by an
 * engagement score assembled from the per-type counters. Like and rating
 * live on disjoint type surfaces by design (posts/pages are liked,
 * resources are rated, views ride everything), so the score is built from
 * the metrics a type actually carries:
 *
 *   post/page: like x3 + view
 *   resource:  view + rating_average x min(rating_count, 20) x2
 *
 * The rating term folds the vote count in capped, so a single 10-point
 * vote cannot outrank a fifty-vote 9 — the running-average meta alone
 * would let it. Weights ride the `aiya_core_hot_weights` filter; the
 * window is the publish date (the counters carry no interaction
 * timestamps, so a true "hot this week" needs the snapshot instrumentation
 * that was deliberately not built — the publish window is the honest
 * simple form).
 *
 * The query rides MarkedQuery's shared marker mechanics; the score
 * expression below is this query's own.
 */
final class HotPostsQuery
{
    public const DEFAULT_NUMBER = 10;
    public const MAX_NUMBER = MarkedQuery::MAX_NUMBER;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = MarkedQuery::MAX_DAYS;

    /** The metric set a type's score is built from. @var array<string, array{like: bool, view: bool, rating: bool}> */
    private const METRICS = [
        'post' => ['like' => true, 'view' => true, 'rating' => false],
        'page' => ['like' => true, 'view' => true, 'rating' => false],
        'resource' => ['like' => false, 'view' => true, 'rating' => true],
    ];

    public function __construct(private PostVisibility $visibility)
    {
    }

    /**
     * @param list<int> $excludeTermTaxonomyIds NSFW rows to drop (the same
     *                                          clause the lists inject)
     * @return list<WP_Post>
     */
    public function query(PublicType $type, int $number, int $days, array $excludeTermTaxonomyIds = []): array
    {
        $metrics = self::METRICS[$type->name] ?? null;
        if ($metrics === null) {
            return [];
        }

        return MarkedQuery::run(
            MarkedQuery::baseArgs($this->visibility, $type, $number, $days, [
                // Marker consumed by the clause filter below; unknown to WP's
                // own orderby parser, which is fine — the filter replaces the
                // clause outright.
                'orderby' => 'hot',
                'hot_metrics' => $metrics,
            ]),
            'hot_metrics',
            $metrics,
            static function (array $clauses) use ($metrics): array {
                $clauses['join'] .= self::joinClause();
                $clauses['orderby'] = self::orderByClause($metrics);

                return $clauses;
            },
            $excludeTermTaxonomyIds
        );
    }

    /** The counter meta JOINs; aliases must stay in sync with the score expression. */
    private static function joinClause(): string
    {
        global $wpdb;

        // The unit suite runs without a wpdb global; fall back to the
        // default prefix so the clause assembly stays testable.
        $postmeta = $wpdb instanceof \wpdb ? (string) $wpdb->postmeta : 'wp_postmeta';
        $posts = $wpdb instanceof \wpdb ? (string) $wpdb->posts : 'wp_posts';

        $join = static fn (string $alias, string $key): string
            => " LEFT JOIN {$postmeta} AS {$alias} ON ({$alias}.post_id = {$posts}.ID AND {$alias}.meta_key = '{$key}')";

        return $join('hot_likes', 'like_count')
            . $join('hot_views', 'view_count')
            . $join('hot_rating', 'rating_score')
            . $join('hot_rating_count', 'rating_count');
    }

    /**
     * The weighted score, per the metrics the type carries. CAST keeps the
     * varchar meta_value arithmetic numeric; COALESCE turns missing
     * counters into zero so cold posts sort by the date tiebreak.
     *
     * @param array{like: bool, view: bool, rating: bool} $metrics
     */
    public static function orderByClause(array $metrics): string
    {
        global $wpdb;

        $table = $wpdb instanceof \wpdb ? (string) $wpdb->posts : 'wp_posts';

        $weights = apply_filters('aiya_core_hot_weights', ['like' => 3, 'view' => 1, 'rating' => 2, 'rating_cap' => 20]);
        $weights = is_array($weights) ? $weights : [];
        $like = max(0, (int) ($weights['like'] ?? 3));
        $view = max(0, (int) ($weights['view'] ?? 1));
        $rating = max(0, (int) ($weights['rating'] ?? 2));
        $cap = max(1, (int) ($weights['rating_cap'] ?? 20));

        $terms = [];
        if ($metrics['like']) {
            $terms[] = "COALESCE(CAST(hot_likes.meta_value AS UNSIGNED), 0) * {$like}";
        }
        if ($metrics['view']) {
            $terms[] = "COALESCE(CAST(hot_views.meta_value AS UNSIGNED), 0) * {$view}";
        }
        if ($metrics['rating']) {
            $terms[] = "COALESCE(CAST(hot_rating.meta_value AS UNSIGNED), 0) * LEAST(COALESCE(CAST(hot_rating_count.meta_value AS UNSIGNED), 0), {$cap}) * {$rating}";
        }
        if ($terms === []) {
            $terms[] = '0';
        }

        return implode(' + ', $terms) . " DESC, {$table}.post_date_gmt DESC, {$table}.ID DESC";
    }
}
