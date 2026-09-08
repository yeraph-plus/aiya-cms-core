<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Sponsorship\AfdianClient;
use Aiya\Core\Domain\Sponsorship\EpayClient;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use Aiya\Core\Domain\Sponsorship\WebhookLogger;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Third-party gateway callbacks, deliberately outside the versioned
 * contract namespace (`aiya/sponsorship/v1`): platform pushes are not
 * visitor-facing contract. Authentication IS the signature check —
 * mis-signed pushes are rejected outright instead of the legacy
 * always-accept behavior.
 */
final class GatewayController
{
    public const GATEWAY_NAMESPACE = 'aiya/sponsorship/v1';

    public function __construct(private OrderService $orders)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::GATEWAY_NAMESPACE, 'afdian/callback', [
            'methods' => 'POST',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->afdianCallback($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::GATEWAY_NAMESPACE, 'epay/callback', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->epayCallback($request),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Afdian push: verify, then bind the order through the
     * `custom_order_id` user encoding the site put into the payment link.
     * The endpoint answers 200 (platform stops retrying) for every
     * verified push, including repeats — order ids dedupe.
     */
    private function afdianCallback(WP_REST_Request $request): WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $raw = (string) $request->get_body();
        $decoded = json_decode($raw, true);

        if ($settings['afdianSavelog']) {
            WebhookLogger::write('Afdian callback received:', $raw);
        }

        if (!is_array($decoded)) {
            return new WP_REST_Response(['ec' => 200, 'em' => 'ignored'], 200);
        }

        $client = $this->afdianClient($settings);
        if ($client === null || !$client->verifyWebhook($decoded)) {
            if ($settings['afdianSavelog']) {
                WebhookLogger::write('Afdian signature check failed.', '');
            }

            return new WP_REST_Response(['ec' => 403, 'em' => 'signature check failed'], 403);
        }

        $order = $decoded['data']['order'] ?? null;
        $customOrderId = is_array($order) ? (string) ($order['custom_order_id'] ?? '') : '';

        if ($order === null || !is_array($order) || $customOrderId === '') {
            return new WP_REST_Response(['ec' => 200, 'em' => 'no binding'], 200);
        }

        $userId = $client->resolveUser($customOrderId);
        $orderId = 'afd_' . (string) ($order['out_trade_no'] ?? '');
        // Only successful trades activate; the legacy callback ignored
        // status entirely.
        $days = ((int) ($order['month'] ?? 0)) * 31;

        if ($userId <= 0 || $orderId === 'afd_' || $days <= 0 || (string) ($order['status'] ?? '') !== 'trade_success') {
            if ($settings['afdianSavelog']) {
                WebhookLogger::write("Afdian push not activatable: order {$orderId} user {$userId}.", '');
            }

            return new WP_REST_Response(['ec' => 200, 'em' => 'skipped'], 200);
        }

        if ($this->orders->exists($orderId)) {
            return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
        }

        $result = $this->orders->add($userId, $orderId, $days, OrderService::STATUS_PAID, 'afdian');

        if ($settings['afdianSavelog']) {
            $outcome = is_wp_error($result) ? 'activation failed' : 'activation completed';
            WebhookLogger::write("The order:{$orderId} user id:{$userId} {$outcome}.", '');
        }

        return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
    }

    /**
     * Epay push (GET, signed query): verify, resolve the plan identity the
     * submit params carried (`userBinding|planKey`), record the order.
     * Days come from the plan — never from amount matching.
     */
    private function epayCallback(WP_REST_Request $request): WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $query = $request->get_query_params();

        if ($settings['epaySavelog']) {
            WebhookLogger::write('Epay callback received:', (string) json_encode($query));
        }

        $client = $this->epayClient($settings);
        if ($client === null || !$client->verifyCallback($query)) {
            if ($settings['epaySavelog']) {
                WebhookLogger::write('Epay signature check failed.', (string) json_encode($query));
            }

            return new WP_REST_Response('fail', 400);
        }

        $tradeStatus = (string) ($query['trade_status'] ?? '');
        $outTradeNo = (string) ($query['out_trade_no'] ?? '');
        $binding = (string) ($query['param'] ?? '');

        if ($tradeStatus !== 'TRADE_SUCCESS' || $outTradeNo === '' || $binding === '') {
            if ($settings['epaySavelog']) {
                WebhookLogger::write('Epay push not activatable (status or binding missing).', '');
            }

            return new WP_REST_Response('success', 200);
        }

        [$userBinding, $planKey] = array_pad(explode('|', $binding, 2), 2, '');
        $userId = (int) $this->binding()->decodeId($userBinding);
        $plan = SponsorshipSettings::planByKey($settings['plans'], sanitize_key($planKey));

        if ($userId <= 0 || $plan === null) {
            if ($settings['epaySavelog']) {
                WebhookLogger::write("Epay binding unresolved: user {$userId} plan {$planKey}.", '');
            }

            return new WP_REST_Response('success', 200);
        }

        $orderId = 'epc_' . $outTradeNo;
        if ($this->orders->exists($orderId)) {
            return new WP_REST_Response('success', 200);
        }

        $result = $this->orders->add($userId, $orderId, $plan['days'], OrderService::STATUS_PAID, 'payment');

        if ($settings['epaySavelog']) {
            $outcome = is_wp_error($result) ? 'activation failed' : 'activation completed';
            WebhookLogger::write("The order:{$orderId} user id:{$userId} {$outcome}.", '');
        }

        return new WP_REST_Response('success', 200);
    }

    /** Builds the Afdian client with a wp_remote_post transport, or null when disabled. */
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

    /** @param array<string, mixed> $settings */
    private function epayClient(array $settings): ?EpayClient
    {
        $client = new EpayClient($settings['epayPid'], $settings['epayKey'], $settings['epayGateway']);

        return $settings['epayEnable'] && $client->configured() ? $client : null;
    }

    /**
     * The user binding shared with the payment-link builder (same frozen
     * XDE alphabet as the legacy custom_order_id values).
     */
    private function binding(): IdSlugEncoder
    {
        static $binding = null;
        $binding ??= new IdSlugEncoder(8);

        return $binding;
    }
}
