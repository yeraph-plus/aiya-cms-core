<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

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
 * Scope is the classic `post` type for now; widening to resources is an
 * extra type check away, not a schema change.
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
        $inserted = $wpdb->insert(
            $this->table(),
            ['user_id' => $userId, 'post_id' => $postId, 'created_at' => current_time('mysql', true)],
            ['%d', '%d', '%s']
        );

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

    public function remove(int $userId, int $postId): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE user_id = %d AND post_id = %d', $this->table(), $userId, $postId);
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
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
        $join = "FROM $table f INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id
             WHERE f.user_id = %d AND p.post_status = 'publish' AND p.post_type = 'post' AND p.post_password = ''";

        $total = (int) $wpdb->get_var(
            // @phpstan-ignore argument.type (fixed table interpolation)
            $wpdb->prepare("SELECT COUNT(f.id) $join", $userId)
        );
        $rows = [];
        if ($total > 0) {
            $listSql = "SELECT f.post_id $join ORDER BY f.created_at DESC, f.id DESC LIMIT %d OFFSET %d";
            /** @var list<array{post_id: string|int}>|null $rows */
            $rows = $wpdb->get_results(
                // @phpstan-ignore argument.type (fixed table interpolation)
                $wpdb->prepare($listSql, $userId, $perPage, ($paged - 1) * $perPage),
                ARRAY_A
            );
        }

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ids[] = (int) $row['post_id'];
        }

        return ['ids' => $ids, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /** How many users favorited one post — the future article-side counter. */
    public function countForPost(int $postId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare('SELECT COUNT(id) FROM %i WHERE post_id = %d', $this->table(), $postId));

        return is_numeric($found) ? (int) $found : 0;
    }

    /** Favorites must target an existing published classic post. */
    private function validatePost(int $postId): int|WP_Error
    {
        $postId = absint((string) $postId);
        $post = $postId > 0 ? get_post($postId) : null;

        if ($post === null || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return new WP_Error('aiya_invalid_param', __('Only published posts can be favorited.', 'aiya-core'));
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
