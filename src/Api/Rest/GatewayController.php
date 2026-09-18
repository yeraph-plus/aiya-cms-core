<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\PaymentGateway;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use Aiya\Core\Domain\Sponsorship\WebhookLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Third-party gateway callbacks, deliberately outside the versioned
 * contract namespace (the domain-owned `PaymentGateway::GATEWAY_NAMESPACE`):
 * platform pushes are not visitor-facing contract. Authentication IS the
 * signature check — mis-signed pushes are rejected outright. Both
 * cashier-side gateways are wired: a verified Epay push records the
 * payment and queues the entitlement (money facts and service rights are
 * separate rows joined by the same order id); Afdian pushes arrive as
 * webhooks through the same pair of routes.
 */
final class GatewayController
{
    public function __construct(
        private OrderService $orders,
        private EntitlementService $entitlements,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(PaymentGateway::GATEWAY_NAMESPACE, 'epay/callback', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->epayCallback($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(PaymentGateway::GATEWAY_NAMESPACE, 'afdian/callback', [
            'methods' => 'POST',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->afdianCallback($request),
            'permission_callback' => '__return_true',
        ]);

        // Announce this first-party namespace to the headless REST gate —
        // the infrastructure layer owns the trim, the API layer owns the
        // list of namespaces it serves (extension seam for future domains).
        add_filter('aiya_core_firstparty_rest_namespaces', static function (array $namespaces): array {
            $namespaces[] = '/' . PaymentGateway::GATEWAY_NAMESPACE;

            return $namespaces;
        });
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
        $query = $request->get_query_params();

        WebhookLogger::write('Epay callback received:', (string) wp_json_encode($query));

        $gateway = EpayGateway::fromSettings();
        $payment = $gateway?->verifyCallback($query);
        if ($payment === null) {
            $reason = $gateway !== null && $gateway->callbackFailed($query) ? 'invalid signature' : 'not activatable (status or binding)';
            WebhookLogger::write("Epay push rejected: {$reason}.", (string) wp_json_encode($query));

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

        if (!$this->orders->exists($orderId)) {
            // Record the money fact before anything else — paid money must
            // never silently vanish from the books, even when the tier has
            // since been deleted. Rights are separate and refused further
            // down.
            $recorded = $this->orders->addPayment($userId, $orderId, $payment['tierKey'], $payment['amount'], 'epay');
            if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
                WebhookLogger::write("The order:{$orderId} payment failed: " . $recorded->get_error_message(), '');

                // Non-"success" makes the gateway resend; the exists() guard
                // above keeps that idempotent.
                return new WP_REST_Response('fail', 400);
            }
        }

        if ($tier === null) {
            return new WP_REST_Response('success', 200);
        }

        // Idempotent on the order_id unique key: a retry after a half-done
        // run completes the queueing instead of double-granting.
        $activated = $this->entitlements->activateFromPayment($userId, $orderId, $tier, $payment['cycles']);

        $outcome = is_wp_error($activated) ? 'activation failed: ' . $activated->get_error_message() : 'activation completed';
        WebhookLogger::write("The order:{$orderId} user id:{$userId} {$outcome}.", '');

        if (is_wp_error($activated) && $activated->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_REST_Response('fail', 400);
        }

        return new WP_REST_Response('success', 200);
    }

    /**
     * Afdian webhook push (POST, JSON body signed over data+ts): the same
     * settle semantics as the Epay push — verify, record, queue — with
     * the platform's own response shape ({ec,em}; the legacy contract
     * Afdian clients parse). Order ids carry the `afd_` namespace prefix
     * from the adapter; unique keys keep replays idempotent.
     */
    private function afdianCallback(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            $body = [];
        }

        WebhookLogger::write('Afdian push received:', (string) wp_json_encode($body));

        $gateway = AfdianGateway::fromSettings();
        $payment = $gateway?->verifyCallback($body);
        if ($payment === null) {
            if ($gateway !== null && $gateway->callbackFailed($body)) {
                WebhookLogger::write('Afdian push rejected: invalid signature.', '');

                return new WP_REST_Response('fail', 400);
            }

            return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
        }

        $orderId = $payment['orderId'];
        $userId = $payment['userId'];
        $tier = SponsorshipSettings::tierByKey(SponsorshipSettings::read()['tiers'], $payment['tierKey']);
        if ($tier === null) {
            return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
        }

        if (!$this->orders->exists($orderId)) {
            $recorded = $this->orders->addPayment(
                $userId,
                $orderId,
                $tier['key'],
                $payment['amount'],
                'afdian'
            );
            if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
                WebhookLogger::write("The order:{$orderId} payment failed: " . $recorded->get_error_message(), '');

                return new WP_REST_Response('fail', 400);
            }
        }

        $activated = $this->entitlements->activateFromPayment($userId, $orderId, $tier, $payment['cycles']);

        $outcome = is_wp_error($activated) ? 'activation failed: ' . $activated->get_error_message() : 'activation completed';
        WebhookLogger::write("The order:{$orderId} user id:{$userId} {$outcome}.", '');

        if (is_wp_error($activated) && $activated->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_REST_Response('fail', 400);
        }

        return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
    }
}
