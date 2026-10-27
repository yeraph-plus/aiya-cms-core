<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Redeem;

use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Payment\AfdianGateway;
use Aiya\Core\Domain\Payment\AfdianTiers;
use Aiya\Core\Domain\Payment\OrderService;
use WP_Error;

/**
 * The Afdian order handler, owned by the redeem domain (2026-10-06
 * ruling): one ping→query-order→verify→settle sequence shared by both
 * triggers — the buyer typing the order number into the redeem box, and
 * the webhook whose push is only a hint carrying the trade number. Every
 * purchase fact (paid?, amount, cycles, plan) is read from the platform's
 * own API answer; a push body is never trusted beyond its trade number, and
 * no RSA signature check is performed.
 *
 * The plan IS part of the settlement (2026-10-27 decoupling): the order's
 * `plan_id` selects the row of the site's own Afdian plan table, and that
 * row supplies the entitlement snapshot. A plan the site has not registered
 * settles into nothing: the money is BOOKED under the platform's own order
 * id with an empty tier key — never swallowed, never granted — and a later
 * replay of the same order number activates it once the row exists. Adding
 * a plan is therefore a configuration change, not a migration, and every
 * paid order keeps a visible trace either way.
 *
 * The site writes no checkout row for Afdian purchases: a purchase the
 * query vouches for books straight into the paid log under the platform's
 * own order id (`afd_` prefix). The order-id unique keys in the payment log
 * and the entitlement queue make replays from either trigger idempotent — a
 * re-pushed or re-submitted number is a clean no-op, never a double grant.
 */
final class AfdianRedemption
{
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
     * @param string $buyerId The caller's Afdian user id, required only when
     *                        the order carries no deep-link binding: without
     *                        it the order number alone would let the first
     *                        person to type it claim somebody else's purchase.
     *
     * @return array{tierKey:string, tierName:string, cycles:int}|WP_Error
     */
    public function redeem(int $userId, string $orderNo, string $buyerId = ''): array|WP_Error
    {
        $resolved = $this->resolvePurchase($orderNo, $userId, $buyerId);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $activated = $this->bookAndActivate($resolved);
        if (is_wp_error($activated)) {
            $code = $activated->get_error_code();
            if ($code === 'aiya_duplicate_order') {
                return new WP_Error(
                    'aiya_order_used',
                    __('This order has already been activated.', 'aiya-core'),
                    ['status' => 409]
                );
            }
            // "Booked but unregistered" is not an activation failure: the
            // caller must hear precisely that, or they will re-type the
            // number forever against a site that already has their money.
            if ($code === 'aiya_plan_unregistered') {
                return $activated;
            }

            return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
        }

        return [
            'tierKey' => $activated['key'],
            'tierName' => $activated['name'],
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
            return match ($activated->get_error_code()) {
                'aiya_duplicate_order' => "already activated: order {$orderNo} (idempotent replay).",
                'aiya_plan_unregistered' => sprintf(
                    'booked, plan unregistered: order %1$s (plan "%2$s") for user #%3$d — money recorded, nothing granted until the plan is listed.',
                    $orderNo,
                    $resolved['planId'],
                    $resolved['userId']
                ),
                default => 'failed: ' . $activated->get_error_code() . " (order {$orderNo}).",
            };
        }

        return sprintf(
            'activated order %1$s: tier %2$s ×%3$d for user #%4$d (amount %5$.2f).',
            $orderNo,
            $activated['key'],
            $resolved['cycles'],
            $resolved['userId'],
            $resolved['amount']
        );
    }

    /**
     * The shared ping→query→verify chain. Returns the purchase the API
     * vouches for, with the holder resolved per trigger: the webhook
     * ($claimant null) trusts only the deep-link binding, the manual
     * path claims a bound order for its owner and an unbound one only for
     * the buyer whose Afdian user id it can verify.
     *
     * `tier` is null when the order's plan is not listed on the site — that
     * is a settlement state, not a verification failure, so the caller still
     * books the money.
     *
     * @return array{orderNo:string, orderId:string, planId:string, userId:int, tier:array{key:string,name:string,description:string,enabled:bool,cycleDays:int,creditsPerCycle:int,maxCycles:int}|null, cycles:int, amount:float}|WP_Error
     */
    private function resolvePurchase(string $orderNo, ?int $claimant, string $buyerId = ''): array|WP_Error
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

        // The plan the buyer actually paid for selects the tier. No amount
        // comparison happens anywhere: `plan_id` is an identity, not a price
        // — and `total_amount` is booked as reported, never checked.
        $planId = trim((string) ($order['plan_id'] ?? ''));
        $tier = $this->gateway->tierForPlan($planId);

