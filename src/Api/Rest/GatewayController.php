<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Sponsorship\AfdianActivator;
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
 * platform pushes are not visitor-facing contract. How a push proves
 * itself is each gateway's own business. The Epay cashier push is
 * signature-verified, then settled against the checkout row this site
 * wrote (money facts and service rights are separate rows joined by the
 * same order id). The Afdian webhook (2026-09-21 rewrite) verifies
 * nothing of the push body — its one trusted field is the trade number —
 * and settles by re-reading the purchase through the platform's
 * authenticated open API, the exact chain the buyer-typed order-number
 * path runs.
 */
final class GatewayController
{
    public function __construct(
        private OrderService $orders,
        private EntitlementService $entitlements,
        private RateLimiter $limiter = new RateLimiter(),
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

        // Epay reads our answer verbatim: the gateway doc requires the plain
        // text `success` ("收到异步通知后，需返回success以表示服务器接收到了
        // 订单通知"), and the REST server would JSON-encode a string response
        // into `"success"` — which an exact-match check on the platform side
        // reads as a failure and retries. The status header is already sent
        // when this filter runs, so the 400 `fail` of a bad signature
        // survives unchanged. Afdian keeps its JSON envelope: that platform
        // parses `{ec,em}`.
        add_filter('rest_pre_serve_request', static function (bool $served, mixed $result, WP_REST_Request $request): bool {
            if ($request->get_route() !== '/' . PaymentGateway::GATEWAY_NAMESPACE . '/epay/callback') {
                return $served;
            }

            $body = $result instanceof WP_REST_Response ? $result->get_data() : '';
            header('Content-Type: text/plain; charset=utf-8');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the gateway's own acknowledgment token, plain text by contract
            echo is_string($body) ? $body : '';

            return true;
        }, 10, 3);
    }

    /**
     * Gateway push (GET, signed query): the signature is Epay's one
     * credential and stays mandatory; what was bought comes from the
     * checkout row this site wrote, not from the callback. Every settled
     * push answers 200 so the gateway stops retrying; the order-id unique
     * keys keep replays idempotent. Adding a gateway means one more
     * adapter route here, zero domain changes.
     */
    private function epayCallback(WP_REST_Request $request): WP_REST_Response
    {
        $query = $request->get_query_params();

        $gateway = EpayGateway::fromSettings();
        if ($gateway === null) {
            return new WP_REST_Response('success', 200);
        }

        $payment = $gateway->verifyCallback($query);
        if ($payment === null) {
            // One local verdict decides the answer — the signature check
            // runs once per push (a push used to be verified twice here).
            // A bad signature answers 400 so tampering is visible to the
            // platform; a validly signed push we cannot use answers
            // success so the platform stops retrying.
            $failed = $gateway->callbackFailed($query);
            WebhookLogger::write($failed
                ? 'Epay push rejected: invalid signature.'
                : 'Epay push ignored: not activatable (status or binding).', '');

            return $failed ? new WP_REST_Response('fail', 400) : new WP_REST_Response('success', 200);
        }

        return $this->settle($payment);
    }

    /**
     * The Epay push settle: the order row this site issued is the authority
     * for WHAT was bought (holder, tier, cycles), the push only reports
     * that money arrived — and its amount is the money truth. Shapes that
     * reach here:
     *
     * - a pending row (the normal case): confirm flips it paid, then the
     *   entitlement queues;
     * - confirm() false: the row was settled between our read and the
     *   write (a concurrent replay — fall through to the activation, whose
     *   order-id unique key is the idempotency backstop), or the write
     *   itself failed (answer `fail` so the platform retries — the
     *   ARCHITECTURE convention: a broken invariant must be hearable);
     * - a row already paid (a replay): money untouched, the queueing
     *   re-runs so a retry after a failed activation completes;
     * - no row: the id was not issued by this site, so there is nothing
     *   to do but answer success and let the platform stop retrying.
     *
     * @param array{orderId:string, userId:int, tierKey:string, cycles:int, amount:float} $payment
     */
    private function settle(array $payment): WP_REST_Response
    {
        $orderId = $payment['orderId'];
        $row = $this->orders->orderRow($orderId);

        if ($row === null) {
            WebhookLogger::write("The order {$orderId} is not a checkout of this site; nothing to settle.", '');

            return new WP_REST_Response('success', 200);
        }

        if ($row['status'] !== OrderService::STATUS_PAID) {
            // Money first: the row settles even when the tier has since
            // been deleted — paid money must never silently vanish from
            // the books. Rights are refused further down instead.
            $settled = $this->orders->confirm($row['id'], $payment['amount']);
            if (!$settled) {
                $row = $this->orders->orderRow($orderId) ?? $row;
                if ($row['status'] !== OrderService::STATUS_PAID) {
                    // Still pending: the write failed, not a lost race.
                    WebhookLogger::write("The order {$orderId} could not be settled — answering retry.", '');

                    return new WP_REST_Response('fail', 400);
                }
            }
        }

        $tier = SponsorshipSettings::tierByKey(SponsorshipSettings::read()['tiers'], $row['tier_key']);

        if ($tier === null) {
            WebhookLogger::write("The order {$orderId} names tier {$row['tier_key']}, which the site no longer sells; money stays booked.", '');

            return new WP_REST_Response('success', 200);
        }

        $activated = $this->entitlements->activateFromPayment((int) $row['user_id'], $orderId, $tier, (int) $row['cycles']);

        $outcome = is_wp_error($activated) ? 'activation failed: ' . $activated->get_error_code() : 'activation completed';
        WebhookLogger::write("The order {$orderId} user id {$row['user_id']}: {$outcome}.", '');

        if (is_wp_error($activated) && $activated->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_REST_Response('fail', 400);
        }

        return new WP_REST_Response('success', 200);
    }

    /**
     * Afdian webhook push (POST, JSON body): unverified by design — the
     * RSA signature check is retired with the push-trust model
     * (2026-09-21 decision; the platform key was becoming unmaintainable
     * dead weight). The activator re-reads the whole purchase through the
     * open API — the same ping→query→settle chain as the buyer-typed
     * order-number path — and settles from the query's facts. A normal
     * receipt always answers the {ec,em} envelope with 200, whatever the
     * settlement outcome, so the platform stops retrying; only a body
     * that is not JSON at all answers 400. The log carries the meaningful
     * nodes (order number, query verdict, activation result), never a
     * raw dump.
     */
    private function afdianCallback(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            WebhookLogger::write('Afdian push discarded: the body is not valid JSON.', '');

            return new WP_REST_Response(['ec' => 400, 'em' => 'invalid payload'], 400);
        }

        // Anonymous endpoint, one outbound query-API call per push: a fixed
        // IP window keeps a push flood from turning into an API flood
        // (same shape as the cashier's order builder). 429 sends the
        // platform away to re-deliver later.
        if (!$this->limiter->hit('afdian_webhook', 10, 600)) {
            WebhookLogger::write('Afdian push limited: too many pushes from one address.', '');

            return new WP_REST_Response(['ec' => 429, 'em' => 'rate limited'], 429);
        }

        $activator = AfdianActivator::fromSettings();
        if ($activator === null) {
            WebhookLogger::write('Afdian push ignored: the integration is off.', '');

            return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
        }

        $outcome = $activator->settlePush($body);
        WebhookLogger::write('Afdian push: ' . $outcome, '');

        return new WP_REST_Response(['ec' => 200, 'em' => 'done'], 200);
    }
}
