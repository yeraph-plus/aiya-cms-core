<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Redeem;

use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Payment\AfdianGateway;
use Aiya\Core\Domain\Payment\OrderService;
use WP_Error;

/**
 * The Afdian order handler, owned by the redeem domain (2026-10-06
 * ruling): one ping→query-order→verify→settle sequence shared by both
 * triggers — the buyer typing the order number into the redeem box, and
 * the webhook whose push is only a hint carrying the trade number. Every
 * purchase fact (paid?, amount, cycles) is read from the platform's own
 * API answer; a push body is never trusted beyond its trade number, and
 * no RSA signature check is performed.
 *
 * The plan is not part of the settlement: every order the platform
 * carries settles into the single tier bound on the payments page
 * (AfdianGateway::boundTier); there is no multi-plan binding table and no
 * fallback tier. The site writes no
 * checkout row for Afdian purchases: a purchase the query vouches for
 * books straight into the paid log under the platform's own order id
 * (`afd_` prefix). The order-id unique keys in the payment log and the
 * entitlement queue make replays from either trigger idempotent — a
 * re-pushed or re-submitted number is a clean no-op, never a double
 * grant.
 */
final class AfdianRedemption
{
    // Aligned with the tier settings' cycles ceiling (the repeater field's
    // max): a queried order that really bought more months must not lose
    // cycles the tier promises. The clamp only bounds a garbage query row.
    private const MAX_CYCLES = 60;

    public function __construct(
        private AfdianGateway $gateway,
        private OrderService $orders,
        private EntitlementService $entitlements,
    ) {
    }

    /** Builds from the domain settings (null when Afdian is off/unconfigured). */
    public static function fromSettings(): ?self
    {
        $gateway = AfdianGateway::fromSettings();
        if ($gateway === null) {
            return null;
        }

        return new self($gateway, new OrderService(), new EntitlementService());
    }

    /**
     * Verifies one Afdian order number and activates it for the caller.
     *
     * @return array{tierKey:string, tierName:string, cycles:int}|WP_Error
     */
    public function redeem(int $userId, string $orderNo): array|WP_Error
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
     * as redeem(), attributed from the order's own deep-link binding.
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
        $ec = $this->gateway->client()->ping();
        if ($ec === 0) {
            return new WP_Error('aiya_afdian_unavailable', __('The Afdian API is unreachable — try again later.', 'aiya-core'), ['status' => 502]);
        }
        if ($ec !== 200) {
            $message = $ec === 400002
                ? __('Afdian rejected the request (expired timestamp) — check this server\'s clock.', 'aiya-core')
                : __('Afdian rejected the request — check the Afdian user id and API token on the payments settings page.', 'aiya-core');

            return new WP_Error('aiya_afdian_rejected', $message, ['status' => 502]);
        }

        $order = $this->gateway->client()->queryOrder($orderNo);
        if ($order === null) {
            // Null covers both "no such order" and a platform/transport
            // failure mid-conversation. A second ping tells those apart:
            // an API that answered the first ping and now fails it is an
            // outage, not a missing purchase — answer 502 so the caller
            // (and the webhook log) reads a retryable condition instead
            // of writing a real order off as never seen.
            if ($this->gateway->client()->ping() !== 200) {
                return new WP_Error('aiya_afdian_unavailable', __('The Afdian API is unreachable — try again later.', 'aiya-core'), ['status' => 502]);
            }

            return new WP_Error('aiya_order_not_found', __('No matching Afdian order — check the order number.', 'aiya-core'), ['status' => 404]);
        }

        // Official query-order doc: status 2 = 交易成功. The push's own
        // status field is never consulted — this answer is the authority.
        if ((int) ($order['status'] ?? 0) !== 2) {
            return new WP_Error('aiya_order_not_paid', __('This order is not a paid trade.', 'aiya-core'), ['status' => 422]);
        }

        // The single bound tier takes every order in — the platform plan
        // carries no settlement weight (2026-10-06 ruling).
        $tier = $this->gateway->boundTier();
        if ($tier === null) {
            return new WP_Error('aiya_plan_unbound', __('The Afdian tier is not configured on the payments settings page.', 'aiya-core'), ['status' => 422]);
        }

        // Attribution: an order placed through the personalized deep link
        // carries the account binding in custom_order_id.
        $boundUser = $this->gateway->client()->resolveUser((string) ($order['custom_order_id'] ?? ''));
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
            'orderId' => $this->gateway->orderId($orderNo),
            'userId' => $userId,
            'tier' => $tier,
            'cycles' => max(1, min(self::MAX_CYCLES, (int) ($order['month'] ?? 1))),
            'amount' => (float) ($order['total_amount'] ?? 0),
        ];
    }

    /**
     * Books the money, then queues the entitlement — both directly under
     * the platform's own order id. There is no local checkout row to
     * settle: the verified query is the booking authority, and the
     * order-id unique keys absorb replays from either trigger into one
     * booked row and one activation.
     *
     * @param array{orderId:string, userId:int, tier:array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}, cycles:int, amount:float} $resolved
     * @return true|WP_Error aiya_duplicate_order = already activated
     */
    private function bookAndActivate(array $resolved): bool|WP_Error
    {
        $recorded = $this->orders->addPayment($resolved['userId'], $resolved['orderId'], $resolved['tier']['key'], $resolved['amount'], 'afdian', $resolved['cycles']);
        if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
        }

        return $this->entitlements->activateFromPayment($resolved['userId'], $resolved['orderId'], $resolved['tier'], $resolved['cycles']);
    }
}
