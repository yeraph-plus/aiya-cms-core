<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use WP_Error;

/**
 * The community like relation (0.102.0, batch DL): a dedicated per-thread
 * a dedicated per-thread actor table — deliberately not the post-meta
 * counter domain — with the count materialized on the thread row by this
 * one writer (the same single-writer stance as syncReplyStats). Writes
 * run inside a transaction so the row insert/delete and the counter step
 * commit together; the unique actor key makes repeat likes the "already"
 * no-op. Closed threads refuse new likes; unlike stays available so a
 * reader can always correct their own state.
 */
final class DiscussionLikeService
{
    private function likesTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return $wpdb->prefix . 'aiya_discussion_likes';
    }

    private function threadsTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return $wpdb->prefix . 'aiya_discussions';
    }

    /**
     * Materialized per-thread counts for a page of threads; ids without a
     * row read zero on the caller's side (the threads table is the source
     * of the projection).
     *
     * @param list<int> $threadIds
     * @return array<int, int>
     */
    public function counts(array $threadIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- fixed threads table and an int IN-list, like pendingIds()
        $rows = $wpdb->get_results($wpdb->prepare(
            // @phpstan-ignore argument.type (fixed threads-table interpolation)
            "SELECT id, like_count FROM {$this->threadsTable()} WHERE id IN ($placeholders)",
            ...$ids
        ), ARRAY_A);
        // phpcs:enable

        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (in_array($id, $ids, true)) {
                $counts[$id] = (int) ($row['like_count'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * The threads of a page the viewer has liked, keyed for O(1) row
     * assembly. Rows outside the requested id scope are skipped by the
     * caller — the IN-list stays the only bound.
     *
     * @param list<int> $threadIds
     * @return array<int, true>
     */
    public function likedBy(int $userId, array $threadIds): array
    {
        if ($userId <= 0) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', $threadIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- fixed likes table and an int IN-list
        $rows = $wpdb->get_results($wpdb->prepare(
            // @phpstan-ignore argument.type (fixed likes-table interpolation)
            "SELECT thread_id FROM {$this->likesTable()} WHERE user_id = %d AND thread_id IN ($placeholders)",
            ...array_merge([$userId], $ids)
        ), ARRAY_A);
        // phpcs:enable

        $liked = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $threadId = (int) ($row['thread_id'] ?? 0);
            if (in_array($threadId, $ids, true)) {
                $liked[$threadId] = true;
            }
        }

        return $liked;
    }

    /** Single-row convenience for the detail projection. */
    public function has(int $threadId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return isset($this->likedBy($userId, [$threadId])[$threadId]);
    }

    /**
     * Drops every like row of one thread — the thread-delete companion,
     * so removing a thread never leaves orphaned actor rows behind.
     * Reports success: the caller's delete transaction rolls back when
     * this fails, keeping the "no orphaned rows" promise honest.
     */
    public function purgeForThread(int $threadId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return $wpdb->delete($this->likesTable(), ['thread_id' => $threadId], ['%d']) !== false;
    }

    /**
     * @return array{likes: int, viewerLiked: true, already: bool}|WP_Error
     */
    public function like(int $threadId, int $userId): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('aiya_not_logged_in', __('Please log in to interact.', 'aiya-core'), ['status' => 401]);
        }
        $thread = $this->thread($threadId);
        if ($thread === null) {
            return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
        }
        if ($thread->status === ThreadStatus::CLOSED) {
            return new WP_Error('aiya_thread_closed', __('This thread is closed; likes are no longer accepted.', 'aiya-core'), ['status' => 409]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->query('START TRANSACTION');
        // A duplicate actor is the expected "already" path, not an error —
        // the follow-up existence check separates it from a real failure.
        $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($this->likesTable(), [
            'thread_id' => $threadId,
            'user_id' => $userId,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%d', '%s']);
        $wpdb->suppress_errors(false);
        if ($inserted === false) {
            if (!isset($this->likedBy($userId, [$threadId])[$threadId])) {
                $wpdb->query('ROLLBACK');

                return new WP_Error('aiya_db_error', __('The like could not be stored.', 'aiya-core'));
            }
            $wpdb->query('COMMIT');

            return ['likes' => $this->count($threadId), 'viewerLiked' => true, 'already' => true];
        }

        $bump = $wpdb->prepare('UPDATE %i SET like_count = like_count + 1 WHERE id = %d', $this->threadsTable(), $threadId);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above, guarded by is_string
        $bumped = is_string($bump) ? $wpdb->query($bump) : false;
        // Zero affected rows means the thread vanished between the existence
        // check and this UPDATE — abort rather than commit an orphan like.
        if ((int) $bumped < 1) {
            $wpdb->query('ROLLBACK');

            return new WP_Error('aiya_db_error', __('The like could not be stored.', 'aiya-core'));
        }
        $wpdb->query('COMMIT');

        return ['likes' => $this->count($threadId), 'viewerLiked' => true, 'already' => false];
    }

    /**
     * @return array{likes: int, viewerLiked: false}|WP_Error
     */
    public function unlike(int $threadId, int $userId): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('aiya_not_logged_in', __('Please log in to interact.', 'aiya-core'), ['status' => 401]);
        }
        if ($this->thread($threadId) === null) {
            return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->query('START TRANSACTION');
        $deleted = $wpdb->delete($this->likesTable(), ['thread_id' => $threadId, 'user_id' => $userId], ['%d', '%d']);
        if ($deleted === false) {
            $wpdb->query('ROLLBACK');

            return new WP_Error('aiya_db_error', __('The like could not be removed.', 'aiya-core'));
        }
        if ((int) $deleted > 0) {
            $drop = $wpdb->prepare('UPDATE %i SET like_count = GREATEST(like_count - 1, 0) WHERE id = %d', $this->threadsTable(), $threadId);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above, guarded by is_string
            $dropped = is_string($drop) ? $wpdb->query($drop) : false;
            if ($dropped === false) {
                $wpdb->query('ROLLBACK');

                return new WP_Error('aiya_db_error', __('The like could not be removed.', 'aiya-core'));
            }
        }
        $wpdb->query('COMMIT');

        return ['likes' => $this->count($threadId), 'viewerLiked' => false];
    }

    /** @return object{id: int, status: string}|null */
    private function thread(int $threadId): ?object
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, status FROM %i WHERE id = %d', $this->threadsTable(), $threadId), ARRAY_A);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        return (object) ['id' => (int) ($rows[0]['id'] ?? 0), 'status' => (string) ($rows[0]['status'] ?? ThreadStatus::OPEN)];
    }

    private function count(int $threadId): int
    {
        return $this->counts([$threadId])[$threadId] ?? 0;
    }
}