        // Attribution: an order placed through the personalized deep link
        // carries the account binding in custom_order_id. A storefront
        // order carries none, and the trade number is not a secret — it
        // appears in screenshots, support tickets and group chats — so the
        // manual path additionally requires the caller's own Afdian user
        // id to match the buyer the platform reports. First-come claiming
        // would otherwise let any holder of a number lock the real buyer
        // out for good.
        $boundUser = $this->gateway->client()->resolveUser((string) ($order['custom_order_id'] ?? ''));
        if ($claimant !== null) {
            if ($boundUser > 0) {
                if ($boundUser !== $claimant) {
                    return new WP_Error('aiya_order_bound', __('This order is bound to another account.', 'aiya-core'), ['status' => 409]);
                }
                $userId = $claimant;
            } else {
                $buyer = trim($buyerId);
                if ($buyer === '') {
                    return new WP_Error(
                        'aiya_order_buyer_required',
                        __('This order carries no site link, so the order number alone cannot identify its buyer — enter your Afdian user id (the /u/… part of your Afdian profile URL) to claim it.', 'aiya-core'),
                        ['status' => 422],
                    );
                }

                $owner = trim((string) ($order['user_id'] ?? ''));
                if ($owner === '' || !hash_equals($owner, $buyer)) {
                    return new WP_Error('aiya_order_buyer_mismatch', __('That order belongs to a different Afdian account.', 'aiya-core'), ['status' => 403]);
                }
                $userId = $claimant;
            }
        } elseif ($boundUser <= 0) {
            // Placed on the platform directly: the webhook has no caller to
            // ask for the buyer id, so it leaves attribution to the account
            // page.
            return new WP_Error('aiya_order_unattributed', __('The order carries no account binding — activate it from the account page with the order number.', 'aiya-core'), ['status' => 422]);
        } else {
            $userId = $boundUser;
        }

        // The queried month is platform truth; the order's own plan sets how
        // many cycles one purchase may queue, and the settings-wide ceiling
        // bounds a garbage row. An unregistered plan has no row to ask, so
        // the global ceiling applies (the booking records what was bought).
        $cycles = max(1, min(
            AfdianTiers::MAX_CYCLES,
            $tier['maxCycles'] ?? AfdianTiers::MAX_CYCLES,
            (int) ($order['month'] ?? 1)
        ));

        return [
            'orderNo' => $orderNo,
            'orderId' => $this->gateway->orderId($orderNo),
            'planId' => $planId,
            'userId' => $userId,
            'tier' => $tier,
            'cycles' => $cycles,
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
     * An unregistered plan books with an empty tier key and stops there
     * (D1: 落账不激活). The booking is what makes a later replay work: the
     * duplicate answer is tolerated, and once the plan is listed the same
     * order number walks on to the activation — the row that already
     * exists is exactly what the entitlement's unique key needs.
     *
     * @param array{orderId:string, planId:string, userId:int, tier:array{key:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|null, cycles:int, amount:float} $resolved
     * @return array{key:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|WP_Error true-equivalent = the tier it activated; aiya_duplicate_order = already activated; aiya_plan_unregistered = booked, nothing granted
     */
    private function bookAndActivate(array $resolved): array|WP_Error
    {
        $tierKey = $resolved['tier']['key'] ?? '';
        $recorded = $this->orders->addPayment($resolved['userId'], $resolved['orderId'], $tierKey, $resolved['amount'], 'afdian', $resolved['cycles']);
        if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
        }

        // The booked row — not the caller in hand — names who paid. Two
        // separate writes (money, then rights) leave a window where the
        // money is booked and the activation failed; a replay from another
        // account must not close that window under its own name, so read
        // the holder back and activate for them. Same account in the
        // normal case, so this costs one read and changes nothing.
        $booked = $this->orders->orderRow($resolved['orderId']);
        if ($booked === null) {
            return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
        }

        if ($resolved['tier'] === null) {
            return new WP_Error(
                'aiya_plan_unregistered',
                __('This order was paid for a plan the site has not registered yet — the payment is recorded; contact the site owner to have it settled.', 'aiya-core'),
                ['status' => 422]
            );
        }

        // Late registration: the row was booked before its plan was listed.
        // Naming the tier now keeps the ledger readable (the audit page shows
        // the todo as an empty tier key). Only a blank key is filled — a
        // settled row's tier is history and never rewritten.
        if ((string) $booked['tier_key'] === '') {
            $this->orders->annotateTier($resolved['orderId'], $tierKey);
        }

        $activated = $this->entitlements->activateFromPayment((int) $booked['user_id'], $resolved['orderId'], $resolved['tier'], $resolved['cycles']);
        if (is_wp_error($activated)) {
            return $activated;
        }

        return $resolved['tier'];
    }
}
