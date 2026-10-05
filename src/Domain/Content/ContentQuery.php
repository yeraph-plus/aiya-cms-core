<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Domain\Shared\PublicType;
use Aiya\Core\Domain\Shared\PublicTypes;
use WP_Post;
use WP_Query;

/**
 * Read-side queries over public content for the headless API. Wraps
 * WP_Query presets parameterized by PublicType and returns raw WP_Post
 * rows plus totals — mapping into contract DTOs belongs to the presenter
 * layer.
 *
 * Visibility rules (0.46.0 badge batch):
 *  - `publish` is the public baseline; password-protected posts stay out
 *    of lists entirely (`has_password => false`) — their detail answers
 *    the locked shape until the visitor unlocks the post through the
 *    content/unlock endpoint;
 *  - `private` posts join queries only for a viewer who may read them
 *    (their own posts, or `read_private_posts` for editors/admins) and
 *    carry the `private` badge — anonymous traffic never sees them, so
 *    shared caches stay safe;
 *  - login/member gated posts (0.71.0, PostVisibility meta flag) stay
 *    `publish` but drop out of lists for viewers who do not qualify —
 *    guests lose both gates, logged-in non-members lose member-only rows;
 *  - sticky posts lead page one of every date-ordered list: the surviving
 *    stickies are promoted, removed from their natural slot on every page
 *    and the row window shifts so pages neither duplicate rows nor lose
 *    them — never WP's automatic prepend, which would overflow a page
 *    one past its configured size;
 *  - an opaque term exclusion (0.96.0, NSFW): callers hand in term
 *    taxonomy ids and rows carrying any of them drop out — the query
 *    model knows nothing about why (the decision lives in NsfwFilter at
 *    the API boundary); the clause rides posts_clauses like the related
 *    ranking, attached and detached around one query.
 */
final class ContentQuery
{
    /** Query var marking the rows of a query that carries the exclusion. */
    private const EXCLUDE_VAR = 'aiya_exclude_ttids';

    public function __construct(
        private PostVisibility $visibility,
    ) {
    }

    /** Whether the current viewer may read private posts of this type at all. */
    private function canReadPrivate(): bool
    {
        return is_user_logged_in() && current_user_can('read_private_posts');
    }

    /** Private rows of this type the current viewer authored. */
    private function ownPrivateQuery(): bool
    {
        return is_user_logged_in() && !current_user_can('read_private_posts');
    }

