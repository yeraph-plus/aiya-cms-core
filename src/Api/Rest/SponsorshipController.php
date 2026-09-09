<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Sponsorship\AfdianClient;
use Aiya\Core\Domain\Sponsorship\EpayClient;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Sponsorship read/self-service routes of the versioned API: the plan list
 * (public, from the domain settings), self-service code redemption and the
 * viewer's own membership state with order history, plus the payment-link
 * builders (Epay cashier submit, Afdian order URL). The gateway callbacks
 * themselves live in GatewayController, outside the contract namespace.
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

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/orders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->createOrder($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'planKey' => ['type' => 'string', 'required' => true, 'maxLength' => 32],
                'channel' => ['type' => 'string', 'required' => true, 'enum' => ['alipay', 'wxpay', 'usdt']],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/afdian/order-url', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_Error|WP_REST_Response => $this->afdianOrderUrl(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);
    }

    private function plans(): WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $homeUrl = $settings['afdianHomeSlug'] !== '' ? 'https://afdian.com/a/' . $settings['afdianHomeSlug'] : null;

        return new WP_REST_Response([
            'channels' => [
                'afdian' => $settings['afdianEnable'],
                'epay' => $settings['epayEnable'],
                'afdianHomeUrl' => $homeUrl,
            ],
            'items' => $settings['plans'],
        ]);
    }

    private function redeem(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hit('sponsorship_redeem', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $code = trim(sanitize_text_field((string) $request->get_param('code')));

        // An all-numeric code is an Afdian trade number: verify it online
        // and activate from the platform order. Everything else is a local
        // redemption code.
        if (ctype_digit($code)) {
            return $this->redeemAfdianOrder($code, $userId);
        }

        $result = $this->codes->redeem($code, $userId);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'days' => $result['days'],
            'expiresAt' => $this->iso($result['expiresAt']),
        ]);
    }

    /** Online order-number redemption (legacy behavior): ping, dedupe, query, activate. */
    private function redeemAfdianOrder(string $tradeNo, int $userId): WP_Error|WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $client = $this->afdianClient($settings);

        if ($client === null || !$client->ping()) {
            return new WP_Error('aiya_afdian_unavailable', __('The Afdian API is unavailable, contact the site owner.', 'aiya-core'), ['status' => 502]);
        }

        if ($this->orders->exists('afd_' . $tradeNo)) {
            return new WP_Error('aiya_code_used', __('This order was already activated.', 'aiya-core'), ['status' => 409]);
        }

        $order = $client->queryOrder($tradeNo);
        if ($order === null) {
            return new WP_Error('aiya_code_invalid', __('No such order found — check the trade number or contact the site owner.', 'aiya-core'), ['status' => 400]);
        }

        $days = ((int) ($order['month'] ?? 0)) * 31;
        $result = $this->orders->add($userId, 'afd_' . $tradeNo, $days, OrderService::STATUS_PAID, 'afdian');
        if (is_wp_error($result)) {
            return new WP_Error('aiya_code_activation_failed', __('Activation failed — you may already hold an overlapping period, or the order was already recorded.', 'aiya-core'), ['status' => 500]);
        }

        return new WP_REST_Response([
            'days' => $days,
            'expiresAt' => $this->iso($this->orders->syncExpiration($userId)),
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

    /**
     * Creates a signed Epay cashier order for the viewer: days are bound
     * to the plan key inside the signed params, so gateway callbacks never
     * resolve amounts back into periods.
     */
    private function createOrder(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $planKey = sanitize_key((string) $request->get_param('planKey'));
        $channel = (string) $request->get_param('channel');
        $plan = SponsorshipSettings::planByKey($settings['plans'], $planKey);

        if (!$settings['epayEnable']) {
            return new WP_Error('aiya_channel_unavailable', __('The Epay channel is not available.', 'aiya-core'), ['status' => 502]);
        }
        if (!$this->epayChannelEnabled($settings, $channel)) {
            return new WP_Error('aiya_channel_unavailable', __('The requested payment channel is not available.', 'aiya-core'), ['status' => 502]);
        }
        if ($plan === null) {
            return new WP_Error('aiya_not_found', __('Unknown purchase plan.', 'aiya-core'), ['status' => 404]);
        }

        $client = new EpayClient($settings['epayPid'], $settings['epayKey'], $settings['epayGateway']);
        if (!$client->configured()) {
            return new WP_Error('aiya_channel_unavailable', __('The Epay channel is not configured.', 'aiya-core'), ['status' => 502]);
        }

        $userId = (int) get_current_user_id();
        $orderId = gmdate('Ymd') . str_pad((string) $userId, 5, '0', STR_PAD_LEFT) . time();
        $binding = (new IdSlugEncoder(8))->encodeId($userId) . '|' . $planKey;

        $submitQuery = $client->buildSubmitQuery([
            'out_trade_no' => $orderId,
            'name' => $plan['name'],
            'money' => number_format($plan['price'], 2, '.', ''),
            'param' => $binding,
            'type' => $channel,
        ], get_rest_url(null, '/' . GatewayController::GATEWAY_NAMESPACE . '/epay/callback'), $settings['epayReturnUrl']);

        return new WP_REST_Response([
            'orderId' => $orderId,
            'submitUrl' => $client->submitUrl($submitQuery),
        ]);
    }

    /**
     * The viewer's Afdian order URL: the platform page carries the encoded
     * user binding (`custom_order_id`) so the webhook can activate without
     * any manual step.
     */
    private function afdianOrderUrl(): WP_Error|WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        if (!$settings['afdianEnable']) {
            return new WP_Error('aiya_channel_unavailable', __('The Afdian channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        $binding = (new AfdianClient($settings['afdianUserId'], $settings['afdianToken']))->bindUser((int) get_current_user_id());
        $siteName = (string) get_bloginfo('name');
        $userName = get_the_author_meta('display_name', (int) get_current_user_id());
        /* translators: %1$s: site name, %2$s: user display name. */
        $remark = rawurlencode(sprintf(__('A sponsorship order from "%1$s" user %2$s~', 'aiya-core'), $siteName, $userName));

        $planId = '';
        if ($settings['afdianPlanType'] === 'preset' && $settings['afdianPresetPlanUrl'] !== '') {
            parse_str((string) wp_parse_url($settings['afdianPresetPlanUrl'], PHP_URL_QUERY), $query);
            $rawPlanId = $query['plan_id'] ?? '';
            $planId = is_string($rawPlanId) ? $rawPlanId : '';
        }

        $url = $planId !== ''
            ? "https://afdian.com/order/create?plan_id={$planId}&custom_order_id={$binding}&remark={$remark}"
            : "https://afdian.com/order/create?user_id={$settings['afdianUserId']}&custom_order_id={$binding}&remark={$remark}";

        return new WP_REST_Response(['url' => $url]);
    }

    /** @param array<string, mixed> $settings */
    private function epayChannelEnabled(array $settings, string $channel): bool
    {
        return match ($channel) {
            'alipay' => $settings['epayAlipay'],
            'wxpay' => $settings['epayWxpay'],
            'usdt' => $settings['epayUsdt'],
            default => false,
        };
    }

    /** @param array<string, mixed> $settings */
    private function afdianClient(array $settings): ?AfdianClient
    {
        if (!$settings['afdianEnable'] || $settings['afdianUserId'] === '' || $settings['afdianToken'] === '') {
            return null;
        }

        return new AfdianClient($settings['afdianUserId'], $settings['afdianToken'], static function (string $url, string $body): ?string {
            $response = wp_remote_post($url, [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
            ]);
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            return (string) wp_remote_retrieve_body($response);
        });
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
