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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Public read routes of the content batch (`/site`, `/menus/primary`,
 * `/menus/secondary`, `/terms`, `/posts`, `/posts/{id}`, `/pages`,
 * `/pages/{id}`, `/resources`, `/resources/{id}`, `/profiles/{slug}`).
 * Controllers only orchestrate: queries run in Domain, mapping in the
 * presenter, envelope in the dispatcher.
 */
final class ContentController
{
    public function __construct(
        private ContentQuery $query,
        private PostPresenter $posts,
        private SitePresenter $site,
        private PrimaryMenu $menus,
        private ProfilePresenter $profiles,
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
                'sort' => ['type' => 'string', 'default' => 'newest', 'enum' => ['newest', 'oldest']],
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
            (string) $request->get_param('sort')
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