    /**
     * Paginated list of one type. Query parameters arrive already
     * validated by the REST layer; unknown category slugs simply match
     * nothing (an empty list, not an error). `$excludeTermTaxonomyIds`
     * is the opaque term exclusion (NSFW): rows carrying any of those
     * term taxonomy ids drop out of the result and the totals.
     *
     * @param list<int> $excludeTermTaxonomyIds
     * @return array{items: list<WP_Post>, total: int}
     */
    public function list(PublicType $type, int $page, int $perPage, string $q, string $category, string $sort, string $author = '', string $tag = '', array $excludeTermTaxonomyIds = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $statuses = ['publish'];

        if ($this->canReadPrivate()) {
            $statuses[] = 'private';
        } elseif ($this->ownPrivateQuery()) {
            // Logged-in without the capability: 'perm' => 'readable' makes
            // WP's status SQL limit 'private' rows to the viewer's own
            // (without 'perm' core applies NO author restriction — every
            // subscriber would see everyone's private posts). Public rows
            // stay listed.
            $statuses[] = 'private';
        }

        $args = [
            'post_type' => $type->postTypes,
            'post_status' => $statuses,
            'has_password' => false,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => $sort === 'relevance' && $q !== '' ? 'relevance' : ($sort === 'rand' ? 'rand' : 'date'),
            'order' => $sort === 'oldest' ? 'ASC' : 'DESC',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ];
        if (count($statuses) > 1) {
            // Only matters when private rows joined the set; with the cap
            // it returns ALL private rows, without it own-private only.
            $args['perm'] = 'readable';
        }
        $gateClause = $this->visibility->listExclusions();
        if ($gateClause !== []) {
            $args['meta_query'] = $gateClause;
        }

        if ($q !== '') {
            $args['s'] = self::searchTerms($q);
        }
        if ($author !== '') {
            // author_name keys on user_nicename — the public profile slug.
            $args['author_name'] = $author;
        }
        $taxQuery = [];
        if ($category !== '') {
            $categoryTaxonomy = $type->wpCategoryTaxonomy('category');
            $categorySlugs = $this->slugList($category);
            if ($categoryTaxonomy !== null && $categorySlugs !== []) {
                // Comma-separated multi-select: any chosen category counts.
                $taxQuery[] = [
                    'taxonomy' => $categoryTaxonomy,
                    'field' => 'slug',
                    'terms' => $categorySlugs,
                    'operator' => 'IN',
                ];
            }
        }
        if ($tag !== '') {
            $tagSlugs = $this->slugList($tag);
            // A type may carry several tag vocabularies (resource maps five
            // onto the contract's tag role): any chosen tag in any vocabulary
            // counts, while the category leg above keeps narrowing with the
            // default AND.
            $tagLegs = [];
            foreach ($type->wpTagTaxonomies() as $tagTaxonomy) {
                $tagLegs[] = [
                    'taxonomy' => $tagTaxonomy,
                    'field' => 'slug',
                    'terms' => $tagSlugs,
                    'operator' => 'IN',
                ];
            }
            if ($tagLegs !== [] && $tagSlugs !== []) {
                $taxQuery[] = count($tagLegs) === 1
                    ? $tagLegs[0]
                    : array_merge(['relation' => 'OR'], $tagLegs);
            }
        }
        if ($taxQuery !== []) {
            // Multiple legs (category + tag) narrow with the default AND relation.
            $args['tax_query'] = $taxQuery;
        }

        // Sticky promotion: page one of a date-ordered list leads with the
        // stickies that survive the active filters (category, tags, gates,
        // the term exclusion — the probe runs the full WHERE). Random order
        // and relevance search have no "front", so the promotion only
        // applies to date sorts. The 0.96.0 fix: the promotion used to
        // float only stickies already inside the fetched page, so a sticky
        // older than the newest perPage rows never surfaced at all. The
        // surviving ids resolve on EVERY page of the read — later pages
        // need them too, to keep the stickies out of their natural slots.
        $stickyIds = [];
        if (!in_array($sort, ['rand', 'relevance'], true)) {
            $stickyIds = $this->stickyIdsFor($type);
            if ($stickyIds !== []) {
                $stickyIds = $this->survivingStickyIds($args, $stickyIds, $excludeTermTaxonomyIds);
            }
            if (count($stickyIds) > $perPage) {
                // More stickies than a page: the first perPage lead, the
                // rest stay invisible to this window (a pathological,
                // admin-created state — the page contract wins).
                $stickyIds = array_slice($stickyIds, 0, $perPage);
            }
        }
        if ($stickyIds !== []) {
            // Stickies lead page one and never occupy a natural slot: the
            // row window shifts left by the sticky count, so page two
            // resumes exactly where page one's rows ended — no duplicates,
            // no dropped rows, and found_rows stays a plain count of the
            // non-sticky set (the stickies add back on top).
            $args['post__not_in'] = $stickyIds;
            $args['offset'] = max(0, ($page - 1) * $perPage - count($stickyIds));
            unset($args['paged']);
            if ($page === 1) {
                // Page one's row quota shrinks by the promoted stickies.
                // Zero or negative (at least as many stickies as the page
                // holds): stickies fill the page alone, the query runs
                // count-only.
                $rowsWanted = $perPage - count($stickyIds);
                if ($rowsWanted > 0) {
                    $args['posts_per_page'] = $rowsWanted;
                } else {
                    $args['posts_per_page'] = 1;
                    $args['fields'] = 'ids';
                }
            }
        }

        $query = $this->runQuery($args, $excludeTermTaxonomyIds);
        $items = [];
        foreach (is_array($query->posts) ? $query->posts : [] as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $post;
            }
        }

        if ($stickyIds !== []) {
            // Page one prepends the promoted stickies (page size stays
            // exact: the rows were limited to perPage minus stickies), in
            // the block order the list itself runs.
            if ($page === 1) {
                $items = [...$this->stickyPosts($stickyIds, $sort === 'oldest' ? 'ASC' : 'DESC'), ...$items];
            }
            $total = (int) $query->found_posts + count($stickyIds);
        } else {
            $total = (int) $query->found_posts;
        }

