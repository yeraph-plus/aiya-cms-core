<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Domain\Shared\PublicType;
use WP_Post;
use WP_Query;

/**
 * The mechanics both marker-ranked queries share (HotPostsQuery,
 * RelatedPostsQuery): one WP_Query whose ranking clauses are installed
 * by a posts_clauses filter scoped to the marked query — a marker
 * orderby unknown to WP's own parser plus an exact payload match, so
 * nested WP_Query calls inside the window stay untouched — over base
 * args that mirror the lists (publish-only, no password, the
 * viewer-relative visibility gate, an optional publish-date window).
 * The ranking clauses themselves differ per query and stay with them.
 */
final class MarkedQuery
{
    public const MAX_NUMBER = 20;
    public const MAX_DAYS = 365;

    /**
     * The base args, plus the visibility gate and the optional date
     * window. `$extra` merges last: the marker orderby, its payload key
     * and any caller-specific arg (post__not_in and friends).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function baseArgs(PostVisibility $visibility, PublicType $type, int $number, int $days, array $extra = []): array
    {
        $args = array_merge([
            'post_type' => $type->postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => min(self::MAX_NUMBER, max(1, $number)),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ], $extra);

        $gateClause = $visibility->listExclusions();
        if ($gateClause !== []) {
            $args['meta_query'] = $gateClause;
        }
        if ($days > 0) {
            $args['date_query'] = [
                [
                    'column' => 'post_date_gmt',
                    'after' => gmdate('Y-m-d', time() - min($days, self::MAX_DAYS) * DAY_IN_SECONDS) . ' 00:00:00',
                ],
            ];
        }

        return $args;
    }

    /**
     * Installs $apply behind the scoped posts_clauses filter, runs the
     * query, removes the filter and returns the WP_Post rows.
     *
     * @param array<string, mixed> $args the marked query's args (orderby marker + payload included)
     * @param \Closure(array<string, string>): array<string, string> $apply ranking-clause assembly for the marked query
     * @param list<int> $excludeTermTaxonomyIds NSFW rows to drop (the same clause the lists inject)
     * @return list<WP_Post>
     */
    public static function run(array $args, string $payloadKey, mixed $payload, \Closure $apply, array $excludeTermTaxonomyIds = []): array
    {
        $marker = (string) ($args['orderby'] ?? '');
        $filter = static function (array $clauses, WP_Query $query) use ($marker, $payloadKey, $payload, $apply, $excludeTermTaxonomyIds): array {
            if ($query->get('orderby') === $marker && $query->get($payloadKey) === $payload) {
                $clauses = $apply($clauses);
                if ($excludeTermTaxonomyIds !== []) {
                    $clauses = ContentQuery::applyTermExclusion($clauses, $excludeTermTaxonomyIds);
                }
            }

            return $clauses;
        };
        add_filter('posts_clauses', $filter, 10, 2);
        $query = new WP_Query($args);
        remove_filter('posts_clauses', $filter, 10);

        $rows = [];
        foreach (is_array($query->posts) ? $query->posts : [] as $row) {
            if ($row instanceof WP_Post) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
