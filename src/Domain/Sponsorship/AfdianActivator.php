<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;

use WP_Error;

/**
 * The Afdian activation chain (2026-09-21 webhook rewrite): one
 * ping→query-order→verify→settle sequence, shared by both triggers — the
 * buyer typing the order number into the redeem box, and the webhook
 * whose push is only a hint carrying the trade number. Every purchase
 * fact (paid?, amount, cycles, plan) is read from the platform's own
 * API answer; a push body is never trusted beyond its trade number, and
 * the RSA signature check is retired with that push-trust model.
 *
 * Plan resolution runs through the gateway's binding table: a bound plan
 * activates its tier, the amount-only plan (empty plan_id — a plain tip)
 * falls into the configured fallback tier, and an unknown plan is
 * refused. Attribution rides the deep-link binding (custom_order_id):
 * the webhook attributes to the bound account only, while the manual
 * path lets the caller claim an unbound order.
 *
 * Order ids carry the `afd_` namespace prefix; the unique keys in the
 * payment log and the entitlement queue make replays from either trigger
 * idempotent — a settled push or a re-submitted number is a clean no-op,
 * never a double grant.
 */
final class AfdianActivator
{
    private const MAX_CYCLES = 36;

    public function __construct(
        private Client $client,
        private OrderService $orders,
        private EntitlementService $entitlements,
        private AfdianGateway $gateway,
    ) {
    }

    /** Builds from the domain settings (null when Afdian is off/unconfigured). */
    public static function fromSettings(): ?self
    {
        $gateway = AfdianGateway::fromSettings();
        if ($gateway === null) {
            return null;
        }

        return new self(
            $gateway->client(),
            new OrderService(),
            new EntitlementService(),
            $gateway,
        );
    }

    /**
     * Verifies one Afdian order number and activates it for the caller.
     *
     * @return array{tierKey:string, tierName:string, cycles:int}|WP_Error
     */
    public function activate(int $userId, string $orderNo): array|WP_Error
    {
        $resolved = $this->resolvePurchase($orderNo, $userId);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $activated = $this->bookAndActivate($resolved);
        if (is_wp_error($activated)) {
            $code = $activated->get_error_code() === 'aiya_duplicate_order'
                ? 'aiya_order_used'
                : 'aiya_activation_failed';

            return new WP_Error($code, $code === 'aiya_order_used'
                ? __('This order has already been activated.', 'aiya-core')
                : __('The membership activation failed — try again.', 'aiya-core'), ['status' => $code === 'aiya_order_used' ? 409 : 502]);
        }

        return [
            'tierKey' => $resolved['tier']['key'],
            'tierName' => $resolved['tier']['name'],
            'cycles' => $resolved['cycles'],
        ];
    }

    /**
     * The webhook's settle of one push body: extract the trade number,
     * re-read the purchase from the open API, settle it — the same chain
     * as activate(), attributed from the order's own deep-link binding.
     * Every outcome is normal traffic, so the answer is always success;
     * the return value is the operator-facing log line.
     *
     * @param array<string, mixed> $push
     */
    public function settlePush(array $push): string
    {
        $orderNo = $this->gateway->pushOrderNo($push);
        if ($orderNo === null) {
            return 'ignored: the push carries no order number.';
        }

        $resolved = $this->resolvePurchase($orderNo, null);
        if (is_wp_error($resolved)) {
            return 'ignored: ' . $resolved->get_error_code() . " (order {$orderNo}).";
        }

        $activated = $this->bookAndActivate($resolved);
        if (is_wp_error($activated)) {
            return $activated->get_error_code() === 'aiya_duplicate_order'
                ? "already activated: order {$orderNo} (idempotent replay)."
                : 'failed: ' . $activated->get_error_code() . " (order {$orderNo}).";
        }

        return sprintf(
            'activated order %1$s: tier %2$s ×%3$d for user #%4$d (amount %5$.2f).',
            $orderNo,
            $resolved['tier']['key'],
            $resolved['cycles'],
            $resolved['userId'],
            $resolved['amount']
        );
    }

