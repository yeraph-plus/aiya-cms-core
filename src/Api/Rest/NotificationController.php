<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Notification;
use Aiya\Core\Api\Presenter\UserPresenter;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Notification\RoleLevel;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public notification feed: broadcast rows gated by the viewer's role rank
 * (guests receive only guest-level notices) plus the viewer's targeted
 * rows once the interaction kinds arrive. Bearer sessions set the current
 * user through TokenAuthentication, so the endpoint is a plain public read
 * that personalizes itself from the resolved viewer.
 */
final class NotificationController
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private NotificationService $notifications,
        private UserPresenter $users,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/notifications', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->list(),
            'permission_callback' => '__return_true',
        ]);
    }

    private function list(): WP_REST_Response
    {
        $user = wp_get_current_user();
        $loggedIn = $user->exists() && (int) $user->ID > 0;
        $rank = RoleLevel::rank($loggedIn ? $this->users->role($user) : RoleLevel::GUEST);

        $items = [];
        foreach ($this->notifications->visible($rank, $loggedIn ? (int) $user->ID : 0, self::MAX_ITEMS) as $row) {
            $items[] = (new Notification(
                (int) $row->id,
                (string) $row->type,
                (string) $row->title,
                (string) $row->body,
                $this->isoCreatedAt((string) $row->created_at)
            ))->toArray();
        }

        return new WP_REST_Response(['items' => $items]);
    }

    /** created_at is stored GMT; the contract wants offset ISO 8601. */
    private function isoCreatedAt(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
