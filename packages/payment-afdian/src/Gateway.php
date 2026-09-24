<?php

declare(strict_types=1);

namespace Aiya\Infra\PaymentAfdian;

/**
 * The Afdian (爱发电) wire protocol on top of Client. Afdian is a
 * platform-push gateway, not a cashier: purchases arrive either as
 * webhook pushes — whose one trusted field is the trade number
 * (pushOrderNo); every other fact is re-read through the authenticated
 * open API — or through the buyer typing the order number into the site's
 * redeem box, which queries the same API. The "payment URL" is the
 * platform's order-create deep link carrying the site user binding in
 * custom_order_id — the tier is resolved from plan_id on the way back,
 * never from the amount.
 *
 * Everything environment-shaped arrives from the caller: the single bound
 * plan id, the tier key that plan activates (null = no plan bound, so the
 * deep link stays empty), and the human-readable remark the platform shows
 * the buyer (the caller owns the copy, this package carries no text).
 */
final class Gateway
{
    /** Payment-log order id prefix for this provider. */
    public const ORDER_PREFIX = 'afd_';

    private const ORDER_CREATE_URL = 'https://afdian.com/order/create';

    /**
     * @param string|null $boundTierKey tier the bound plan activates; null leaves the gateway unbound
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $planId,
        private readonly ?string $boundTierKey,
        private readonly string $remark = '',
    ) {
    }

    /**
     * The order-create deep link for the single bound plan on behalf of one
     * user. The binding's first segment (the encoded user id) becomes
     * custom_order_id and rides the checkout back into the webhook.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): string
    {
        $segments = explode('|', (string) $payment['binding']);

        return $this->orderUrl(
            $this->client->resolveUser($segments[0] ?? ''),
            (int) ($segments[2] ?? 0)
        );
    }

    /**
     * The personalized order-create deep link; empty when the plan binding
     * is unconfigured so callers can hide the jump. Params follow the
     * official order-create URL doc: plan_id + product_type=0 identify the
     * plan, custom_order_id carries the user binding, an optional month
     * pre-selects the cycle count (the buyer can still change it there).
     */
    public function orderUrl(int $userId, int $month = 0, ?string $planId = null): string
    {
        $planId ??= $this->planId;
        if ($planId === '' || $this->boundTierKey === null) {
            return '';
        }

        $url = self::ORDER_CREATE_URL . '?plan_id=' . rawurlencode($planId)
            . '&product_type=0'
            . '&custom_order_id=' . rawurlencode($this->client->bindUser($userId))
            . '&remark=' . rawurlencode($this->remark);
        if ($month > 0) {
            $url .= '&month=' . $month;
        }

        return $url;
    }

    /**
     * The one field a webhook push is trusted for: the platform trade
     * number. Everything else a push carries (status, amounts, plan) is
     * deliberately ignored — the site re-reads the whole order through the
     * authenticated open API and settles from the query's facts, so an
     * unauthenticated push can never plant them. Null when the body is
     * not an order push or carries no trade number.
     *
     * @param array<string, mixed> $body
     */
    public function pushOrderNo(array $body): ?string
    {
        $order = $body['data']['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $outTradeNo = (string) ($order['out_trade_no'] ?? '');

        return $outTradeNo !== '' ? $outTradeNo : null;
    }
}
