<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use WP_Error;

/**
 * The discussion thread store on its own tables
 * (`{prefix}aiya_discussions` + `{prefix}aiya_discussion_replies` +
 * `{prefix}aiya_discussion_boards`): plain custom tables outside the WP
 * post/comments models (2026-09-08 decision), written exclusively through
 * this service. The former three-value `type` column became customizable
 * boards (0.45.0): a thread belongs to exactly one board, and the last
 * remaining board cannot be deleted — its threads would have nowhere to
 * go. Authorization follows the legacy issue semantics — the thread/reply
 * author or an `edit_pages` administrator — and the reply counters on the
 * thread are denormalized columns kept fresh by syncReplyStats().
 *
 * Content is kses-filtered at the single write path, so everything stored
 * is safe to hand to the contract as-is. Threads and replies embed at
 * most nine images so the front end's grid layouts stay bounded; tags
 * live inside the content text and are matched read-time through the
 * search-style filters below (no tag column by decision).
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
    public function create(int $userId, string $title, string $content, int $boardId, int $postId = 0): int|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The thread author does not exist.', 'aiya-core'), ['status' => 400]);
        }
        // The title is optional for social-style threads: an empty title
        // renders as a content-only card on the front end.
        $title = trim($title);
        if ($this->boardById($boardId) === null) {
            return new WP_Error('aiya_invalid_param', __('Unknown board.', 'aiya-core'), ['status' => 400]);
        }
        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('aiya_invalid_param', __('The thread body is required.', 'aiya-core'), ['status' => 400]);
        }
        $content = wp_kses_post($content);
        if (DiscussionContent::imageCount($content) > DiscussionContent::MAX_IMAGES) {
            return new WP_Error('aiya_invalid_param', __('A thread can carry at most nine images.', 'aiya-core'), ['status' => 400]);
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
                'board_id' => $boardId,
                'status' => ThreadStatus::OPEN,
                'title' => mb_substr(trim($title), 0, self::TITLE_LENGTH),
                'content' => $content,
                'post_id' => $postId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The thread could not be stored.', 'aiya-core'));
        }

        do_action('aiya_core_thread_published', (int) $wpdb->insert_id, $userId, $boardId);

        return (int) $wpdb->insert_id;
    }

    /**
     * Visible thread rows, newest activity first (or by creation). All
     * threads are public — the community has no draft state. Optional
     * filters: board, keyword search over title and content, and a
     * #tag matched against both tag shapes in the content.
     *
     * @return array{items: list<object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}>, total:int, pages:int}
     */
    public function list(string $status, int $postId, int $authorId, string $sort, int $paged, int $perPage, string $search = '', int $boardId = 0, string $tag = ''): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->threadsTable();
        $boards = $this->boardsTable();
        $where = ['1=1'];
        $params = [];

        if (ThreadStatus::isValid($status)) {
            $where[] = 'd.status = %s';
            $params[] = $status;
        }
        if ($postId > 0) {
            $where[] = 'd.post_id = %d';
            $params[] = $postId;
        }
        if ($authorId > 0) {
            $where[] = 'd.user_id = %d';
            $params[] = $authorId;
        }
        if ($boardId > 0) {
            $where[] = 'd.board_id = %d';
            $params[] = $boardId;
        } elseif ($boardId < 0) {
            // A negative id is the caller's "unknown board slug" marker:
            // the filter must match nothing, not fall back to everything.
            $where[] = '1=0';
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(d.title LIKE %s OR d.content LIKE %s)';
            array_push($params, $like, $like);
        }
        $tag = trim($tag);
        if ($tag !== '') {
            [$tagSql, $tagParams] = DiscussionContent::tagFilter($tag, 'd.content');
            $where[] = $tagSql;
            $params = array_merge($params, $tagParams);
        }

        $order = $sort === 'newest' ? 'd.created_at DESC, d.id DESC' : 'COALESCE(d.last_reply_at, d.created_at) DESC, d.id DESC';
        $whereSql = implode(' AND ', $where);
        // Every where fragment and the order clause come exclusively from
        // the internal whitelists above; the interpolation is safe but
        // leaves phpstan's literal-string inference.
        $countSql = "SELECT COUNT(d.id) FROM $table d WHERE $whereSql";
        $total = (int) (count($params) > 0
            // @phpstan-ignore argument.type (whitelist interpolation)
            ? $wpdb->get_var($wpdb->prepare($countSql, $params)) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- whitelist-built SQL, see note above
            : $wpdb->get_var($countSql)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- whitelist-built SQL, see note above

        $rows = [];
        if ($total > 0) {
            $listSql = "SELECT d.id, d.user_id, d.board_id, b.slug AS board_slug, b.name AS board_name, d.status, d.title, d.content, d.post_id, d.reply_count, d.last_reply_user_id, d.last_reply_at, d.created_at
             FROM $table d LEFT JOIN $boards b ON b.id = d.board_id WHERE $whereSql ORDER BY $order LIMIT %d OFFSET %d";
            /** @var list<object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}>|null $rows */
            $rows = $wpdb->get_results(
                // @phpstan-ignore argument.type (whitelist interpolation)
                $wpdb->prepare($listSql, array_merge($params, [$perPage, ($paged - 1) * $perPage])) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist-built SQL, see note above
            );
        }

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string}|null
     */
    public function byId(int $threadId): ?object
    {
        if ($threadId <= 0) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT d.id, d.user_id, d.board_id, b.slug AS board_slug, b.name AS board_name, d.status, d.title, d.content, d.post_id, d.reply_count, d.last_reply_user_id, d.last_reply_at, d.created_at, d.updated_at
             FROM %i d LEFT JOIN %i b ON b.id = d.board_id WHERE d.id = %d',
            $this->threadsTable(),
            $this->boardsTable(),
            $threadId
        ));

        return $row;
    }

    /**
     * Every board in menu order, thread counts attached.
     *
     * @return list<object{id:int,slug:string,name:string,description:string,sort:int,threads:int}>
     */
    public function boards(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<object{id:int,slug:string,name:string,description:string,sort:int,threads:int}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT b.id, b.slug, b.name, b.description, b.sort, COUNT(d.id) AS threads
             FROM %i b LEFT JOIN %i d ON d.board_id = b.id
             GROUP BY b.id, b.slug, b.name, b.description, b.sort
             ORDER BY b.sort, b.id',
            $this->boardsTable(),
            $this->threadsTable()
        ));

        return is_array($rows) ? $rows : [];
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

    /** @return object{id:int,slug:string,name:string,description:string,sort:int}|null */
    public function boardById(int $boardId): ?object
    {
        if ($boardId <= 0) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var object{id:int,slug:string,name:string,description:string,sort:int}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, slug, name, description, sort FROM %i WHERE id = %d',
            $this->boardsTable(),
            $boardId
        ));

        return $row;
    }

    /** @return object{id:int,slug:string,name:string,description:string,sort:int}|null */
    public function boardBySlug(string $slug): ?object
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var object{id:int,slug:string,name:string,description:string,sort:int}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, slug, name, description, sort FROM %i WHERE slug = %s',
            $this->boardsTable(),
            $slug
        ));

        return $row;
    }

    /** The first board in menu order — the default for new threads. */
    public function defaultBoardId(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i ORDER BY sort, id LIMIT 1', $this->boardsTable()));

        return (int) $id;
    }

    /**
     * Creates a board and returns its id.
     *
     * @return int|WP_Error
     */
    public function createBoard(string $slug, string $name, string $description = '', int $sort = 0): int|WP_Error
    {
        $slug = $this->normalizeSlug($slug);
        $name = trim($name);
        if ($slug === '') {
            return new WP_Error('aiya_invalid_param', __('The board slug must be lowercase letters, digits, dashes or underscores.', 'aiya-core'), ['status' => 400]);
        }
        if ($name === '') {
            return new WP_Error('aiya_invalid_param', __('The board name is required.', 'aiya-core'), ['status' => 400]);
        }
        if ($this->boardBySlug($slug) !== null) {
            return new WP_Error('aiya_board_exists', __('A board with this slug already exists.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert(
            $this->boardsTable(),
            [
                'slug' => $slug,
                'name' => mb_substr(trim($name), 0, 100),
                'description' => mb_substr(trim($description), 0, 255),
                'sort' => $sort,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The board could not be stored.', 'aiya-core'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Updates name / description / sort. Absent fields stay untouched.
     *
     * @param array{name?:string, description?:string, sort?:int} $fields
     * @return true|WP_Error
     */
    public function updateBoard(int $boardId, array $fields): bool|WP_Error
    {
        if ($this->boardById($boardId) === null) {
            return new WP_Error('aiya_not_found', __('Board not found.', 'aiya-core'), ['status' => 404]);
        }

        $data = [];
        if (array_key_exists('name', $fields)) {
            $name = trim((string) $fields['name']);
            if ($name === '') {
                return new WP_Error('aiya_invalid_param', __('The board name is required.', 'aiya-core'), ['status' => 400]);
            }
            $data['name'] = mb_substr($name, 0, 100);
        }
        if (array_key_exists('description', $fields)) {
            $data['description'] = mb_substr(trim((string) $fields['description']), 0, 255);
        }
        if (array_key_exists('sort', $fields)) {
            $data['sort'] = (int) $fields['sort'];
        }

        if ($data === []) {
            return true;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $updated = $wpdb->update($this->boardsTable(), $data, ['id' => $boardId]);
        if ($updated === false) {
            return new WP_Error('aiya_db_error', __('The board could not be updated.', 'aiya-core'));
        }

        return true;
    }

    /**
     * Deletes a board and moves its threads to the first remaining
     * board. The last board standing refuses to go.
     *
     * @return true|WP_Error
     */
    public function deleteBoard(int $boardId): bool|WP_Error
    {
        if ($this->boardById($boardId) === null) {
            return new WP_Error('aiya_not_found', __('Board not found.', 'aiya-core'), ['status' => 404]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $boards = $this->boardsTable();
        $threads = $this->threadsTable();
        $remaining = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE id != %d ORDER BY sort, id LIMIT 1',
            $boards,
            $boardId
        ));
        if ($remaining === null) {
            return new WP_Error('aiya_board_last', __('The last board cannot be deleted.', 'aiya-core'), ['status' => 400]);
        }

        $sql = $wpdb->prepare(
            'UPDATE %i SET board_id = %d WHERE board_id = %d',
            $threads,
            (int) $remaining,
            $boardId
        );
        // $sql is built through prepare() right above; the variable pass trips the sniff only.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared two lines up.
        $moved = is_string($sql) ? $wpdb->query($sql) : false;
        if ($moved === false) {
            return new WP_Error('aiya_db_error', __('The board threads could not be moved.', 'aiya-core'));
        }
        $deleted = $wpdb->delete($boards, ['id' => $boardId], ['%d']);
        if ($deleted === false) {
            return new WP_Error('aiya_db_error', __('The board could not be deleted.', 'aiya-core'));
        }

        return true;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return preg_match('/^[a-z0-9_-]{1,50}$/', $slug) === 1 ? $slug : '';
    }

    /**
     * Adds a reply: locked threads refuse (WP_Error carrying 409 data)
     * and counters resync. With the two-state status there is no reply
     * -driven transition — only `closed` changes anything, via the
     * moderation edit path.
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
            return new WP_Error('aiya_invalid_user', __('The replying user does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('aiya_invalid_param', __('The reply body is required.', 'aiya-core'), ['status' => 400]);
        }
        $content = wp_kses_post($content);
        if (DiscussionContent::imageCount($content) > DiscussionContent::MAX_IMAGES) {
            return new WP_Error('aiya_invalid_param', __('A reply can carry at most nine images.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $this->repliesTable(),
            [
                'thread_id' => $threadId,
                'user_id' => $userId,
                'content' => $content,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The reply could not be stored.', 'aiya-core'));
        }

        $this->syncReplyStats($threadId);
        do_action('aiya_core_thread_replied', $threadId, (int) $wpdb->insert_id, $userId);

        return (int) $wpdb->insert_id;
    }

    /**
     * Updates title / content / board / status. Only the author or an
     * administrator may act; anything absent stays untouched.
     *
     * @param array{title?:string, content?:string, board?:int, status?:string} $fields
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
            // Optional title: an empty string clears it (content-only card).
            $data['title'] = mb_substr(trim((string) $fields['title']), 0, self::TITLE_LENGTH);
        }
        if (array_key_exists('content', $fields)) {
            $content = (string) $fields['content'];
            if (trim(wp_strip_all_tags($content)) === '') {
                return new WP_Error('aiya_invalid_param', __('The thread body is required.', 'aiya-core'), ['status' => 400]);
            }
            $content = wp_kses_post($content);
            if (DiscussionContent::imageCount($content) > DiscussionContent::MAX_IMAGES) {
                return new WP_Error('aiya_invalid_param', __('A thread can carry at most nine images.', 'aiya-core'), ['status' => 400]);
            }
            $data['content'] = $content;
        }
        if (array_key_exists('board', $fields)) {
            $boardId = (int) $fields['board'];
            if ($this->boardById($boardId) === null) {
                return new WP_Error('aiya_invalid_param', __('Unknown board.', 'aiya-core'), ['status' => 400]);
            }
            $data['board_id'] = $boardId;
        }
        if (array_key_exists('status', $fields)) {
            $status = (string) $fields['status'];
            if (!ThreadStatus::isValid($status)) {
                return new WP_Error('aiya_invalid_param', __('Unknown thread status.', 'aiya-core'), ['status' => 400]);
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

    /**
     * Updates a reply body: the author or an administrator, non-empty,
     * kses-filtered, at most nine images. Counters are untouched — a body
     * edit changes no reply stats.
     *
     * @return true|WP_Error
     */
    public function updateReply(int $replyId, int $actorId, string $content): bool|WP_Error
    {
        $reply = $this->replyById($replyId);
        if ($reply === null) {
            return new WP_Error('aiya_not_found', __('Reply not found.', 'aiya-core'), ['status' => 404]);
        }
        if (!$this->canModerate((int) $reply->user_id, $actorId)) {
            return new WP_Error('aiya_forbidden', __('You are not allowed to edit this reply.', 'aiya-core'), ['status' => 403]);
        }
        if (trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('aiya_invalid_param', __('The reply body is required.', 'aiya-core'), ['status' => 400]);
        }
        $content = wp_kses_post($content);
        if (DiscussionContent::imageCount($content) > DiscussionContent::MAX_IMAGES) {
            return new WP_Error('aiya_invalid_param', __('A reply can carry at most nine images.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $updated = $wpdb->update(
            $this->repliesTable(),
            ['content' => $content, 'updated_at' => current_time('mysql', true)],
            ['id' => $replyId]
        );
        if ($updated === false) {
            return new WP_Error('aiya_db_error', __('The reply could not be updated.', 'aiya-core'));
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
            return new WP_Error('aiya_invalid_param', __('The bound content does not exist or cannot carry discussions.', 'aiya-core'), ['status' => 400]);
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

    private function boardsTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_discussion_boards';
    }

    /**
     * Creates the three community tables in their final shape and seeds
     * the three default boards; the clean-release migration callback.
     * dbDelta fails silently on transient DB hiccups, so every table is
     * verified afterwards and the runner holds the version back on
     * failure (the next request retries).
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $boards = $wpdb->prefix . 'aiya_discussion_boards';
        dbDelta(
            "CREATE TABLE $boards (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                sort INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) $charset;"
        );

        // The three default boards seed once, on the empty table.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name interpolation
        $seeded = (int) $wpdb->get_var("SELECT COUNT(id) FROM $boards");
        if ($seeded === 0) {
            $now = current_time('mysql', true);
            $wpdb->insert($boards, ['slug' => 'discussion', 'name' => '讨论', 'sort' => 1, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
            $wpdb->insert($boards, ['slug' => 'question', 'name' => '问答', 'sort' => 2, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
            $wpdb->insert($boards, ['slug' => 'feedback', 'name' => '反馈', 'sort' => 3, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
        }

        $threads = $wpdb->prefix . 'aiya_discussions';
        dbDelta(
            "CREATE TABLE $threads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                board_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
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
                KEY board_id (board_id),
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

        foreach ([$boards, $threads, $replies] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                throw new \RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }
    }
}
