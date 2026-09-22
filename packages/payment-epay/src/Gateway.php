<?php

declare(strict_types=1);

namespace Aiya\Infra\PaymentEpay;

use Aiya\Infra\SlugToolkit\IdSlugEncoder;

/**
 * The Epay (彩虹易支付) wire protocol: the cashier's submit-parameter set
 * and the callback shape, on top of the signing primitive in Client.
 * Everything provider-shaped lives here — the out_trade_no/name/money/
 * param/type parameter names, the TRADE_SUCCESS status, the order-id
 * prefix, the user|tier|cycles binding split — and nothing
 * environment-shaped does: the notify/return URLs and the set of
 * acceptable tier keys arrive from the caller, which owns the settings,
 * WordPress and the money/rights writes.
 *
 * The binding codec is the site's own XDE encoding (the slug-toolkit
 * package, also WordPress-free); it is a wire contract with the platform's
 * `param` field, so it is decoded here rather than handed in.
 */
final class Gateway
{
    /** Payment-log order id prefix for this provider. */
    public const ORDER_PREFIX = 'epc_';

    /**
     * @param list<string> $tierKeys tier keys a callback may activate; empty refuses every tier
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $notifyUrl,
        private readonly string $returnUrl,
        private readonly array $tierKeys = [],
    ) {
    }

    /** True when the credentials are complete (the caller decides what to do about it). */
    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * The signed cashier URL for one order. The caller has already
     * accepted the channel; this only speaks Epay.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): string
    {
        $submitQuery = $this->client->buildSubmitQuery([
            'out_trade_no' => (string) $payment['orderId'],
            'name' => (string) $payment['title'],
            'money' => number_format(round((float) $payment['amount'], 2), 2, '.', ''),
            'param' => (string) $payment['binding'],
            'type' => (string) $payment['channel'],
        ], $this->notifyUrl, $this->returnUrl);

        return $this->client->submitUrl($submitQuery);
    }

    /**
     * Verifies a callback and resolves the domain's payment description.
     * Null means not activatable: bad signature, a non-success trade
     * status, a malformed binding, or a tier this site does not sell.
     *
     * @param array<string, mixed> $query
     * @return array{orderId:string, userId:int, tierKey:string, cycles:int, amount:float}|null
     */
    public function verifyCallback(array $query): ?array
    {
        if (!$this->client->verifyCallback($query)) {
            return null;
        }
        if ((string) ($query['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return null;
        }

        $outTradeNo = (string) ($query['out_trade_no'] ?? '');
        $binding = (string) ($query['param'] ?? '');
        if ($outTradeNo === '' || $binding === '') {
            return null;
        }

        [$userBinding, $tierKey, $cyclesRaw] = array_pad(explode('|', $binding, 3), 3, '');
        $userId = (int) (new IdSlugEncoder(8))->decodeId($userBinding);
        $tierKey = self::sanitizeKey($tierKey);
        $cycles = abs((int) $cyclesRaw);

        if ($userId <= 0 || $tierKey === '' || $cycles < 1) {
            return null;
        }
        if (!in_array($tierKey, $this->tierKeys, true)) {
            return null;
        }

        return [
            'orderId' => self::ORDER_PREFIX . $outTradeNo,
            'userId' => $userId,
            'tierKey' => $tierKey,
            'cycles' => $cycles,
            // The actually-paid amount from the platform, not a settings
            // recompute — the money log must reflect what was paid even if
            // the tier price changed between checkout and callback.
            'amount' => round((float) ($query['money'] ?? 0), 2),
        ];
    }

    /**
     * True when a push carries an invalid signature — the one callback
     * failure mode the platform must hear about (the caller answers 400).
     * A validly signed push we merely cannot use returns false: answer
     * success and move on.
     *
     * @param array<string, mixed> $query
     */
    public function callbackFailed(array $query): bool
    {
        return !$this->client->verifyCallback($query);
    }

    /** Mirrors WordPress's sanitize_key(): lowercase, ASCII word characters only. */
    private static function sanitizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $key));
    }
}
