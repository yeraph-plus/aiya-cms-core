<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use WP_Error;

/**
 * The discussion thread store on its own tables
 * (`{prefix}aiya_discussions` + `{prefix}aiya_discussion_replies`): plain
 * custom tables outside the WP post/comments models (2026-09-08 decision),
 * written exclusively through this service. Authorization follows the
 * legacy issue semantics — the thread/reply author or an `edit_pages`
 * administrator — and the reply counters on the thread are denormalized
 * columns kept fresh by syncReplyStats().
 *
 * Content is kses-filtered at the single write path, so everything stored
 * is safe to hand to the contract as-is.
 */
final class DiscussionService
{
    /** Post types a thread may bind to (the ticket/article surface). */
    private const BINDABLE_TYPES = ['post', 'resource'];

    private const TITLE_LENGTH = 191;

    /**
     * Creates a thread and returns its id.
     *
     * @return int|WP_Error
     */
    public function create(int $userId, string $title, string $type, string $content, int $postId = 0): int|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The thread author does not exist.', 'aiya-core'));
        }
        $title = trim($title);
        if ($title === '') {
            return new WP_Error('aiya_invalid_param', __('The thread title is required.', 'aiya-core'));
        }
        if (!ThreadType::isValid($type)) {
            return new WP_Error('aiya_invalid_param', __('Unknown thread type.', 'aiya-core'));
        }
        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('aiya_invalid_param', __('The thread body is required.', 'aiya-core'));
        }
        $postId = $this->validateBinding($postId);
        if (is_wp_error($postId)) {
            return $postId;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $this->threadsTable(),
            [
                'user_id' => $userId,
                'type' => $type,
                'status' => ThreadStatus::OPEN,
                'title' => mb_substr(trim($title), 0, self::TITLE_LENGTH),
                'content' => wp_kses_post($content),
                'post_id' => $postId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The thread could not be stored.', 'aiya-core'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Visible thread rows, newest activity first (or by creation). All
     * threads are public — the community has no draft state.
     *
     * @return array{items: list<object{id:int,user_id:int,type:string,status:string,title:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}>, total:int, pages:int}
     */
    public function list(string $type, string $status, int $postId, int $authorId, string $sort, int $paged, int $perPage): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->threadsTable();
        $where = ['1=1'];
        $params = [];

        if (ThreadType::isValid($type)) {
            $where[] = 'type = %s';
            $params[] = $type;
        }
        if (ThreadStatus::isValid($status)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ($postId > 0) {
            $where[] = 'post_id = %d';
            $params[] = $postId;
        }
        if ($authorId > 0) {
            $where[] = 'user_id = %d';
            $params[] = $authorId;
        }
        $order = $sort === 'newest' ? 'created_at DESC, id DESC' : 'COALESCE(last_reply_at, created_at) DESC, id DESC';
        $whereSql = implode(' AND ', $where);
        // Every where fragment and the order clause come exclusively from
        // the internal whitelists above; the interpolation is safe but
        // leaves phpstan's literal-string inference.
        $countSql = "SELECT COUNT(id) FROM $table WHERE $whereSql";
        $total = (int) (count($params) > 0
            // @phpstan-ignore argument.type (whitelist interpolation)
            ? $wpdb->get_var($wpdb->prepare($countSql, $params))
            : $wpdb->get_var($countSql));

        $rows = [];
        if ($total > 0) {
            $listSql = "SELECT id, user_id, type, status, title, post_id, reply_count, last_reply_user_id, last_reply_at, created_at
             FROM $table WHERE $whereSql ORDER BY $order LIMIT %d OFFSET %d";
            /** @var list<object{id:int,user_id:int,type:string,status:string,title:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}>|null $rows */
            $rows = $wpdb->get_results(
                // @phpstan-ignore argument.type (whitelist interpolation)
                $wpdb->prepare($listSql, array_merge($params, [$perPage, ($paged - 1) * $perPage]))
            );
        }

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return object{id:int,user_id:int,type:string,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string}|null
     */
    public function byId(int $threadId): ?object
    {
        if ($threadId <= 0) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var object{id:int,user_id:int,type:string,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, user_id, type, status, title, content, post_id, reply_count, last_reply_user_id, last_reply_at, created_at, updated_at
             FROM %i WHERE id = %d',
            $this->threadsTable(),
            $threadId
        ));

        return $row;
    }

    /**
     * Flat replies of a thread, oldest first.
     *
     * @return array{items: list<object{id:int,thread_id:int,user_id:int,content:string,created_at:string}>, total:int, pages:int}
     */
    public function replies(int $threadId, int $paged, int $perPage): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->repliesTable();
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(id) FROM %i WHERE thread_id = %d', $table, $threadId));

        $rows = [];
        if ($total > 0) {
            /** @var list<object{id:int,thread_id:int,user_id:int,content:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, thread_id, user_id, content, created_at FROM %i WHERE thread_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
                $table,
                $threadId,
                $perPage,
                ($paged - 1) * $perPage
            ));
        }

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /** @return object{id:int,thread_id:int,user_id:int,content:string,created_at:string}|null */
    public function replyById(int $replyId): ?object
    {
        if ($replyId <= 0) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var object{id:int,thread_id:int,user_id:int,content:string,created_at:string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, thread_id, user_id, content, created_at FROM %i WHERE id = %d',
            $this->repliesTable(),
            $replyId
        ));

        return $row;
    }

    /**
     * Adds a reply: locked threads refuse (WP_Error carrying 409 data),
     * counters resync, and an untouched `open` thread flips to `answered`
     * when someone other than the author replies.
     *
     * @return int|WP_Error the reply id
     */
    public function reply(int $threadId, int $userId, string $content): int|WP_Error
    {
        $thread = $this->byId($threadId);
        if ($thread === null) {
            return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
        }
        if (ThreadStatus::locksReplies((string) $thread->status)) {
            return new WP_Error('aiya_thread_locked', __('This thread is closed to new replies.', 'aiya-core'), ['status' => 409]);
        }
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The replying user does not exist.', 'aiya-core'));
        }
        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('aiya_invalid_param', __('The reply body is required.', 'aiya-core'));
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $this->repliesTable(),
            [
                'thread_id' => $threadId,
                'user_id' => $userId,
                'content' => wp_kses_post($content),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The reply could not be stored.', 'aiya-core'));
        }

        $this->syncReplyStats($threadId);
        $next = ThreadStatus::afterReply((string) $thread->status, $userId, (int) $thread->user_id);
        if ($next !== (string) $thread->status) {
            $wpdb->update($this->threadsTable(), ['status' => $next, 'updated_at' => $now], ['id' => $threadId], ['%s', '%s'], ['%d']);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Updates title / content / type / status. Only the author or an
     * administrator may act; anything absent stays untouched.
     *
     * @param array{title?:string, content?:string, type?:string, status?:string} $fields
     * @return true|WP_Error
     */
    public function update(int $threadId, int $actorId, array $fields): bool|WP_Error
    {
        $thread = $this->byId($threadId);
        if ($thread === null) {
            return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
        }
        if (!$this->canModerate((int) $thread->user_id, $actorId)) {
            return new WP_Error('aiya_forbidden', __('You are not allowed to edit this thread.', 'aiya-core'), ['status' => 403]);
        }

        $data = [];
        if (array_key_exists('title', $fields)) {
            $title = trim((string) $fields['title']);
            if ($title === '') {
                return new WP_Error('aiya_invalid_param', __('The thread title is required.', 'aiya-core'));
            }
            $data['title'] = mb_substr($title, 0, self::TITLE_LENGTH);
        }
        if (array_key_exists('content', $fields)) {
            $content = (string) $fields['content'];
            if (trim(wp_strip_all_tags($content)) === '') {
                return new WP_Error('aiya_invalid_param', __('The thread body is required.', 'aiya-core'));
            }
            $data['content'] = wp_kses_post($content);
        }
        if (array_key_exists('type', $fields)) {
            $type = (string) $fields['type'];
            if (!ThreadType::isValid($type)) {
                return new WP_Error('aiya_invalid_param', __('Unknown thread type.', 'aiya-core'));
            }
            $data['type'] = $type;
        }
        if (array_key_exists('status', $fields)) {
            $status = (string) $fields['status'];
            if (!ThreadStatus::isValid($status)) {
                return new WP_Error('aiya_invalid_param', __('Unknown thread status.', 'aiya-core'));
            }
            $data['status'] = $status;
        }

        if ($data === []) {
            return true;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $data['updated_at'] = current_time('mysql', true);
        $updated = $wpdb->update($this->threadsTable(), $data, ['id' => $threadId]);
        if ($updated === false) {
            return new WP_Error('aiya_db_error', __('The thread could not be updated.', 'aiya-core'));
        }

        return true;
    }

    /** @return true|WP_Error */
    public function delete(int $threadId, int $actorId): bool|WP_Error
    {
        $thread = $this->byId($threadId);
        if ($thread === null) {
            return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
        }
        if (!$this->canModerate((int) $thread->user_id, $actorId)) {
            return new WP_Error('aiya_forbidden', __('You are not allowed to delete this thread.', 'aiya-core'), ['status' => 403]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $replies = $wpdb->delete($this->repliesTable(), ['thread_id' => $threadId], ['%d']);
        if ($replies === false) {
            return new WP_Error('aiya_db_error', __('The thread replies could not be deleted.', 'aiya-core'));
        }
        $deleted = $wpdb->delete($this->threadsTable(), ['id' => $threadId], ['%d']);
        if ($deleted === false) {
            return new WP_Error('aiya_db_error', __('The thread could not be deleted.', 'aiya-core'));
        }

        return true;
    }

    /** @return true|WP_Error */
    public function deleteReply(int $replyId, int $actorId): bool|WP_Error
    {
        $reply = $this->replyById($replyId);
        if ($reply === null) {
            return new WP_Error('aiya_not_found', __('Reply not found.', 'aiya-core'), ['status' => 404]);
        }
        if (!$this->canModerate((int) $reply->user_id, $actorId)) {
            return new WP_Error('aiya_forbidden', __('You are not allowed to delete this reply.', 'aiya-core'), ['status' => 403]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $deleted = $wpdb->delete($this->repliesTable(), ['id' => $replyId], ['%d']);
        if ($deleted === false) {
            return new WP_Error('aiya_db_error', __('The reply could not be deleted.', 'aiya-core'));
        }

        $this->syncReplyStats((int) $reply->thread_id);

        return true;
    }

    /** The thread author or an `edit_pages` administrator. */
    public function canModerate(int $ownerId, int $actorId): bool
    {
        return $ownerId === $actorId || ($actorId > 0 && user_can($actorId, 'edit_pages'));
    }

    /**
     * Refreshes the denormalized reply counters on the thread — the same
     * job the legacy sync helper did for issues.
     */
    public function syncReplyStats(int $threadId): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $replies = $this->repliesTable();
        $last = $wpdb->get_row($wpdb->prepare(
            'SELECT user_id, created_at FROM %i WHERE thread_id = %d ORDER BY id DESC LIMIT 1',
            $replies,
            $threadId
        ));
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(id) FROM %i WHERE thread_id = %d', $replies, $threadId));

        $wpdb->update(
            $this->threadsTable(),
            [
                'reply_count' => $count,
                'last_reply_user_id' => $last !== null ? (int) $last->user_id : 0,
                'last_reply_at' => $last !== null ? (string) $last->created_at : null,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $threadId],
            ['%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    /** Validates the postRef binding: must be an existing published post/resource. */
    private function validateBinding(int $postId): int|WP_Error
    {
        $postId = absint((string) $postId);
        if ($postId === 0) {
            return 0;
        }

        $post = get_post($postId);
        if ($post === null || !in_array($post->post_type, self::BINDABLE_TYPES, true) || $post->post_status !== 'publish') {
            return new WP_Error('aiya_invalid_param', __('The bound content does not exist or cannot carry discussions.', 'aiya-core'));
        }

        return $postId;
    }

    private function threadsTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_discussions';
    }

    private function repliesTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_discussion_replies';
    }

    /** Creates both tables; the 0.26.0 schema migration callback. */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $threads = $wpdb->prefix . 'aiya_discussions';
        dbDelta(
            "CREATE TABLE $threads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'discussion',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                title VARCHAR(191) NOT NULL,
                content TEXT NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                reply_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_reply_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_reply_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY type (type),
                KEY status (status),
                KEY post_id (post_id),
                KEY last_reply_at (last_reply_at)
            ) $charset;"
        );

        $replies = $wpdb->prefix . 'aiya_discussion_replies';
        dbDelta(
            "CREATE TABLE $replies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                thread_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY thread_id (thread_id),
                KEY user_id (user_id)
            ) $charset;"
        );
    }
}
