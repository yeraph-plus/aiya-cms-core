<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Notification;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Notification\RoleLevel;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Notification feed: broadcast rows gated by the viewer's role rank
 * (guests receive only guest-level notices) plus the viewer's targeted
 * interaction rows. Bearer sessions set the current user through
 * TokenAuthentication, so the endpoint is a plain public read that
 * personalizes itself from the resolved viewer. Paged like the other
 * list routes: `page`/`perPage` with the standard meta.pagination block,
 * newest first, 50-per-page default.
 */
final class NotificationController
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private NotificationService $notifications,
        private UserPresenter $users,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/notifications', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->list($request),
            'permission_callback' => '__return_true',
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => [
                    'type' => 'integer',
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                ],
            ],
        ]);
    }

    private function list(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();
        $loggedIn = $user->exists() && (int) $user->ID > 0;
        $rank = RoleLevel::rank($loggedIn ? $this->users->role($user) : RoleLevel::GUEST);
        $viewerId = $loggedIn ? (int) $user->ID : 0;

        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('perPage')));
        $total = $this->notifications->countVisible($rank, $viewerId);

        $items = [];
        foreach ($this->notifications->visible($rank, $viewerId, $perPage, ($page - 1) * $perPage) as $row) {
            $items[] = (new Notification(
                (int) $row->id,
                (string) $row->type,
                (string) $row->title,
                (string) $row->body,
                $this->isoCreatedAt((string) $row->created_at)
            ))->toArray();
        }

        return new WP_REST_Response([
            'items' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $total)->toArray(),
            ],
        ]);
    }

    /** created_at is stored GMT; the contract wants offset ISO 8601. */
    private function isoCreatedAt(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
