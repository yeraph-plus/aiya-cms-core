<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use WP_Comment;
use WP_Comment_Query;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Comment read/write for the headless front end
 * (`aiya/core/v1/content/{id}/comments`), covering every public
 * commentable type (post, page, resource). Identity follows the site's
 * "users must be logged in to comment" switch: logged-in writers ride
 * the session; guests pass only when `comment_registration` is off and
 * then answer through the native name/email fields. Writes go through
 * wp_new_comment, so the classic pipeline applies: flood throttle,
 * duplicate check, the disallowed list and the moderation decision. Only
 * approved comments are ever listed; held ones answer with their queue
 * status instead of a silent 404-style drop. Bodies carry kses'd
 * restricted HTML so the shared tiptap editor (and its uploaded images)
 * survives storage; the read path re-runs the whitelist.
 */
final class CommentsController
{
    /** Types that may carry comments; matches the counter feature matrix. */
    private const COMMENTABLE_TYPES = ['post', 'page', 'resource'];

    private const MAX_BODY_LENGTH = 5000;
    private const MAX_BODY_RAW_LENGTH = 20000;
    private const HITS = 5;
    private const WINDOW = 10 * MINUTE_IN_SECONDS;

    /**
     * Page size for a comment request that carries none: the site's own
     * discussion setting, clamped to this API's 1–100 ceiling — the same
     * "the option is the default, the caller wins" rule the content
     * lists follow (ContentController::defaultPerPage).
     */
    public static function defaultPerPage(): int
    {
        return min(100, max(1, (int) get_option('comments_per_page', 20)));
    }

    /**
     * Window direction for a request that carries none: the site's
     * "default comments page" switch — `newest` means page 1 opens on
     * the newest window, which is this route's `desc`.
     */
    public static function defaultOrder(): string
    {
        return (string) get_option('default_comments_page', 'newest') === 'oldest' ? 'asc' : 'desc';
    }

