<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use WP_Post;
use WP_Query;

/**
 * Read-side queries over public content for the headless API. Wraps
 * WP_Query presets parameterized by PublicType and returns raw WP_Post
 * rows plus totals — mapping into contract DTOs belongs to the presenter
 * layer. Only `publish` status is ever visible; password-protected posts
 * are excluded because they are not public content.
 */
final class ContentQuery
{
    /**
     * Paginated public list of one type. Query parameters arrive already
     * validated by the REST layer; unknown category slugs simply match
     * nothing (an empty list, not an error).
     *
     * @return array{items: list<WP_Post>, total: int}
     */
    public function list(PublicType $type, int $page, int $perPage, string $q, string $category, string $sort): array
    {
        $args = [
            'post_type' => $type->postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => min(100, max(1, $perPage)),
            'paged' => max(1, $page),
            'orderby' => 'date',
            'order' => $sort === 'oldest' ? 'ASC' : 'DESC',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ];

        if ($q !== '') {
            $args['s'] = $q;
        }
        if ($category !== '') {
            $categoryTaxonomy = $type->wpCategoryTaxonomy('category');
            if ($categoryTaxonomy !== null) {
                $args['tax_query'] = [[
                    'taxonomy' => $categoryTaxonomy,
                    'field' => 'slug',
                    'terms' => $category,
                ],
                ];
            }
        }

        $query = new WP_Query($args);
        $items = [];
        foreach (is_array($query->posts) ? $query->posts : [] as $post) {
            if ($post instanceof WP_Post) {
                $items[] = $post;
            }
        }

        $total = (int) $query->found_posts;

        // Since WP 6.3 the found-rows lookup is skipped when a page returns
        // no rows, so an out-of-range page would report 0. The contract
        // keeps real statistics — count once from page 1.
        if ($items === [] && $args['paged'] > 1) {
            $count = new WP_Query(array_merge($args, ['paged' => 1, 'fields' => 'ids']));
            $total = (int) $count->found_posts;
        }

        return ['items' => $items, 'total' => $total];
    }

    /** A single public post of the type; null when missing or not public. */
    public function byId(int $id, PublicType $type): ?WP_Post
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post
            || !in_array($post->post_type, $type->postTypes, true)
            || $post->post_status !== 'publish') {
            return null;
        }
        if ((string) $post->post_password !== '') {
            return null;
        }

        return $post;
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
