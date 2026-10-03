<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Domain\Shared\PublicType;
use WP_Post;
use WP_Term;

/**
 * Shared-term related-content ranking (the WPJAM Basic related-posts
 * algorithm, rebuilt headless): among published rows of the same public
 * type, the posts sharing the most term relationships with the origin
 * post rank first, newer ids win ties. Terms are collected from every
 * contract vocabulary the type carries (category + tag roles), so one
 * shared category and one shared tag both count toward the score.
 *
 * The ranking itself is one SQL pass — INNER JOIN on term_relationships
 * restricted to the origin's term taxonomy ids, GROUP BY the joined row
 * and ORDER BY the distinct shared-term count. The query rides
 * MarkedQuery's shared marker mechanics; the clause assembly below is
 * this query's own.
 *
 * Visibility mirrors ContentQuery's list reads: `publish` + no password
 * only, plus the login/member gate exclusions (viewer-relative, same
 * clause the lists use) — the response stays shared-cache safe. No
 * shared terms means no results — there is no recency fallback.
 */
final class RelatedPostsQuery
{
    public const DEFAULT_NUMBER = 5;
    public const MAX_NUMBER = MarkedQuery::MAX_NUMBER;
    public const MAX_DAYS = MarkedQuery::MAX_DAYS;

    public function __construct(private PostVisibility $visibility)
    {
    }

    /**
     * @param list<int> $excludeTermTaxonomyIds Opaque term exclusion
     * (0.96.0, NSFW): rows carrying any of these term taxonomy ids drop
     * out of the ranking — the same clause the lists inject, composed
     * after the shared-term join.
     * @return list<WP_Post>
     */
    public function forPost(WP_Post $post, PublicType $type, int $number, int $days, array $excludeTermTaxonomyIds = []): array
    {
        $ttIds = $this->termTaxonomyIds($post, $type);
        if ($ttIds === []) {
            return [];
        }

        return MarkedQuery::run(
            MarkedQuery::baseArgs($this->visibility, $type, $number, $days, [
                // Marker consumed by the clause filter below; unknown to
                // WP's own orderby parser, which is fine — the filter
                // replaces the clause outright.
                'orderby' => 'related',
                'term_taxonomy_ids' => $ttIds,
                'post__not_in' => [(int) $post->ID],
            ]),
            'term_taxonomy_ids',
            $ttIds,
            static fn (array $clauses): array => self::applyClauses($clauses, $ttIds),
            $excludeTermTaxonomyIds
        );
    }

    /**
     * Term taxonomy ids of every contract vocabulary the type carries,
     * deduplicated (a term shared across vocabularies counts once).
     *
     * @return list<int>
     */
    private function termTaxonomyIds(WP_Post $post, PublicType $type): array
    {
        $ids = [];
        foreach ($type->taxonomies as [$wpTaxonomy]) {
            $terms = get_the_terms($post, $wpTaxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if ($term instanceof WP_Term && (int) $term->term_taxonomy_id > 0) {
                    $ids[] = (int) $term->term_taxonomy_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Pure clause assembly for the shared-term ranking. `$ttIds` are
     * narrowed to positive ints here regardless of what arrived — the
     * IN list is interpolated by design (prepare() cannot build one).
     *
     * @param array<string, string> $clauses
     * @param list<mixed> $ttIds
     * @return array<string, string>
     */
    public static function applyClauses(array $clauses, array $ttIds): array
    {
        global $wpdb;

        $ids = [];
        foreach ($ttIds as $id) {
            $int = is_scalar($id) ? (int) $id : 0;
            if ($int > 0) {
                $ids[] = $int;
            }
        }
        if ($ids === []) {
            return $clauses;
        }

        // The unit suite runs without a wpdb global; fall back to the
        // default prefix so the clause assembly stays testable.
        $table = $wpdb instanceof \wpdb ? (string) $wpdb->posts : 'wp_posts';
        $relations = $wpdb instanceof \wpdb ? (string) $wpdb->term_relationships : 'wp_term_relationships';

        $clauses['join'] .= " INNER JOIN {$relations} AS aiya_tr ON ({$table}.ID = aiya_tr.object_id)";
        $clauses['where'] .= ' AND aiya_tr.term_taxonomy_id IN (' . implode(',', $ids) . ')';
        $groupby = trim((string) ($clauses['groupby'] ?? ''));
        $clauses['groupby'] = ($groupby !== '' ? $groupby . ', ' : '') . 'aiya_tr.object_id';
        // Distinct tt ids, not rows: sibling filters' joins (WP's OR meta
        // queries LEFT JOIN postmeta without an ON filter) multiply the
        // row count per post, and the relationship table's primary key
        // guarantees one row per shared term — so only a DISTINCT count
        // measures "shared terms" and nothing else.
        $clauses['orderby'] = " count(DISTINCT aiya_tr.term_taxonomy_id) DESC, {$table}.ID DESC";

        return $clauses;
    }
}
