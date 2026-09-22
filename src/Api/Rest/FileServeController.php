<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\FilePresenter;
use Aiya\Core\Domain\FileServe\DownloadService;
use Aiya\Core\Domain\FileServe\FileService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A content item's file lists and their claims.
 *
 * The read is public and viewer-independent — rows carry an opaque ref, never
 * a link — so the only gate is the post's own: a gated or unknown post answers
 * 404 either way, exactly like its comment thread.
 *
 * The claim is where a link appears, and it is where the credits move: the
 * domain prices the row, charges it through the ledger and hands the link over
 * once the charge stands. Free lists come through the same door, so one path
 * serves every delivery.
 */
final class FileServeController
{
    public function __construct(
        private FileService $files,
        private DownloadService $downloads,
        private FilePresenter $presenter,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        $idArg = ['type' => 'integer', 'required' => true, 'minimum' => 1];

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/downloads', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->lists($request),
                'permission_callback' => '__return_true',
                'args' => ['id' => $idArg],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->claim($request),
                'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
                'args' => [
                    'id' => $idArg,
                    'listId' => ['type' => 'string', 'required' => true],
                    'ref' => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);
    }

    private function lists(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $result = $this->files->forPost((int) $request->get_param('id'), (int) get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['lists' => $this->presenter->lists($result['lists'])]);
    }

    private function claim(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->limiter->hit('fileserve_download', 30, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $result = $this->downloads->claim(
            (int) $request->get_param('id'),
            (string) $request->get_param('listId'),
            (string) $request->get_param('ref'),
            (int) get_current_user_id()
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($this->presenter->download($result));
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }
}
