<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\LikeResponse;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Domain\Discussion\DiscussionLikeService;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Community thread routes of the versioned API: public reads with
 * board/status/post/user filters plus keyword and #tag search, and
 * bearer-session writes throttled by the shared rate limiter. Likes ride
 * a dedicated relation table (0.102.0) beside the reply counter; closed
 * threads refuse new likes. Boards are the customizable classification
 * (0.45.0); a thread's board is addressed by slug on create/update.
 */
final class DiscussionController
{
    private const REPLIES_PER_PAGE = 50;

    public function __construct(
        private DiscussionService $threads,
        private DiscussionPresenter $presenter,
        private DiscussionLikeService $likes,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/discussions/boards', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->boards(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->list($request),
            'permission_callback' => '__return_true',
            'args' => [
                'status' => ['type' => 'string', 'default' => '', 'enum' => ['', ...ThreadStatus::ALL]],
                'board' => ['type' => 'string', 'default' => '', 'maxLength' => 50],
                'q' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'tag' => ['type' => 'string', 'default' => '', 'maxLength' => 50],
                'post' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'user' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'sort' => ['type' => 'string', 'default' => 'last_activity', 'enum' => ['last_activity', 'newest']],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->create($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'title' => ['type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 191],
                'content' => ['type' => 'string', 'required' => true, 'maxLength' => 20000],
                'board' => ['type' => 'string', 'default' => '', 'maxLength' => 50],
                'postId' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/replies', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->replies($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/replies', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->addReply($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'content' => ['type' => 'string', 'required' => true, 'maxLength' => 10000],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->update($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'title' => ['type' => 'string', 'required' => false, 'maxLength' => 191],
                'content' => ['type' => 'string', 'required' => false, 'maxLength' => 20000],
                'board' => ['type' => 'string', 'required' => false, 'maxLength' => 50],
                'status' => ['type' => 'string', 'required' => false, 'enum' => ThreadStatus::ALL],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->delete($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/like', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->like($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/like', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->unlike($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/replies/(?P<replyId>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->updateReply($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'replyId' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'content' => ['type' => 'string', 'required' => true, 'maxLength' => 10000],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/discussions/(?P<id>\d+)/replies/(?P<replyId>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->deleteReply($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'replyId' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
            ],
        ]);
    }

    /** The public board list with thread counts, menu order. */
    private function boards(): WP_REST_Response
    {
        return Envelope::payload($this->presenter->boards($this->threads->boards()));
    }

    private function list(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');
        $boardSlug = (string) $request->get_param('board');
        $board = $boardSlug !== '' ? $this->threads->boardBySlug($boardSlug) : null;
        $result = $this->threads->list(
            (string) $request->get_param('status'),
            (int) $request->get_param('post'),
            (int) $request->get_param('user'),
            (string) $request->get_param('sort'),
            $page,
            $perPage,
            (string) $request->get_param('q'),
            $board !== null ? (int) $board->id : ($boardSlug !== '' ? -1 : 0),
            (string) $request->get_param('tag'),
        );

        $viewer = (int) get_current_user_id();
        $items = [];
        foreach ($this->presenter->presentAll($result['items'], $viewer) as $thread) {
            $items[] = $thread->toArray();
        }

        return Envelope::payload($items, Pagination::fromCounts($page, $perPage, $result['total']));
    }

    private function create(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hitFor('discussion_create', $userId, 5, 3600)) {
            return RestGuard::rateLimited();
        }

        $boardId = $this->resolveBoardId((string) $request->get_param('board'));
        if (is_wp_error($boardId)) {
            return $boardId;
        }

        $threadId = $this->threads->create(
            $userId,
            (string) $request->get_param('title'),
            (string) $request->get_param('content'),
            $boardId,
            (int) $request->get_param('postId'),
        );
        if (is_wp_error($threadId)) {
            return $threadId;
        }

        $thread = $this->threads->byId($threadId);
        if ($thread === null) {
            return new WP_Error('aiya_server_error', __('The thread could not be read back.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response($this->presenter->detail(
            $thread,
            [],
            $userId,
        )->toArray());
    }

    private function replies(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $threadId = (int) $request->get_param('id');
        if ($this->threads->byId($threadId) === null) {
            return $this->notFound();
        }

        $page = (int) $request->get_param('page');
        $viewer = (int) get_current_user_id();
        $result = $this->threads->replies($threadId, $page, self::REPLIES_PER_PAGE);

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = $this->presenter->reply($row, $viewer)->toArray();
        }

        return Envelope::payload($items, Pagination::fromCounts($page, self::REPLIES_PER_PAGE, $result['total']));
    }

    private function addReply(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hitFor('discussion_reply', $userId, 30, 600)) {
            return RestGuard::rateLimited();
        }

        $replyId = $this->threads->reply((int) $request->get_param('id'), $userId, (string) $request->get_param('content'));
        if (is_wp_error($replyId)) {
            return $replyId;
        }

        $reply = $this->threads->replyById($replyId);
        if ($reply === null) {
            return new WP_Error('aiya_server_error', __('The reply could not be read back.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response($this->presenter->reply($reply, $userId)->toArray());
    }

    private function update(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $fields = [];
        foreach (['title', 'content', 'status'] as $field) {
            if ($request->offsetExists($field)) {
                $fields[$field] = (string) $request->get_param($field);
            }
        }
        if ($request->offsetExists('board')) {
            $boardId = $this->resolveBoardId((string) $request->get_param('board'));
            if (is_wp_error($boardId)) {
                return $boardId;
            }
            $fields['board'] = $boardId;
        }

        $updated = $this->threads->update((int) $request->get_param('id'), $userId, $fields);
        if (is_wp_error($updated)) {
            return $updated;
        }

        $thread = $this->threads->byId((int) $request->get_param('id'));
        if ($thread === null) {
            return $this->notFound();
        }

        // First reply page plus the REAL pagination: a thread with more
        // than one page of replies states so in meta (hasNext/totalItems)
        // instead of silently shipping a truncated list — deeper pages
        // are the replies endpoint's job.
        $replies = $this->threads->replies((int) $thread->id, 1, self::REPLIES_PER_PAGE);
        $replyObjects = [];
        foreach ($replies['items'] as $row) {
            $replyObjects[] = $this->presenter->reply($row, $userId);
        }

        return Envelope::payload(
            $this->presenter->detail($thread, $replyObjects, $userId)->toArray(),
            Pagination::fromCounts(1, self::REPLIES_PER_PAGE, $replies['total'])
        );
    }

    /**
     * Edits one reply body. The route carries both ids; the pair must
     * match so a reply can never be edited through a foreign thread path.
     */
    private function updateReply(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $threadId = (int) $request->get_param('id');
        $replyId = (int) $request->get_param('replyId');

        $reply = $this->threads->replyById($replyId);
        if ($reply === null || (int) $reply->thread_id !== $threadId) {
            return $this->notFound();
        }

        $updated = $this->threads->updateReply($replyId, $userId, (string) $request->get_param('content'));
        if (is_wp_error($updated)) {
            return $updated;
        }

        $fresh = $this->threads->replyById($replyId);
        if ($fresh === null) {
            return new WP_Error('aiya_server_error', __('The reply could not be read back.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response($this->presenter->reply($fresh, $userId)->toArray());
    }

    private function delete(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $deleted = $this->threads->delete((int) $request->get_param('id'), (int) get_current_user_id());
        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return new WP_REST_Response(['deleted' => true]);
    }

    /**
     * Deletes one reply. The route carries both ids; the pair must match,
     * same contract as the edit above — a reply is never reachable through
     * a foreign thread path.
     */
    private function deleteReply(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $reply = $this->threads->replyById((int) $request->get_param('replyId'));
        if ($reply === null || (int) $reply->thread_id !== (int) $request->get_param('id')) {
            return $this->notFound();
        }

        $deleted = $this->threads->deleteReply((int) $request->get_param('replyId'), (int) get_current_user_id());
        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return new WP_REST_Response(['deleted' => true]);
    }

    private function like(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        // Login-gated write: the budget belongs to the acting user, not
        // the egress address (one office IP must not crowd out its users).
        if (!$this->limiter->hitFor('discussion_like', $userId, 30, 60)) {
            return RestGuard::rateLimited();
        }

        $result = $this->likes->like((int) $request->get_param('id'), $userId);
        if (is_wp_error($result)) {
            return $result;
        }

        return Envelope::payload((new LikeResponse(
            $result['likes'],
            $result['viewerLiked'],
            $result['already'] ?? false,
        ))->toArray());
    }

    private function unlike(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hitFor('discussion_like', $userId, 30, 60)) {
            return RestGuard::rateLimited();
        }

        $result = $this->likes->unlike((int) $request->get_param('id'), $userId);
        if (is_wp_error($result)) {
            return $result;
        }

        return Envelope::payload((new LikeResponse(
            $result['likes'],
            $result['viewerLiked'],
        ))->toArray());
    }

    /**
     * A board slug resolves to its id; an empty slug falls back to the
     * first board in menu order; an unknown slug is a 400.
     *
     * @return int|WP_Error
     */
    private function resolveBoardId(string $slug): int|WP_Error
    {
        $slug = trim($slug);
        if ($slug === '') {
            return $this->threads->defaultBoardId();
        }

        $board = $this->threads->boardBySlug($slug);
        if ($board === null) {
            return new WP_Error('aiya_invalid_param', __('Unknown board.', 'aiya-core'), ['status' => 400]);
        }

        return (int) $board->id;
    }


    private function notFound(): WP_Error
    {
        return new WP_Error('aiya_not_found', __('Thread not found.', 'aiya-core'), ['status' => 404]);
    }
}
