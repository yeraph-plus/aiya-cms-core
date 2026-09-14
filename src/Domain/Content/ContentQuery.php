<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use WP_Post;
use WP_Query;

/**
 * Read-side queries over public content for the headless API. Wraps
 * WP_Query presets parameterized by PublicType and returns raw WP_Post
 * rows plus totals — mapping into contract DTOs belongs to the presenter
 * layer.
 *
 * Visibility rules (0.46.0 badge batch):
 *  - `publish` is the public baseline; password-protected posts enter
 *    the list as locked shapes (the presenter marks them `password`)
 *    and the detail answers a restricted body until the visitor unlocks
 *    the post through the content/unlock endpoint;
 *  - `private` posts join queries only for a viewer who may read them
 *    (their own posts, or `read_private_posts` for editors/admins) and
 *    carry the `private` badge — anonymous traffic never sees them, so
 *    shared caches stay safe;
 *  - sticky posts lead page one of every list through an explicit
 *    post__in merge — never WP's automatic prepend, which would push a
 *    page one row past its configured size.
 */
final class ContentQuery
{
    public function __construct()
    {
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
     * nothing (an empty list, not an error).
     *
     * @return array{items: list<WP_Post>, total: int}
     */
    public function list(PublicType $type, int $page, int $perPage, string $q, string $category, string $sort, string $author = '', string $tag = ''): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $statuses = ['publish'];

        if ($this->canReadPrivate()) {
            $statuses[] = 'private';
        } elseif ($this->ownPrivateQuery()) {
            // Logged-in without the capability: WP's status SQL limits
            // 'private' rows to the viewer's own — public rows stay listed.
            $statuses[] = 'private';
        }

        $args = [
            'post_type' => $type->postTypes,
            'post_status' => $statuses,
            'has_password' => false,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => $sort === 'rand' ? 'rand' : 'date',
            'order' => $sort === 'oldest' ? 'ASC' : 'DESC',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ];

        if ($q !== '') {
            $args['s'] = $q;
        }
        if ($author !== '') {
            // author_name keys on user_nicename — the public profile slug.
            $args['author_name'] = $author;
        }
        $taxQuery = [];
        if ($category !== '') {
            $categoryTaxonomy = $type->wpCategoryTaxonomy('category');
            if ($categoryTaxonomy !== null) {
                $taxQuery[] = [
                    'taxonomy' => $categoryTaxonomy,
                    'field' => 'slug',
                    'terms' => $category,
                ];
            }
        }
        if ($tag !== '') {
            $tagTaxonomy = $type->wpCategoryTaxonomy('tag');
            if ($tagTaxonomy !== null) {
                $taxQuery[] = [
                    'taxonomy' => $tagTaxonomy,
                    'field' => 'slug',
                    'terms' => $tag,
                ];
            }
        }
        if ($taxQuery !== []) {
            // Multiple legs (category + tag) narrow with the default AND relation.
            $args['tax_query'] = $taxQuery;
        }

        $query = new WP_Query($args);
        $items = [];
        foreach (is_array($query->posts) ? $query->posts : [] as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $post;
            }
        }

        // Page one leads with the sticky posts of this type that survive
        // the active filters. They occupy this page's quota (explicit
        // post__in merge, dedup keeps the count at exactly perPage) —
        // WP's automatic prepend is precisely what used to overflow a
        // 20-per-page request to 21 rows. Random order has no "front",
        // so the promotion only applies to date sorts.
        if ($page === 1 && $sort !== 'rand') {
            $sticky = $this->stickyIdsFor($type);
            if ($sticky !== []) {
                $merged = $this->mergeStickyFirst($sticky, $items, $perPage);
                if ($merged !== null) {
                    $items = $merged;
                }
            }
        }

        $total = (int) $query->found_posts;

        // Since WP 6.3 the found-rows lookup is skipped when a page returns
        // no rows, so an out-of-range page would report 0. The contract
        // keeps real statistics — count once from page 1.
        if ($items === [] && $page > 1) {
            $count = new WP_Query(array_merge($args, ['paged' => 1, 'fields' => 'ids']));
            $total = (int) $count->found_posts;
        }

        return ['items' => $items, 'total' => $total];
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
     * Moves the sticky rows to the front and tops the page back up to
     * perPage from the tail. Returns null when the reorder changes
     * nothing (no sticky rows in this page) so the caller keeps the
     * query result untouched.
     *
     * @param list<int> $stickyIds
     * @param list<WP_Post> $items
     * @return list<WP_Post>|null
     */
    private function mergeStickyFirst(array $stickyIds, array $items, int $perPage): ?array
    {
        $byId = [];
        foreach ($items as $post) {
            $byId[(int) $post->ID] = $post;
        }

        $leading = [];
        foreach ($stickyIds as $id) {
            if (isset($byId[$id])) {
                $leading[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        if ($leading === []) {
            return null;
        }

        // Keep the page size honest: drop from the tail (lowest relevance
        // under date sort) rather than overflowing the requested quota.
        return array_slice(array_merge($leading, array_values($byId)), 0, $perPage);
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

        $previousQuery = new WP_Query(array_merge($base, [
            'date_query' => [['column' => 'post_date', 'before' => $post->post_date]],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]));
        $nextQuery = new WP_Query(array_merge($base, [
            'date_query' => [['column' => 'post_date', 'after' => $post->post_date]],
            'orderby' => ['date' => 'ASC', 'ID' => 'ASC'],
        ]));

        $pick = static function (WP_Query $query, WP_Post $current): ?WP_Post {
            foreach (is_array($query->posts) ? $query->posts : [] as $found) {
                if ($found instanceof WP_Post && (int) $found->ID !== (int) $current->ID) {
                    return $found;
                }
            }

            return null;
        };

        return ['previous' => $pick($previousQuery, $post), 'next' => $pick($nextQuery, $post)];
    }
}
