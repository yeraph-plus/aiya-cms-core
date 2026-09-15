<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * The Afdian self-service activation (the redeem box's "afdian" channel):
 * the buyer types the platform order number, the service pings the open
 * API, looks the order up, resolves its plan into a bound tier and runs
 * the same activateFromPayment settle as the webhook path. Order ids
 * carry the `afd_` namespace prefix; the unique keys in the payment log
 * and the entitlement queue make a re-submitted number a clean 409.
 */
final class AfdianActivator
{
    private const MAX_CYCLES = 36;

    /**
     * @param array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null $boundTier
     */
    public function __construct(
        private AfdianClient $client,
        private OrderService $orders,
        private EntitlementService $entitlements,
        private string $planId,
        private ?array $boundTier,
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
            $gateway->planId(),
            $gateway->boundTier(),
        );
    }

    /**
     * Verifies one Afdian order number and activates it for the caller.
     *
     * @return array{tierKey:string, tierName:string, cycles:int}|WP_Error
     */
    public function activate(int $userId, string $orderNo): array|WP_Error
    {
        // Official open-API ec table: 200 ok, 0 = no answer (this side),
        // 400002 ts expired (clock skew), 400004/400005 bad credentials.
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

        $orderId = 'afd_' . $orderNo;

        $order = $this->client->queryOrder($orderNo);
        if ($order === null) {
            return new WP_Error('aiya_order_not_found', __('No matching Afdian order — check the order number.', 'aiya-core'), ['status' => 404]);
        }

        // Official query-order doc: status 2 = 交易成功.
        if ((int) ($order['status'] ?? 0) !== 2) {
            return new WP_Error('aiya_order_not_paid', __('This order is not a paid trade.', 'aiya-core'), ['status' => 422]);
        }

        // Single binding: the order's plan must be the configured one.
        if ($this->planId === '' || (string) ($order['plan_id'] ?? '') !== $this->planId || $this->boundTier === null) {
            return new WP_Error('aiya_plan_unbound', __('This order\'s Afdian plan is not bound to a membership tier.', 'aiya-core'), ['status' => 422]);
        }
        $tier = $this->boundTier;

        // Webhook attribution: an order placed through the personalized
        // deep link carries a custom_order_id binding. If it decodes to a
        // DIFFERENT holder than the caller, the buyer owns this order —
        // the caller may not claim it.
        $boundUser = $this->client->resolveUser((string) ($order['custom_order_id'] ?? ''));
        if ($boundUser > 0 && $boundUser !== $userId) {
            return new WP_Error('aiya_order_bound', __('This order is bound to another account.', 'aiya-core'), ['status' => 409]);
        }

        $cycles = max(1, min(self::MAX_CYCLES, (int) ($order['month'] ?? 1)));

        // No exists() pre-check here: addPayment/activateFromPayment are
        // idempotent on the order-id unique keys, so a half-done earlier
        // attempt completes on retry instead of hitting a permanent 409.
        $recorded = $this->orders->addPayment($userId, $orderId, $tier['key'], (float) ($order['total_amount'] ?? 0), 'afdian');
        if (is_wp_error($recorded) && $recorded->get_error_code() !== 'aiya_duplicate_order') {
            return new WP_Error('aiya_activation_failed', __('The membership activation failed — try again.', 'aiya-core'), ['status' => 502]);
        }

        $activated = $this->entitlements->activateFromPayment($userId, $orderId, $tier, $cycles);
        if (is_wp_error($activated)) {
            $code = $activated->get_error_code() === 'aiya_duplicate_order'
                ? 'aiya_order_used'
                : 'aiya_activation_failed';

            return new WP_Error($code, $code === 'aiya_order_used'
                ? __('This order has already been activated.', 'aiya-core')
                : __('The membership activation failed — try again.', 'aiya-core'), ['status' => $code === 'aiya_order_used' ? 409 : 502]);
        }

        return [
            'tierKey' => $this->boundTier['key'],
            'tierName' => $this->boundTier['name'],
            'cycles' => $cycles,
        ];
    }
}