    /**
     * The shared ping→query→verify chain. Returns the purchase the API
     * vouches for, with the holder resolved per trigger: the webhook
     * ($claimant null) trusts only the deep-link binding, the manual
     * path claims the order for its caller unless another account owns
     * the binding.
     *
     * @return array{orderNo:string, orderId:string, userId:int, tier:array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}, cycles:int, amount:float}|WP_Error
     */
    private function resolvePurchase(string $orderNo, ?int $claimant): array|WP_Error
    {
        // Official open-API ec table: 200 ok, 0 = no answer (this side),
        // 400002 = ts expired (clock skew), 400004/400005 = bad credentials.
        $ec = $this->client->ping();
        if ($ec === 0) {
            return new WP_Error('aiya_afdian_unavailable', __('The Afdian API is unreachable — try again later.', 'aiya-core'), ['status' => 502]);
        }
        if ($ec !== 200) {
            $message = $ec === 400002
                ? __('Afdian rejected the request (expired timestamp) — check this server\'s clock.', 'aiya-core')
                : __('Afdian rejected the request — check the Afdian user id and API token on the payments settings page.', 'aiya-core');

            return new WP_Error('aiya_afdian_rejected', $message, ['status' => 502]);
        }

        $order = $this->client->queryOrder($orderNo);
        if ($order === null) {
            return new WP_Error('aiya_order_not_found', __('No matching Afdian order — check the order number.', 'aiya-core'), ['status' => 404]);
        }

        // Official query-order doc: status 2 = 交易成功. The push's own
        // status field is never consulted — this answer is the authority.
        if ((int) ($order['status'] ?? 0) !== 2) {
            return new WP_Error('aiya_order_not_paid', __('This order is not a paid trade.', 'aiya-core'), ['status' => 422]);
        }

        // Plan resolution: a bound plan activates its tier; the amount-only
        // plan falls into the fallback tier; anything else is refused (and
        // logged by the webhook, surfaced as 422 to the manual caller).
        $planId = (string) ($order['plan_id'] ?? '');
        $tier = $planId === '' ? $this->gateway->fallbackTier() : $this->gateway->tierForPlan($planId);
        if ($tier === null) {
            return new WP_Error('aiya_plan_unbound', __('This order\'s Afdian plan is not bound to a membership tier.', 'aiya-core'), ['status' => 422]);
        }

        // Attribution: an order placed through the personalized deep link
        // carries the account binding in custom_order_id.
        $boundUser = $this->client->resolveUser((string) ($order['custom_order_id'] ?? ''));
        if ($claimant !== null) {
            if ($boundUser > 0 && $boundUser !== $claimant) {
                return new WP_Error('aiya_order_bound', __('This order is bound to another account.', 'aiya-core'), ['status' => 409]);
            }
            $userId = $claimant;
        } elseif ($boundUser <= 0) {
            // Placed on the platform directly: no local account to credit.
            return new WP_Error('aiya_order_unattributed', __('The order carries no account binding — activate it from the account page with the order number.', 'aiya-core'), ['status' => 422]);
        } else {
            $userId = $boundUser;
        }

        return [
            'orderNo' => $orderNo,
            'orderId' => Gateway::ORDER_PREFIX . $orderNo,
            'userId' => $userId,
            'tier' => $tier,
            'cycles' => max(1, min(self::MAX_CYCLES, (int) ($order['month'] ?? 1))),
            'amount' => (float) ($order['total_amount'] ?? 0),
        ];
    }

    /**
     * Books the money, then queues the entitlement. A pending checkout
     * row (the deep link wrote one) settles to the real order id, actual
     * amount, cycles and plan-resolved tier; without one the purchase is
     * booked directly. Both writes are idempotent on the order-id unique
     * keys, so replays from the webhook and the manual path converge on
     * one settled row and one activation.
     *
     * @param array{orderId:string, userId:int, tier:array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}, cycles:int, amount:float} $resolved
     * @return true|WP_Error aiya_duplicate_order = already activated
     */
    private function bookAndActivate(array $resolved): bool|WP_Error
    {
        $pending = $this->orders->pendingForUser($resolved['userId'], 'afdian');
        if ($pending !== null) {
            // A false return here means the real order id is already
            // booked (a concurrent settle or the manual path won the
            // race) — the queue step below is the idempotent one either way.
            $this->orders->confirm($pending['id'], $resolved['amount'], $resolved['orderId'], $resolved['cycles'], $resolved['tier']['key']);
        } else {
            $recorded = $this->orders->addPayment($resolved['userId'], $resolved['orderId'], $resolved['tier']['key'], $resolved['amount'], 'afdian');
            if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
                return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
            }
        }

        return $this->entitlements->activateFromPayment($resolved['userId'], $resolved['orderId'], $resolved['tier'], $resolved['cycles']);
    }
}
