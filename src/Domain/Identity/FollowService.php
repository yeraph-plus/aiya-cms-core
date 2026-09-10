<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use WP_Error;

/**
 * The user-follow store on the `aiya_user_follows` relation table
 * (0.28.0 schema): one row per (follower, followed) pair under a unique
 * index. Following yourself is not a thing; re-following is a no-op
 * success; un-following an absent row is a no-op success. The caller
 * resolves ids to Author projections — this store is ids and counts only.
 */
final class FollowService
{
    public function __construct()
    {
    }

    /** @return true|WP_Error */
    public function follow(int $followerId, int $followedId): bool|WP_Error
    {
        if ($followerId <= 0 || $followedId <= 0) {
            return new WP_Error('aiya_invalid_param', __('Invalid follow target.', 'aiya-core'), ['status' => 400]);
        }
        if ($followerId === $followedId) {
            return new WP_Error('aiya_invalid_param', __('You cannot follow yourself.', 'aiya-core'), ['status' => 400]);
        }
        if (get_userdata($followedId) === false) {
            return new WP_Error('aiya_user_missing', __('The user to follow does not exist.', 'aiya-core'), ['status' => 404]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert(
            $this->table(),
            ['follower_id' => $followerId, 'followed_id' => $followedId, 'created_at' => current_time('mysql', true)],
            ['%d', '%d', '%s']
        );

        // Duplicate-key failures are the expected re-follow path.
        return $inserted === false && (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE follower_id = %d AND followed_id = %d',
            $this->table(),
            $followerId,
            $followedId
        )) === 0
            ? new WP_Error('aiya_db_error', __('The follow could not be stored.', 'aiya-core'), ['status' => 500])
            : true;
    }

    /** Removes a follow; false only on a DB failure (absent rows are a no-op success). */
    public function unfollow(int $followerId, int $followedId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE follower_id = %d AND followed_id = %d', $this->table(), $followerId, $followedId);
        if (is_string($sql)) {
            return $wpdb->query($sql) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        return false;
    }

    public function isFollowing(int $followerId, int $followedId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE follower_id = %d AND followed_id = %d',
            $this->table(),
            $followerId,
            $followedId
        ));

        return $found !== null;
    }

    public function countFollowing(int $userId): int
    {
        return $this->countBy('follower_id', $userId);
    }

    public function countFollowers(int $userId): int
    {
        return $this->countBy('followed_id', $userId);
    }

    /**
     * The ids of users the given user follows, newest first.
     *
     * @return array{ids: list<int>, total: int, pages: int}
     */
    public function followingIds(int $userId, int $paged, int $perPage): array
    {
        return $this->ids('follower_id', 'followed_id', $userId, $paged, $perPage);
    }

    /**
     * The ids of the given user's followers, newest first.
     *
     * @return array{ids: list<int>, total: int, pages: int}
     */
    public function followerIds(int $userId, int $paged, int $perPage): array
    {
        return $this->ids('followed_id', 'follower_id', $userId, $paged, $perPage);
    }

    /**
     * Shared pagination over one direction of the relation: $holder is the
     * viewer-side column, $target the id column to return. Both are fixed
     * internal identifiers and travel as %i placeholders.
     *
     * @return array{ids: list<int>, total: int, pages: int}
     */
    private function ids(string $holder, string $target, int $userId, int $paged, int $perPage): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();

        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(id) FROM %i WHERE %i = %d',
            $table,
            $holder,
            $userId
        ));

        $rows = [];
        if ($total > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT %i AS target_id FROM %i WHERE %i = %d ORDER BY id DESC LIMIT %d OFFSET %d',
                $target,
                $table,
                $holder,
                $userId,
                $perPage,
                ($paged - 1) * $perPage
            ), ARRAY_A);
        }

        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['target_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return ['ids' => $ids, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    /** @param string $column follower_id|followed_id (fixed internal identifier) */
    private function countBy(string $column, int $userId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(id) FROM %i WHERE %i = %d',
            $this->table(),
            $column,
            $userId
        ));

        return is_numeric($found) ? (int) $found : 0;
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_user_follows';
    }
}
