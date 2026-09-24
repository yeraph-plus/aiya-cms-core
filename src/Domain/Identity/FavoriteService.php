<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Domain\Content\PublicTypes;
use WP_Error;

/**
 * The user favorites store on the `aiya_user_favorites` relation table
 * (2026-09-09 plan): one row per (user, post) pair under a unique index,
 * so a favorite click is a single insert-or-nothing instead of a
 * read-modify-write over an array, and "who favorited this post" is a
 * plain indexed lookup. Rows pointing at posts that later became private
 * or disappeared stay in the table but never surface — every read joins
 * published posts.
 *
 * Scope is every public type (post/page/resource — the same list the
 * front end renders detail shells for); unpublished rows are refused on
 * write and filtered on read.
 */
final class FavoriteService
{
    /**
     * Adds a favorite. Inserting an existing pair is a no-op success.
     *
     * @return true|WP_Error
     */
    public function add(int $userId, int $postId): bool|WP_Error
    {
        $postId = $this->validatePost($postId);
        if (is_wp_error($postId)) {
            return $postId;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        // A re-favorite is the expected duplicate-key path, not a site error:
        // unsuppressed, wpdb prints its error HTML straight into the JSON
        // response body and the front end reads the write as a failure.
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            $this->table(),
            ['user_id' => $userId, 'post_id' => $postId, 'created_at' => current_time('mysql', true)],
            ['%d', '%d', '%s']
        );
        $wpdb->suppress_errors($suppress);

        // Duplicate-key failures are the expected re-favorite path.
        return $inserted === false && (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND post_id = %d',
            $this->table(),
            $userId,
            $postId
        )) === 0
            ? new WP_Error('aiya_db_error', __('The favorite could not be stored.', 'aiya-core'))
            : true;
    }

    /** Removes a favorite; false only on a DB failure (absent rows are a no-op success). */
    public function remove(int $userId, int $postId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE user_id = %d AND post_id = %d', $this->table(), $userId, $postId);
        if (is_string($sql)) {
            return $wpdb->query($sql) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        return false;
    }

    public function has(int $userId, int $postId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND post_id = %d',
            $this->table(),
            $userId,
            $postId
        ));

        return $found !== null;
    }

    /**
     * Published favorites of a user, newest first.
     *
     * @return array{ids: list<int>, total: int, pages: int}
     */
    public function published(int $userId, int $paged, int $perPage): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        // The join interpolates the fixed favorites table name and the core
        // posts table property only — no external input ever enters the
        // string, but the interpolation leaves phpstan's literal inference.
        // Visibility-gated posts stay out of public profiles entirely: the
        // favorites list is guest-readable and shared-cacheable, so there
        // is no viewer-relative exclusion here — both gates always apply.
        $gate = "AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} gm
             WHERE gm.post_id = p.ID AND gm.meta_key = 'aiya_core_visibility' AND gm.meta_value <> '')";
        // The type list comes from the fixed registry, never user input —
        // same interpolation convention as the table names beside it.
        $types = implode("','", array_map('esc_sql', PublicTypes::wpPostTypes()));
        $join = "FROM $table f INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id
             WHERE f.user_id = %d AND p.post_status = 'publish' AND p.post_type IN ('$types') AND p.post_password = '' $gate";

        $total = (int) $wpdb->get_var(
            // @phpstan-ignore argument.type (fixed table interpolation)
            $wpdb->prepare("SELECT COUNT(f.id) $join", $userId) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed table interpolation; the placeholders live in $join
        );
        $rows = [];
        if ($total > 0) {
            $listSql = "SELECT f.post_id $join ORDER BY f.created_at DESC, f.id DESC LIMIT %d OFFSET %d";
            /** @var list<array{post_id: string|int}>|null $rows */
            $rows = $wpdb->get_results(
                // @phpstan-ignore argument.type (fixed table interpolation)
                $wpdb->prepare($listSql, $userId, $perPage, ($paged - 1) * $perPage), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table interpolation, see note above
                ARRAY_A
            );
        }

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ids[] = (int) $row['post_id'];
        }

        return ['ids' => $ids, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /** Every user id that favorited a post — the update-notification fan-out list.
     *
     * @return list<int>
     */
    public function favoritedUserIds(int $postId): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM %i WHERE post_id = %d', $this->table(), $postId));

        return array_map('intval', is_array($rows) ? $rows : []);
    }

    /** How many users favorited one post — the future article-side counter. */
    public function countForPost(int $postId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare('SELECT COUNT(id) FROM %i WHERE post_id = %d', $this->table(), $postId));

        return is_numeric($found) ? (int) $found : 0;
    }

    /** How many favorites the author's published posts received in total. */
    public function countForAuthor(int $authorId): int
    {
        $types = implode("','", array_map('esc_sql', PublicTypes::wpPostTypes()));
        global $wpdb;
        /** @var \wpdb $wpdb */
        // Password-protected posts stay out of the count: the public list
        // reads exclude them, and the author stat must not leak a hidden
        // post's popularity.
        $query = 'SELECT COUNT(f.id) FROM %i f INNER JOIN %i p ON p.ID = f.post_id'
            . " WHERE p.post_author = %d AND p.post_status = %s AND p.post_type IN ('$types') AND p.post_password = ''";
        $found = $wpdb->get_var(
            // @phpstan-ignore argument.type (fixed registry interpolation, see published())
            $wpdb->prepare($query, $this->table(), $wpdb->posts, $authorId, 'publish') // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed registry interpolation; see published()
        );

        return is_numeric($found) ? (int) $found : 0;
    }

    /** Favorites must target an existing published row of a public type. */
    private function validatePost(int $postId): int|WP_Error
    {
        $postId = absint((string) $postId);
        $post = $postId > 0 ? get_post($postId) : null;

        if (
            $post === null
            || PublicTypes::forPostType((string) $post->post_type) === null
            || $post->post_status !== 'publish'
        ) {
            return new WP_Error('aiya_invalid_param', __('Only published content can be favorited.', 'aiya-core'), ['status' => 400]);
        }

        return $postId;
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_user_favorites';
    }
}
