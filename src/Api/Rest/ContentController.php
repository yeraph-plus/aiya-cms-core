<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Api\Contract\SearchGroup;
use Aiya\Core\Api\Contract\SearchResult;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\ProfilePresenter;
use Aiya\Core\Api\Presenter\SitePresenter;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PublicType;
use Aiya\Core\Domain\Content\PublicTypes;
use Aiya\Core\Domain\Content\RelatedPostsQuery;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Public read routes of the content batch (`/site`, `/menus/primary`,
 * `/menus/secondary`, `/terms`, `/posts`, `/posts/{slug}`, `/pages`,
 * `/pages/{slug}`, `/resources`, `/resources/{slug}`, `/profiles/{slug}`)
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
        private ProfilePresenter $profiles,
        private RateLimiter $limiter,
    ) {
    }

    /**
     * Page size for a list request that carries none: the site's own reading
     * setting, clamped to this API's 1–100 ceiling. A reading setting of -1
     * is core's "show all posts" — the API cannot express an unbounded list,
     * so the faithful default is the ceiling (not 1).
     *
     * An explicit `perPage` from the caller always wins — `ContentQuery::list()`
     * hands the validated request value straight to WP_Query, so the option is
     * only ever consulted for the default.
     */
    public static function defaultPerPage(): int
    {
        $value = (int) get_option('posts_per_page', 10);
        if ($value < 1) {
            return 100;
        }

        return min(100, $value);
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/site', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => new WP_REST_Response($this->site->presentArray()),
            'permission_callback' => '__return_true',
        ]);


        register_rest_route(Contract::API_NAMESPACE, '/terms', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->terms($request),
            'permission_callback' => '__return_true',
            'args' => [
                // "all" (default) flattens every vocabulary the type maps
                // to the contract groups; category/tag keep filtering.
                'taxonomy' => ['type' => 'string', 'default' => 'all', 'enum' => ['all', 'category', 'tag']],
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
                'slug' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->search($request),
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['type' => 'string', 'required' => true, 'maxLength' => 100],
                // Omitted = grouped cross-type answer (page one per type
                // plus totals); present = one type with full pagination.
                'type' => ['type' => 'string', 'enum' => ['post', 'page', 'resource']],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
            ],
        ]);
    }

    /**
     * Cross-type search (posts / pages / resources). Relevance-ordered
     * (title matches first) through WP's own scoring, visibility
     * exclusions applied by the shared query — gated, passworded and
     * private rows never surface here. Two modes:
     *
     *  - no `type`: grouped SearchResult (page one per type + totals);
     *  - `type` present: one type's full pagination, standard list shape.
     *
     * Short or missing queries answer an empty payload without hitting
     * the database (a one-character box mid-typing is a normal state).
     * LIKE search is the priciest read on the site, hence the limiter.
     */
    private function search(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->limiter->hit('content_search', 30, 60)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $q = trim(sanitize_text_field((string) $request->get_param('q')));
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(50, max(1, (int) $request->get_param('perPage')));
        $typeName = (string) $request->get_param('type');

        if (mb_strlen($q) < 2) {
            if ($typeName !== '') {
                return new WP_REST_Response([
                    'data' => [],
                    'meta' => [
                        'apiVersion' => Contract::VERSION,
                        'requestId' => Envelope::meta()['requestId'],
                        'pagination' => Pagination::fromCounts($page, $perPage, 0)->toArray(),
                    ],
                ]);
            }

            $empty = static fn (): SearchGroup => new SearchGroup([], 0);

            return new WP_REST_Response((new SearchResult($empty(), $empty(), $empty()))->toArray());
        }

        $summarize = fn (array $posts, PublicType $type): array => array_values(array_map(
            fn (WP_Post $post): PostSummary => $this->posts->summary($post, $type),
            $posts
        ));

        if ($typeName !== '') {
            $type = PublicTypes::get($typeName);
            if ($type === null) {
                return new WP_Error('aiya_invalid_param', __('Content not found.', 'aiya-core'), ['status' => 400]);
            }

            $result = $this->query->list($type, $page, $perPage, $q, '', 'relevance');

            return new WP_REST_Response([
                'data' => array_map(
                    static fn (PostSummary $summary): array => $summary->toArray(),
                    $summarize($result['items'], $type)
                ),
                'meta' => [
                    'apiVersion' => Contract::VERSION,
                    'requestId' => Envelope::meta()['requestId'],
                    'pagination' => Pagination::fromCounts($page, $perPage, $result['total'])->toArray(),
                ],
            ]);
        }

        $groups = [];
        foreach (PublicTypes::all() as $name => $type) {
            $result = $this->query->list($type, 1, $perPage, $q, '', 'relevance');
            $groups[$name] = new SearchGroup($summarize($result['items'], $type), $result['total']);
        }

        return new WP_REST_Response((new SearchResult(
            $groups['post'],
            $groups['page'],
            $groups['resource']
        ))->toArray());
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
                'perPage' => [
                    'type' => 'integer',
                    'default' => self::defaultPerPage(),
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'q' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'category' => ['type' => 'string', 'default' => '', 'maxLength' => 200],
                'tag' => ['type' => 'string', 'default' => '', 'maxLength' => 200],
                'author' => ['type' => 'string', 'default' => '', 'maxLength' => 100],
                'sort' => ['type' => 'string', 'default' => 'newest', 'enum' => ['newest', 'oldest', 'rand']],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/' . $path . '/(?P<slug>[^/]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request) => $this->detail($request, $typeName),
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
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
            // Multi-select rides as comma-separated slugs; sanitize_title
            // would fuse them into one hyphenated slug, so only text-level
            // cleanup happens here — per-slug sanitization is slugList's job.
            sanitize_text_field((string) $request->get_param('category')),
            (string) $request->get_param('sort'),
            sanitize_title((string) $request->get_param('author')),
            sanitize_text_field((string) $request->get_param('tag'))
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

        $post = $this->query->bySlug(sanitize_title((string) $request->get_param('slug')), $type);
        if ($post === null) {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        // Adjacency is a posts-only affordance; page/resource details carry
        // the empty pair (the contract field stays, the values stay null).
        $neighbors = $typeName === 'post'
            ? $this->query->neighbors($post, $type)
            : ['previous' => null, 'next' => null];

        return new WP_REST_Response(
            $this->posts->detail($post, $neighbors, $type)->toArray()
        );
    }

    /**
     * Password gate for a locked body: verifies against the WP hash and
     * answers the unlocked detail DIRECTLY in the response. The native
     * postpass cookie is deliberately not used: the browser never talks to
     * WP (the Astro proxy does), so a cookie planted here can never reach
     * the visitor — and each unlock POST carries the password again, which
     * is its own proof. No credential-level brute force here — the rate
     * limiter is the attempt budget.
     */
    private function unlock(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->limiter->hit('content-unlock', self::UNLOCK_HITS, self::UNLOCK_WINDOW)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }

        // Any public type can carry the password gate; the first match wins.
        $post = null;
        $type = null;
        foreach (PublicTypes::all() as $candidateType) {
            $candidate = $this->query->byId((int) $request->get_param('id'), $candidateType);
            if ($candidate !== null) {
                $post = $candidate;
                $type = $candidateType;
                break;
            }
        }
        if ($post === null || $type === null || (string) $post->post_password === '') {
            return $this->notFound(__('Content not found.', 'aiya-core'));
        }

        // post_password stores the plain password (core semantics); the
        // comparison is constant-time. On success the full detail is
        // returned in this response — the front end renders it (and may
        // keep it for the browsing session); the next cold detail read is
        // locked again until the password is presented once more.
        $password = (string) $request->get_param('password');
        if (!hash_equals((string) $post->post_password, $password)) {
            return new WP_Error('aiya_wrong_password', __('Incorrect password.', 'aiya-core'), ['status' => 403]);
        }

        return new WP_REST_Response(
            $this->posts->detailUnlocked($post, $this->query->neighbors($post, $type), $type)->toArray()
        );
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
