<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\ProfilePresenter;
use Aiya\Core\Api\Presenter\SitePresenter;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PrimaryMenu;
use Aiya\Core\Domain\Content\PublicTypes;
use Aiya\Core\Domain\Content\RelatedPostsQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Public read routes of the content batch (`/site`, `/menus/primary`,
 * `/menus/secondary`, `/terms`, `/posts`, `/posts/{id}`, `/pages`,
 * `/pages/{id}`, `/resources`, `/resources/{id}`, `/profiles/{slug}`)
 * plus the password gate (`POST /content/{id}/unlock`) for locked
 * bodies and the shared-term related reads (`GET /content/{id}/related`).
 * Controllers only orchestrate: queries run in Domain, mapping in the
 * presenter, envelope in the dispatcher.
 */
final class ContentController
{
    private const UNLOCK_HITS = 10;
    private const UNLOCK_WINDOW = 10 * MINUTE_IN_SECONDS;

    public function __construct(
        private ContentQuery $query,
        private RelatedPostsQuery $related,
        private PostPresenter $posts,
        private SitePresenter $site,
        private PrimaryMenu $menus,
        private ProfilePresenter $profiles,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/site', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => new WP_REST_Response($this->site->present()->toArray()),
            'permission_callback' => '__return_true',
        ]);

        $this->registerMenuRoute(PrimaryMenu::GROUP_PRIMARY);
        $this->registerMenuRoute(PrimaryMenu::GROUP_SECONDARY);

        register_rest_route(Contract::API_NAMESPACE, '/terms', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->terms($request),
            'permission_callback' => '__return_true',
            'args' => [
                'taxonomy' => ['type' => 'string', 'required' => true, 'enum' => ['category', 'tag']],
                'type' => ['type' => 'string', 'default' => 'post', 'enum' => ['post', 'page', 'resource']],
            ],
        ]);

        $this->registerTypeRoutes('posts', 'post');
        $this->registerTypeRoutes('pages', 'page');
        $this->registerTypeRoutes('resources', 'resource');

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/unlock', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->unlock($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'password' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/related', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->related($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'number' => ['type' => 'integer', 'default' => RelatedPostsQuery::DEFAULT_NUMBER, 'minimum' => 1, 'maximum' => RelatedPostsQuery::MAX_NUMBER],
                'days' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => RelatedPostsQuery::MAX_DAYS],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/profiles/(?P<slug>[a-z0-9-]{1,64})', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->profile($request),
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /**
     * Registers one menu group read route; the response `location` mirrors
     * the group key so the front end can reuse one schema per group.
     *
     * @param PrimaryMenu::GROUP_* $group
     */
    private function registerMenuRoute(string $group): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/menus/' . $group, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => new WP_REST_Response([
                'location' => $group,
                'items' => array_map(static fn ($item): array => $item->toArray(), $this->menus->group($group)),
            ]),
            'permission_callback' => '__return_true',
        ]);
    }

    /** Registers the list + detail pair for one public type. */
    private function registerTypeRoutes(string $path, string $typeName): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/' . $path, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request) => $this->list($request, $typeName),
            'permission_callback' => '__return_true',
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 100],
                'q' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'category' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'tag' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'author' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'sort' => ['type' => 'string', 'default' => 'newest', 'enum' => ['newest', 'oldest', 'rand']],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/' . $path . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request) => $this->detail($request, $typeName),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
            ],
        ]);
    }

    private function terms(WP_REST_Request $request): WP_REST_Response
    {
        $type = PublicTypes::get((string) $request->get_param('type')) ?? PublicTypes::get('post');
        if ($type === null) {
            return new WP_REST_Response([]);
        }

        return new WP_REST_Response($this->posts->presentTerms($type, (string) $request->get_param('taxonomy')));
    }

    private function list(WP_REST_Request $request, string $typeName): WP_REST_Response
    {
        $type = PublicTypes::get($typeName);
        if ($type === null) {
            return new WP_REST_Response(['data' => [], 'meta' => Envelope::meta()]);
        }

        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');
        $result = $this->query->list(
            $type,
            $page,
            $perPage,
            sanitize_text_field((string) $request->get_param('q')),
            sanitize_title((string) $request->get_param('category')),
            (string) $request->get_param('sort'),
            sanitize_title((string) $request->get_param('author')),
            sanitize_title((string) $request->get_param('tag'))
        );

        $items = [];
        foreach ($result['items'] as $post) {
            $items[] = $this->posts->summary($post, $type)->toArray();
        }

        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $result['total'])->toArray(),
            ],
        ]);
    }

    private function detail(WP_REST_Request $request, string $typeName): WP_Error|WP_REST_Response
    {
        $type = PublicTypes::get($typeName);
        if ($type === null) {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        $post = $this->query->byId((int) $request->get_param('id'), $type);
        if ($post === null) {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        return new WP_REST_Response(
            $this->posts->detail($post, $this->query->neighbors($post, $type), $type)->toArray()
        );
    }

    /**
     * Password gate for a locked body: verifies against the WP hash and
     * plants the native post-password cookie, so the very next detail
     * read answers the full body through core's own machinery. No
     * credential-level brute force here — the rate limiter is the
     * attempt budget.
     */
    private function unlock(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->limiter->hit('content-unlock', self::UNLOCK_HITS, self::UNLOCK_WINDOW)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }

        // Any public type can carry the password gate; the first match wins.
        $post = null;
        foreach (PublicTypes::all() as $type) {
            $candidate = $this->query->byId((int) $request->get_param('id'), $type);
            if ($candidate !== null) {
                $post = $candidate;
                break;
            }
        }
        if ($post === null || (string) $post->post_password === '') {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        // post_password stores the plain password (core semantics); the
        // comparison is constant-time. The native cookie stores a phpass
        // hash OF that password ($P$B…), and post_password_required()
        // re-checks it via CheckPassword on every later read.
        $password = (string) $request->get_param('password');
        if (!hash_equals((string) $post->post_password, $password)) {
            return new WP_Error('aiya_wrong_password', __('Incorrect password.', 'aiya-core'), ['status' => 403]);
        }

        require_once ABSPATH . 'wp-includes/class-phpass.php';
        $hasher = new \PasswordHash(8, true);
        setcookie(
            'wp-postpass_' . \COOKIEHASH,
            $hasher->HashPassword($password),
            [
                'expires' => time() + 10 * DAY_IN_SECONDS,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        return new WP_REST_Response(['unlocked' => true]);
    }

    /**
     * Shared-term related reads for any public type: the origin resolves
     * through the same visibility rules as the detail route (first
     * matching type wins), results present as PostSummary rows.
     */
    private function related(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $post = null;
        $type = null;
        foreach (PublicTypes::all() as $candidate) {
            $found = $this->query->byId((int) $request->get_param('id'), $candidate);
            if ($found !== null) {
                $post = $found;
                $type = $candidate;
                break;
            }
        }
        if ($post === null || $type === null) {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        $items = [];
        foreach ($this->related->forPost(
            $post,
            $type,
            (int) $request->get_param('number'),
            (int) $request->get_param('days')
        ) as $row) {
            $items[] = $this->posts->summary($row, $type)->toArray();
        }

        return new WP_REST_Response($items);
    }

    private function profile(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $user = get_user_by('slug', (string) $request->get_param('slug'));
        if (!$user instanceof WP_User) {
            return $this->notFound(__('Profile not found.', 'aiya-core'));
        }

        return new WP_REST_Response($this->profiles->present($user)->toArray());
    }

    private function notFound(string $message): WP_Error
    {
        return new WP_Error('aiya_not_found', $message, ['status' => 404]);
    }
}