    /**
     * Rich-comment whitelist (2026-09-17 batch): comment bodies ride as
     * restricted HTML so the shared tiptap editor and its uploaded images
     * survive storage. kses runs at write AND read (idempotent, and it
     * keeps legacy plain-text rows on the same path); the front end runs
     * its own sanitizer on top as defense in depth.
     */
    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'blockquote' => [],
        'code' => [],
        'span' => ['data-spoiler' => true],
        'img' => ['src' => true, 'alt' => true, 'class' => true, 'loading' => true],
    ];

    public function __construct(
        private RateLimiter $rateLimiter,
        private readonly SmiliesRenderer $smilies,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/comments', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->list($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => [
                    'type' => 'integer',
                    'default' => self::defaultPerPage(),
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                // Display window direction: `desc` makes page 1 the newest
                // window (WP's default_comments_page=newest shape); the
                // default mirrors the site's own switch.
                'order' => [
                    'type' => 'string',
                    'default' => self::defaultOrder(),
                    'enum' => ['asc', 'desc'],
                ],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/comments', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->create($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'body' => ['type' => 'string', 'required' => true, 'maxLength' => self::MAX_BODY_RAW_LENGTH],
                // 0/omitted = top-level comment (no parent).
                'parentId' => ['type' => 'integer', 'required' => false, 'minimum' => 0],
                // Guest identity (comment_registration off): the session
                // stays the identity for logged-in writers and these are
                // ignored for them.
                'authorName' => ['type' => 'string', 'required' => false, 'maxLength' => 245],
                'authorEmail' => ['type' => 'string', 'required' => false, 'maxLength' => 254],
            ],
        ]);
    }

    private function list(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        if ($this->publicPost($postId) === null) {
            return $this->notFound();
        }

        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');

        $countQuery = new WP_Comment_Query([
            'post_id' => $postId,
            'status' => 'approve',
            'count' => true,
        ]);
        $total = (int) ($countQuery->total_comments ?? 0);

        $query = new WP_Comment_Query([
            'post_id' => $postId,
            'status' => 'approve',
            'order' => (string) $request->get_param('order') === 'desc' ? 'DESC' : 'ASC',
            'number' => min(100, max(1, $perPage)),
            'paged' => max(1, $page),
        ]);

        $items = [];
        foreach (is_array($query->comments) ? $query->comments : [] as $comment) {
            if ($comment instanceof WP_Comment) {
                $items[] = $this->present($comment);
            }
        }

        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $total)->toArray(),
            ],
        ]);
    }

    private function create(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('comment', self::HITS, self::WINDOW)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }

        $postId = (int) $request->get_param('id');
        $post = $this->publicPost($postId);
        if ($post === null) {
            return $this->notFound();
        }
        if (!comments_open($postId)) {
            return new WP_Error('aiya_comments_closed', __('Comments are closed for this content.', 'aiya-core'), ['status' => 403]);
        }

        // Bodies ride as restricted HTML (the shared tiptap composer); the
        // cap applies to the visible text so a wall of markup cannot buy
        // extra room, plus a raw ceiling for the markup itself.
        $raw = trim((string) $request->get_param('body'));
        if ($raw === '' || mb_strlen($raw) > self::MAX_BODY_RAW_LENGTH) {
            return new WP_Error(
                'aiya_invalid_param',
                __('The comment is too long.', 'aiya-core'),
                ['status' => 400]
            );
        }
        $visible = trim(wp_strip_all_tags($raw));
        if ($visible === '' || mb_strlen($visible) > self::MAX_BODY_LENGTH) {
            return new WP_Error(
                'aiya_invalid_param',
                sprintf(
                    /* translators: %d: maximum comment length. */
                    __('The comment must be 1-%d characters long.', 'aiya-core'),
                    self::MAX_BODY_LENGTH
                ),
                ['status' => 400]
            );
        }

        $parentId = (int) $request->get_param('parentId');
        if ($parentId > 0) {
            $parent = get_comment($parentId);
            if (!$parent instanceof WP_Comment
                || (int) $parent->comment_post_ID !== $postId
                || (string) $parent->comment_approved !== '1') {
                return new WP_Error('aiya_invalid_parent', __('The commented reply does not exist.', 'aiya-core'), ['status' => 400]);
            }
        }

        // Identity: the session is the whole identity for logged-in
        // writers; guests only pass when the site's "users must be logged
        // in to comment" switch is off, and then the native name/email
        // fields (with require_name_email) stand in for the session.
        $user = wp_get_current_user();
        $loggedIn = $user instanceof WP_User && $user->ID > 0;
        if (!$loggedIn) {
            if ((bool) get_option('comment_registration', false)) {
                return new WP_Error('aiya_login_required', __('Please log in to comment.', 'aiya-core'), ['status' => 401]);
            }
            $authorName = sanitize_text_field((string) $request->get_param('authorName'));
            $authorEmail = sanitize_email((string) $request->get_param('authorEmail'));
            if ((bool) get_option('require_name_email', true)
                && ($authorName === '' || $authorEmail === '' || !is_email($authorEmail))) {
                return new WP_Error(
                    'aiya_identity_required',
                    __('Please provide your name and a valid email address to comment.', 'aiya-core'),
                    ['status' => 400]
                );
            }
        } else {
            $authorName = (string) $user->display_name;
            $authorEmail = (string) $user->user_email;
        }

        // Core's own comment kses (`wp_filter_kses` → the tag-poor global
        // $allowedtags, no img) hooks pre_comment_content for
        // non-privileged authors and would strip the uploaded-image markup
        // right after our stricter whitelist already sanitized it. It
        // steps aside for this one write and is restored immediately.
        $coreKsesActive = remove_filter('pre_comment_content', 'wp_filter_kses');

        $commentId = wp_new_comment([
            'comment_post_ID' => $postId,
            'comment_parent' => $parentId,
            'comment_author' => $authorName,
            'comment_author_email' => $authorEmail,
            'comment_author_url' => '',
            'comment_content' => wp_kses($raw, self::ALLOWED_TAGS),
            'user_id' => $loggedIn ? (int) $user->ID : 0,
            'comment_author_IP' => \Aiya\Core\Infrastructure\Http\ClientIp::forVisitor(),
            'comment_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'comment_date' => current_time('mysql'),
        ], true);

        if ($coreKsesActive) {
            add_filter('pre_comment_content', 'wp_filter_kses');
        }

        if (is_wp_error($commentId)) {
            $code = $commentId->get_error_code();
            if ($code === 'comment_duplicate') {
                return new WP_Error('aiya_duplicate_comment', __('The comment could not be posted.', 'aiya-core'), ['status' => 409]);
            }
            if ($code === 'comment_flood') {
                // Native flood control (comment_flood_threshold seconds
                // between comments by the same author).
                return new WP_Error('aiya_comment_flood', __('You are commenting too quickly. Slow down.', 'aiya-core'), ['status' => 429]);
            }

            return new WP_Error('aiya_comment_rejected', __('The comment could not be posted.', 'aiya-core'), ['status' => 409]);
        }
        if (!is_int($commentId) || $commentId <= 0) {
            return new WP_Error('aiya_comment_rejected', __('The comment could not be posted.', 'aiya-core'), ['status' => 500]);
        }

        $comment = get_comment($commentId);
        $approved = $comment instanceof WP_Comment && (string) $comment->comment_approved === '1';

        return new WP_REST_Response([
            'created' => true,
            'id' => $commentId,
            'status' => $approved ? 'approved' : 'held',
        ]);
    }

    /**
     * Resolves a public, non-protected post of a commentable type;
     * anything else answers 404 like content that does not exist.
     */
    private function publicPost(int $id): ?WP_Post
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post
            || !in_array($post->post_type, self::COMMENTABLE_TYPES, true)
            || $post->post_status !== 'publish'
            || (string) $post->post_password !== '') {
            return null;
        }

        return $post;
    }

    /** @return array<string, mixed> */
    private function present(WP_Comment $comment): array
    {
        $authorId = (int) $comment->user_id;
        $avatar = get_avatar_url($authorId > 0 ? $authorId : (string) $comment->comment_author_email, ['size' => 64]);

        $publishedAt = mysql2date('c', (string) $comment->comment_date, false);

        return [
            'id' => (int) $comment->comment_ID,
            'parentId' => (int) $comment->comment_parent > 0 ? (int) $comment->comment_parent : null,
            'author' => [
                'id' => $authorId,
                'name' => (string) $comment->comment_author,
                'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
            ],
            'body' => (string) $comment->comment_content,
            // Storage carries kses'd restricted HTML (legacy rows are the
            // plain text they always were); the read re-runs the whitelist
            // and lets the renderer inject exactly its whitelisted smilies
            // imgs — the state machine only touches text nodes. `body`
            // stays the source form.
            'bodyHtml' => $this->smilies->render(wp_kses((string) $comment->comment_content, self::ALLOWED_TAGS)),
            'publishedAt' => is_string($publishedAt) ? $publishedAt : '',
        ];
    }

    private function notFound(): WP_Error
    {
        return new WP_Error('aiya_not_found', __('Content not found.', 'aiya-core'), ['status' => 404]);
    }
}
