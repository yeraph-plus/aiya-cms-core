<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Sponsorship read/self-service routes of the versioned API: the plan list
 * (public, from the domain settings), self-service code redemption and the
 * viewer's own membership state with order history. Gateway integrations
 * (Afdian webhook, Epay cashier/callback) live outside the versioned
 * namespace and arrive in their own slices.
 */
final class SponsorshipController
{
    public function __construct(
        private MembershipService $membership,
        private OrderService $orders,
        private RedeemCodeService $codes,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/plans', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->plans(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->redeem($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'code' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/membership', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->membershipState(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);
    }

    private function plans(): WP_REST_Response
    {
        $settings = (array) get_option(SponsorshipModule::OPTION_NAME, []);
        $rows = (array) ($settings['plans'] ?? []);

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $items[] = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'days' => max(1, (int) ($row['days'] ?? 1)),
            ];
        }

        return new WP_REST_Response([
            'channels' => [
                'afdian' => (bool) ($settings['afdian_enable'] ?? false),
                'epay' => (bool) ($settings['epay_enable'] ?? false),
            ],
            'items' => $items,
        ]);
    }

    private function redeem(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hit('sponsorship_redeem', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $code = trim(sanitize_text_field((string) $request->get_param('code')));
        $result = $this->codes->redeem($code, $userId);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'days' => $result['days'],
            'expiresAt' => $this->iso($result['expiresAt']),
        ]);
    }

    private function membershipState(): WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $expiresAt = $this->membership->expiration($userId);
        // Legacy is_valid semantics: pure protocol-meta window, no editor bypass.
        $active = !$this->membership->forceCancelled($userId) && $this->membership->leftDays($userId) > 0;

        $totalDays = 0;
        $orders = [];
        foreach ($this->orders->forUser($userId) as $order) {
            $totalDays += (int) $order->duration_days;
            $orders[] = [
                'orderId' => (string) $order->order_id,
                'days' => (int) $order->duration_days,
                'startedAt' => $this->iso((int) $order->start_time),
                'status' => (string) $order->status,
                'source' => (string) $order->source,
                'createdAt' => $this->isoCreatedAt((string) $order->created_at),
            ];
        }

        return new WP_REST_Response([
            'active' => $active,
            'forceCancelled' => $this->membership->forceCancelled($userId),
            'expiresAt' => $expiresAt > 0 ? $this->iso($expiresAt) : null,
            'leftDays' => $this->membership->leftDays($userId),
            'totalDays' => $totalDays,
            'triggerCount' => $this->membership->triggerCount($userId),
            'orders' => $orders,
        ]);
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }

    private function iso(int $localTimestamp): string
    {
        return (string) wp_date('c', $localTimestamp);
    }

    private function isoCreatedAt(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
