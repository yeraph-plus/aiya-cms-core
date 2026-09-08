<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Epay (彩虹易支付) signature and submit-parameter builder, algorithm kept
 * byte-for-byte from the legacy SDK: md5 over ksort-ed `k=v&` pairs (skipping
 * sign/sign_type, empty values and the literal '0') with the merchant key
 * appended. The '0' skip is part of the verified wire behavior, so it is
 * preserved even though it looks odd. WordPress-free.
 */
final class EpayClient
{
    public function __construct(
        private string $pid,
        private string $key,
        private string $gatewayUrl,
    ) {
    }

    public function configured(): bool
    {
        return $this->pid !== '' && $this->key !== '' && $this->gatewayUrl !== '';
    }

    /**
     * Signs one cashier order and returns the full submit query (GET) for
     * the gateway's submit.php endpoint.
     *
     * @param array<string, string|int|float> $order out_trade_no / name / money / param / type
     */
    public function buildSubmitQuery(array $order, string $notifyUrl = '', string $returnUrl = ''): string
    {
        $order['pid'] = $this->pid;
        if ($notifyUrl !== '') {
            $order['notify_url'] = $notifyUrl;
        }
        if ($returnUrl !== '') {
            $order['return_url'] = $returnUrl;
        }

        $order['sign'] = $this->sign($order);
        $order['sign_type'] = 'MD5';

        return http_build_query($order);
    }

    public function submitUrl(string $submitQuery): string
    {
        $base = rtrim($this->gatewayUrl, '/') . '/submit.php';

        return $base . '?' . $submitQuery;
    }

    /**
     * Callback verification over the received query: recompute the
     * signature exactly the way the platform built it and compare.
     *
     * @param array<string, mixed> $callback
     */
    public function verifyCallback(array $callback): bool
    {
        if ($callback === [] || !isset($callback['sign']) || !is_string($callback['sign'])) {
            return false;
        }

        return hash_equals($this->sign($callback), $callback['sign']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sign(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'sign_type') {
                continue;
            }
            $value = (string) $value;
            if ($value === '' || $value === '0') {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return md5(implode('&', $pairs) . $this->key);
    }
}