        // Since WP 6.3 the found-rows lookup is skipped when a page returns
        // no rows, so an out-of-range page would report 0. The contract
        // keeps real statistics — count once from the top of the window.
        if ($items === [] && $page > 1) {
            $recount = array_merge($args, ['posts_per_page' => 1, 'offset' => 0, 'fields' => 'ids']);
            $count = $this->runQuery($recount, $excludeTermTaxonomyIds);
            $total = (int) $count->found_posts + count($stickyIds);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Runs one WP_Query, attaching the term exclusion to its rows when
     * term taxonomy ids were handed in. The clause filter carries the
     * exact id list so nested queries inside the window (the sticky
     * probe) take it too, and only marked queries do.
     *
     * @param array<string, mixed> $args
     * @param list<int> $excludeTermTaxonomyIds
     */
    private function runQuery(array $args, array $excludeTermTaxonomyIds): WP_Query
    {
        $filter = null;
        if ($excludeTermTaxonomyIds !== []) {
            $args[self::EXCLUDE_VAR] = array_values($excludeTermTaxonomyIds);
            $marked = $args[self::EXCLUDE_VAR];
            $filter = static function (array $clauses, WP_Query $query) use ($marked): array {
                if ($query->get(self::EXCLUDE_VAR) === $marked) {
                    $clauses = self::applyTermExclusion($clauses, $marked);
                }

                return $clauses;
            };
            add_filter('posts_clauses', $filter, 10, 2);
        }

        $query = new WP_Query($args);

        if ($filter !== null) {
            remove_filter('posts_clauses', $filter, 10);
        }

        return $query;
    }

    /**
     * Pure clause assembly for the term exclusion: rows carrying ANY of
     * the term taxonomy ids drop out (the native NOT IN semantics — one
     * subquery over the relationships table, no tax_query NOT legs, no
     * join). `$ttIds` are narrowed to positive ints here regardless of
     * what arrived; the IN list is interpolated by design (prepare()
     * cannot build one).
     *
     * @param array<string, string> $clauses
     * @param list<mixed> $ttIds
     * @return array<string, string>
     */
    public static function applyTermExclusion(array $clauses, array $ttIds): array
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

        $clauses['where'] .= " AND {$table}.ID NOT IN (SELECT aiya_ex.object_id FROM {$relations} AS aiya_ex WHERE aiya_ex.term_taxonomy_id IN (" . implode(',', $ids) . '))';

        return $clauses;
    }

    /** Sticky post ids of this type that still exist as published content.
     *
     * @return list<int>
     */
    private function stickyIdsFor(PublicType $type): array
    {
        $sticky = get_option('sticky_posts');
        if (!is_array($sticky) || $sticky === []) {
            return [];
        }

        $ids = [];
        foreach ($sticky as $id) {
            $post = get_post((int) $id);
            if ($post instanceof WP_Post
                && in_array($post->post_type, $type->postTypes, true)
                && $post->post_status === 'publish'
                && (string) $post->post_password === '') {
                $ids[] = (int) $post->ID;
            }
        }

        return $ids;
    }

    /**
     * Narrows the sticky ids to the ones that survive the list's active
     * filters: one bounded probe query running the exact WHERE the main
     * list runs (gates, tax legs, search, the term exclusion) restricted
     * to the sticky ids.
     *
     * @param array<string, mixed> $args
     * @param list<int> $stickyIds
     * @param list<int> $excludeTermTaxonomyIds
     * @return list<int>
     */
    private function survivingStickyIds(array $args, array $stickyIds, array $excludeTermTaxonomyIds): array
    {
        $probe = array_merge($args, [
            'post__in' => $stickyIds,
            'posts_per_page' => count($stickyIds),
            'no_found_rows' => true,
            'fields' => 'ids',
            'orderby' => 'post__in',
            'ignore_sticky_posts' => true,
        ]);
        unset($probe['paged'], $probe['offset']);

        $query = $this->runQuery($probe, $excludeTermTaxonomyIds);
        $rows = is_array($query->posts) ? $query->posts : [];
        $surviving = [];
        foreach ($rows as $row) {
            // fields=ids answers ints; a filter that swapped the fields
            // back to objects still narrows correctly to its id.
            $id = $row instanceof WP_Post ? (int) $row->ID : (is_scalar($row) ? (int) $row : 0);
            if ($id > 0 && in_array($id, $stickyIds, true)) {
                $surviving[] = $id;
            }
        }

        return $surviving;
    }

