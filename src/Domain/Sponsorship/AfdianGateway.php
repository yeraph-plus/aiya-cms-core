<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * The Afdian (爱发电) adapter behind PaymentGateway. Afdian is a
 * platform-push gateway, not a cashier: orders arrive either as signed
 * webhook pushes (verifyCallback) or through the buyer typing the order
 * number into the redeem box (AfdianActivator's queryOrder). The
 * "payment URL" is the platform's own order-create deep link carrying the
 * site user binding in custom_order_id — the tier is resolved from
 * plan_id on the way back, never from the amount.
 */
final class AfdianGateway implements PaymentGateway
{
    private const ORDER_PREFIX = 'afd_';
    private const MAX_CYCLES = 36;

    /**
     * @param array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null $boundTier
     */
    public function __construct(
        private AfdianClient $client,
        private bool $enabled,
        private string $planId,
        private ?array $boundTier,
        private string $siteName,
    ) {
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = SponsorshipSettings::read();
        if (!$settings['afdianEnable'] || $settings['afdianUserId'] === '' || $settings['afdianToken'] === '') {
            return null;
        }

        // The real HTTP transport for the open API: raw JSON in, response
        // body out (API-level errors ride HTTP 200 with their own ec).
        $client = new AfdianClient(
            $settings['afdianUserId'],
            $settings['afdianToken'],
            static function (string $url, string $jsonBody): ?string {
                $response = wp_remote_post($url, [
                    'timeout' => 20,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => $jsonBody,
                ]);
                if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500) {
                    return null;
                }

                $body = wp_remote_retrieve_body($response);

                return is_string($body) && $body !== '' ? $body : null;
            }
        );

        $bound = SponsorshipSettings::boundTier($settings);

        return new self($client, true, $settings['afdianPlanId'], $bound, (string) get_bloginfo('name'));
    }

    public function client(): AfdianClient
    {
        return $this->client;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    /** @return array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null */
    public function boundTier(): ?array
    {
        return $this->boundTier;
    }

    public function id(): string
    {
        return 'afdian';
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Afdian never rides the cashier; the platform owns its checkout. */
    public function channels(): array
    {
        return [];
    }

    /**
     * The order-create deep link for the single bound plan on behalf of
     * one user. The binding's first segment (the encoded user id) becomes
     * custom_order_id and rides the checkout back into the webhook.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): string
    {
        $segments = explode('|', (string) $payment['binding']);

        return $this->orderUrl($this->client->resolveUser($segments[0] ?? ''), (int) ($segments[2] ?? 0));
    }

    /**
     * The personalized order-create deep link; empty when the plan
     * binding is unconfigured so callers can hide the jump. Params follow
     * the official order-create URL doc: plan_id + product_type=0
     * identify the plan, custom_order_id carries the user binding, an
     * optional month pre-selects the cycle count (the buyer can still
     * change it there).
     */
    public function orderUrl(int $userId, int $month = 0): string
    {
        if ($this->planId === '' || $this->boundTier === null) {
            return '';
        }

        $remark = rawurlencode(sprintf('来自「%s」的会员订单', $this->siteName));
        $url = 'https://afdian.com/order/create?plan_id=' . rawurlencode($this->planId)
            . '&product_type=0'
            . '&custom_order_id=' . rawurlencode($this->client->bindUser($userId))
            . '&remark=' . $remark;
        if ($month > 0) {
            $url .= '&month=' . $month;
        }

        return $url;
    }

    /**
     * Verifies a webhook push and resolves the payment description. Null
     * means not activatable: bad signature (see callbackFailed), a push
     * that is not a successful trade (status 2 / type order), no user
     * binding, or an order whose plan is not the bound one (e.g. the
     * optional amount plan) — the platform hears {ec:200} so it stops
     * retrying.
     *
     * @param array<string, mixed> $body
     * @return array{orderId:string, userId:int, tierKey:string, cycles:int, amount:float}|null
     */
    public function verifyCallback(array $body): ?array
    {
        if (!$this->client->verifyWebhook($body)) {
            return null;
        }

        $data = $body['data'] ?? null;
        if (!is_array($data) || (string) ($data['type'] ?? '') !== 'order') {
            return null;
        }

        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        // Official webhook doc: status 2 = 交易成功; anything else is not
        // money the site can book (pending, refunded, …).
        if ((int) ($order['status'] ?? 0) !== 2) {
            return null;
        }

        $userId = $this->client->resolveUser((string) ($order['custom_order_id'] ?? ''));
        if ($userId <= 0) {
            return null;
        }

        // Single binding: only pushes for the configured plan activate.
        if ((string) ($order['plan_id'] ?? '') !== $this->planId || $this->boundTier === null) {
            return null;
        }

        $outTradeNo = (string) ($order['out_trade_no'] ?? '');
        if ($outTradeNo === '') {
            return null;
        }

        return [
            'orderId' => self::ORDER_PREFIX . $outTradeNo,
            'userId' => $userId,
            'tierKey' => $this->boundTier['key'],
            'cycles' => max(1, min(self::MAX_CYCLES, (int) ($order['month'] ?? 1))),
            'amount' => (float) ($order['total_amount'] ?? 0),
        ];
    }

    /**
     * Only a signature mismatch is a push the platform must hear about
     * (400). A validly signed push without an activatable description
     * (gift without binding, unbound plan) answers success.
     *
     * @param array<string, mixed> $body
     */
    public function callbackFailed(array $body): bool
    {
        return !$this->client->verifyWebhook($body);
    }
}
