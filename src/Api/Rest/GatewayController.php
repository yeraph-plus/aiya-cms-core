<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use Aiya\Core\Domain\Sponsorship\WebhookLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Third-party gateway callbacks, deliberately outside the versioned
 * contract namespace (`aiya/sponsorship/v1`): platform pushes are not
 * visitor-facing contract. Authentication IS the signature check —
 * mis-signed pushes are rejected outright. The 0.50.0 rewrite wires only
 * the Epay cashier: a verified push records the payment and queues the
 * entitlement (money facts and service rights are separate rows joined
 * by the same order id). The Afdian integration is parked — SDK class
 * retained in the domain, no webhook route.
 */
final class GatewayController
{
    public const GATEWAY_NAMESPACE = 'aiya/sponsorship/v1';

    public function __construct(
        private OrderService $orders,
        private EntitlementService $entitlements,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::GATEWAY_NAMESPACE, 'epay/callback', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->epayCallback($request),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Gateway push (GET, signed query): the adapter verifies and resolves
     * the payment description, the domain records it and queues the
     * entitlement. The money amount is never matched back into rights —
     * the tier snapshot comes from the site's own settings. Every
     * verified push answers 200 so the gateway stops retrying; the
     * order-id unique keys keep replays idempotent. Adding a gateway
     * means one more adapter route here, zero domain changes.
     */
    private function epayCallback(WP_REST_Request $request): WP_REST_Response
    {
        $settings = SponsorshipSettings::read();
        $query = $request->get_query_params();

        if ($settings['epaySavelog']) {
            WebhookLogger::write('Epay callback received:', (string) wp_json_encode($query));
        }

        $gateway = EpayGateway::fromSettings();
        $payment = $gateway?->verifyCallback($query);
        if ($payment === null) {
            if ($settings['epaySavelog']) {
                $reason = $gateway !== null && $gateway->callbackFailed($query) ? 'invalid signature' : 'not activatable (status or binding)';
                WebhookLogger::write("Epay push rejected: {$reason}.", (string) wp_json_encode($query));
            }

            // A bad signature answers 400 so tampering is visible to the
            // platform; a validly signed push we cannot use answers
            // success so the platform stops retrying.
            if ($gateway !== null && $gateway->callbackFailed($query)) {
                return new WP_REST_Response('fail', 400);
            }

            return new WP_REST_Response('success', 200);
        }

        $orderId = $payment['orderId'];
        $userId = $payment['userId'];
        $tier = SponsorshipSettings::tierByKey(SponsorshipSettings::read()['tiers'], $payment['tierKey']);
        if ($tier === null) {
            return new WP_REST_Response('success', 200);
        }

        if (!$this->orders->exists($orderId)) {
            $recorded = $this->orders->addPayment(
                $userId,
                $orderId,
                $tier['key'],
                $payment['amount'],
                'epay'
            );
            if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
                if ($settings['epaySavelog']) {
                    WebhookLogger::write("The order:{$orderId} payment failed: " . $recorded->get_error_message(), '');
                }

                // Non-"success" makes the gateway resend; the exists() guard
                // above keeps that idempotent.
                return new WP_REST_Response('fail', 400);
            }
        }

        // Idempotent on the order_id unique key: a retry after a half-done
        // run completes the queueing instead of double-granting.
        $activated = $this->entitlements->activateFromPayment($userId, $orderId, $tier, $payment['cycles']);

        if ($settings['epaySavelog']) {
            $outcome = is_wp_error($activated) ? 'activation failed: ' . $activated->get_error_message() : 'activation completed';
            WebhookLogger::write("The order:{$orderId} user id:{$userId} {$outcome}.", '');
        }

        if (is_wp_error($activated) && $activated->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_REST_Response('fail', 400);
        }

        return new WP_REST_Response('success', 200);
    }
}