    /**
     * The promoted sticky posts in the list's own order (direction-aware —
     * an oldest-sorted list leads with its oldest sticky; date tiebreak on
     * id, same as the rows under the block).
     *
     * @param list<int> $stickyIds
     * @return list<WP_Post>
     */
    private function stickyPosts(array $stickyIds, string $direction): array
    {
        $posts = [];
        foreach ($stickyIds as $id) {
            $post = get_post($id);
            if ($post instanceof WP_Post) {
                $posts[] = $post;
            }
        }
        $direction === 'ASC'
            ? usort($posts, static fn (WP_Post $a, WP_Post $b): int => [$a->post_date, $a->ID] <=> [$b->post_date, $b->ID])
            : usort($posts, static fn (WP_Post $a, WP_Post $b): int => [$b->post_date, $b->ID] <=> [$a->post_date, $a->ID]);

        return $posts;
    }

    /**
     * A single post of the type for the current viewer. Password
     * -protected posts come back as their locked shape (title +
     * password flag — the caller answers a restricted body); private
     * posts only for viewers allowed to read them.
     */
    public function byId(int $id, PublicType $type): ?WP_Post
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post
            || !in_array($post->post_type, $type->postTypes, true)) {
            return null;
        }

        $status = (string) $post->post_status;
        if ($status === 'publish') {
            return $post;
        }
        if ($status === 'private'
            && (current_user_can('read_post', $post->ID) || (int) $post->post_author === get_current_user_id())) {
            return $post;
        }

        return null;
    }

    /**
     * The same viewer-relative read as byId, keyed by slug: detail routes
     * answer slugs, and the page resolves the numeric id only after the
     * lookup. WP_Query normalizes the incoming slug the same way core
     * permalinks do (sanitize_title_for_query), so percent-encoded
     * non-ASCII slugs match the stored post_name.
     */
    /**
     * Rows for explicit ids, preserving the given order — the favorites
     * and profile paths read relation-table ids (favorite recency) and
     * must not re-sort. One query instead of a per-id loop: the mass-fill
     * primes the post, meta, term and author caches for the whole page
     * up front instead of ten-plus cold queries per row.
     *
     * The visibility policy mirrors the lists: publish rows only, never a
     * password-locked one, and never outside the public types — a public
     * projection API must not leak a locked summary even when a caller's
     * own SQL already excluded it. Stickies are not prepended and the raw
     * row set passes through unfiltered: the id order is the contract,
     * not query heuristics.
     *
     * @param list<int> $ids
     * @return list<WP_Post>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $query = new WP_Query([
            'post__in' => $ids,
            'post_type' => PublicTypes::wpPostTypes(),
            'post_status' => 'publish',
            'has_password' => false,
            'orderby' => 'post__in',
            'posts_per_page' => count($ids),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => true,
        ]);
        /** @var list<WP_Post> $posts */
        $posts = array_values(array_filter(is_array($query->posts) ? $query->posts : [], static fn ($post): bool => $post instanceof WP_Post));
        cache_users(wp_list_pluck($posts, 'post_author'));

        return $posts;
    }

    public function bySlug(string $slug, PublicType $type): ?WP_Post
    {
        $found = get_posts([
            'name' => $slug,
            'post_type' => $type->postTypes,
            'post_status' => ['publish', 'private'],
            'posts_per_page' => 1,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);
        $post = $found[0] ?? null;
        if (!$post instanceof WP_Post) {
            return null;
        }

        $status = (string) $post->post_status;
        if ($status === 'publish') {
            return $post;
        }
        if ($status === 'private'
            && (current_user_can('read_post', $post->ID) || (int) $post->post_author === get_current_user_id())) {
            return $post;
        }

        return null;
    }

    /**
     * Adjacent public posts under the list ordering (date + id, same
     * direction), scoped to the type's WP post types. Two small bounded
     * queries per call; `before`/`after` are inclusive so same-second
     * publications resolve by id — matching the contract's sort
     * guarantees.
     *
     * @return array{previous: WP_Post|null, next: WP_Post|null}
     */
    public function neighbors(WP_Post $post, PublicType $type): array
    {
        $base = [
            'post_type' => $type->postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            'posts_per_page' => 2,
        ];
        // Gated posts stay out of prev/next for viewers who do not qualify
        // — same exclusion the lists apply (viewer-relative).
        $gateClause = $this->visibility->listExclusions();
        if ($gateClause !== []) {
            $base['meta_query'] = $gateClause;
        }

        $previousQuery = new WP_Query(array_merge($base, [
            'date_query' => [['column' => 'post_date', 'before' => $post->post_date]],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]));
        $nextQuery = new WP_Query(array_merge($base, [
            'date_query' => [['column' => 'post_date', 'after' => $post->post_date]],
            'orderby' => ['date' => 'ASC', 'ID' => 'ASC'],
        ]));

        $pick = static function (WP_Query $query, WP_Post $current, bool $older): ?WP_Post {
            foreach (is_array($query->posts) ? $query->posts : [] as $found) {
                if (!$found instanceof WP_Post || (int) $found->ID === (int) $current->ID) {
                    continue;
                }
                // Same-second publications (a batch import) share a date, and
                // the inclusive date window still returns the ones on the
                // wrong side — there the ID tiebreak decides the direction,
                // so a candidate that is not strictly behind/ahead of the
                // current post is not its neighbor.
                $foundDate = (string) $found->post_date;
                $currentDate = (string) $current->post_date;
                $inDirection = $older
                    ? $foundDate < $currentDate || ($foundDate === $currentDate && (int) $found->ID < (int) $current->ID)
                    : $foundDate > $currentDate || ($foundDate === $currentDate && (int) $found->ID > (int) $current->ID);
                if ($inDirection) {
                    return $found;
                }
            }

            return null;
        };

        return ['previous' => $pick($previousQuery, $post, true), 'next' => $pick($nextQuery, $post, false)];
    }

    /**
     * Comma-separated parameter → sanitized slug list ('' entries dropped).
     *
     * @return list<string>
     */
    private function slugList(string $commaSeparated): array
    {
        $slugs = array_map('sanitize_title', explode(',', $commaSeparated));

        return array_values(array_filter($slugs, static fn (string $slug): bool => $slug !== ''));
    }

    /**
     * The search box's terms, prepared for WP's AND-split LIKE search
     * (0.100.0). Latin/digit runs stay whole; a run of CJK characters
     * becomes overlapping bigrams — `壁纸资源` searches `壁纸 纸资 资源` —
     * because one unsplit CJK phrase otherwise only matches rows carrying
     * it as a contiguous substring. Every bigram present still bounds the
     * match far tighter than an OR, and a single CJK character stays
     * itself. Terms cap at eight so a paste attack cannot inflate the LIKE
     * set; punctuation never forms a term (WP's own search drops it too).
     * A query with no CJK runs passes through normalized to
     * whitespace-separated runs.
     */
    public static function searchTerms(string $query): string
    {
        preg_match_all('/([A-Za-z0-9]+)|([\x{4E00}-\x{9FFF}]+)/u', $query, $runs, PREG_SET_ORDER);

        $terms = [];
        $cjkSeen = false;
        foreach ($runs as $run) {
            $latin = $run[1] ?? '';
            $cjk = $run[2] ?? '';
            if ($latin !== '') {
                $terms[] = $latin;
                continue;
            }
            $cjkSeen = true;
            $length = mb_strlen($cjk);
            if ($length === 1) {
                $terms[] = $cjk;
                continue;
            }
            for ($start = 0; $start + 2 <= $length; $start++) {
                $terms[] = mb_substr($cjk, $start, 2);
            }
        }

        if ($terms === []) {
            return trim($query);
        }

        if (!$cjkSeen) {
            return implode(' ', $terms);
        }

        return implode(' ', array_slice($terms, 0, 8));
    }
}
