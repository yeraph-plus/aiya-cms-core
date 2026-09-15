<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\SlugToolkit\IdSlugEncoder;

/**
 * Afdian open-API client. Outbound requests sign md5 of
 * `token + "params" + params + "ts" + ts + "user_id" + user_id` (legacy
 * byte-for-byte). Inbound webhook pushes verify RSA-SHA256 against the
 * platform's published public key over the order's
 * out_trade_no + user_id + plan_id + total_amount concatenation, with the
 * base64 signature in `data.sign` (official WebHook doc; the earlier
 * md5-with-token reading was wrong — that scheme is outbound-only).
 * Transport is injected (returns the raw body or null) so this class
 * never touches WordPress. Surface mirrors the official open-API list:
 * ping / query-order / query-sponsor / query-plan / send-msg.
 */
final class AfdianClient
{
    private const API_ROOT = 'https://afdian.com/api/open/%s';

    /** The platform webhook verification key, published in the official doc. */
    private const WEBHOOK_PUBLIC_KEY = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwwdaCg1Bt+UKZKs0R54y
lYnuANma49IpgoOwNmk3a0rhg/PQuhUJ0EOZSowIC44l0K3+fqGns3Ygi4AfmEfS
4EKbdk1ahSxu7Zkp2rHMt+R9GarQFQkwSS/5x1dYiHNVMiR8oIXDgjmvxuNes2Cr
8fw9dEF0xNBKdkKgG2qAawcN1nZrdyaKWtPVT9m2Hl0ddOO9thZmVLFOb9NVzgYf
jEgI+KWX6aY19Ka/ghv/L4t1IXmz9pctablN5S0CRWpJW3Cn0k6zSXgjVdKm4uN7
jRlgSRaf/Ind46vMCm3N2sgwxu/g3bnooW+db0iLo13zzuvyn727Q3UDQ0MmZcEW
MQIDAQAB
-----END PUBLIC KEY-----';

    private IdSlugEncoder $binding;

    public function __construct(
        private string $userId,
        private string $token,
        private mixed $transport = null, // transport closure: takes (url, jsonBody), returns the response body or null
        private ?string $webhookPublicKey = null, // test seam: overrides the platform key
    ) {
        $this->binding = new IdSlugEncoder(8);
    }

    /** Encodes a site user id into the `custom_order_id` binding value. */
    public function bindUser(int $userId): string
    {
        return $this->binding->encodeId($userId);
    }

    /** Decodes the `custom_order_id` back to a site user id, 0 when malformed. */
    public function resolveUser(string $customOrderId): int
    {
        $decoded = $this->binding->decodeId($customOrderId);

        return is_numeric($decoded) ? (int) $decoded : 0;
    }

    /**
     * Official WebHook verification: the platform RSA-SHA256-signs the
     * order's out_trade_no, user_id, plan_id and total_amount concatenated
     * in that order; the base64 signature rides in `data.sign`. Anything
     * unsigned, malformed or signed by anyone else fails.
     *
     * @param array<string, mixed> $body
     */
    public function verifyWebhook(array $body): bool
    {
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            return false;
        }

        $order = $data['order'] ?? null;
        if (!is_array($order)) {
            return false;
        }

        $sign = (string) ($data['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        $signStr = (string) ($order['out_trade_no'] ?? '')
            . (string) ($order['user_id'] ?? '')
            . (string) ($order['plan_id'] ?? '')
            . (string) ($order['total_amount'] ?? '');

        $key = openssl_get_publickey($this->webhookPublicKey ?? self::WEBHOOK_PUBLIC_KEY);
        if ($key === false) {
            return false;
        }

        // Verifying the platform's base64-encoded RSA signature, not obfuscation.
        $raw = base64_decode($sign, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        return openssl_verify($signStr, (string) $raw, $key, 'sha256') === 1;
    }

    /**
     * Open-API health check. Returns the platform's `ec`: 200 = healthy,
     * 400002 = our ts rejected (server clock skew), 400004/400005 = the
     * configured user id/token or signature is wrong, 0 = unreachable.
     */
    public function ping(): int
    {
        $result = $this->query('ping', ['ping' => 'hello world']);

        return $result === null ? 0 : (int) ($result['ec'] ?? 0);
    }

    /**
     * One paid order by its platform trade number, or null when the call
     * fails or the order is absent.
     *
     * @return array<string, mixed>|null
     */
    public function queryOrder(string $outTradeNo): ?array
    {
        $result = $this->queryOrders(1, 50, $outTradeNo);

        return $result === null ? null : ($result['list'][0] ?? null);
    }

    /**
     * Paged order list, ordered by order create time descending.
     * `outTradeNos` optionally pins specific trade numbers, comma-separated
     * exactly as the platform documents.
     *
     * @return array{list: list<array<string, mixed>>, total_count: int, total_page: int}|null Null on transport or platform failure.
     */
    public function queryOrders(int $page = 1, int $perPage = 50, string $outTradeNos = ''): ?array
    {
        $params = ['page' => max(1, $page), 'per_page' => $perPage >= 1 ? min(100, $perPage) : 50];
        if ($outTradeNos !== '') {
            $params['out_trade_no'] = $outTradeNos;
        }

        return $this->paged('query-order', $params);
    }

    /**
     * Paged sponsor relationships, ordered by relationship creation time
     * descending. `userIds` optionally pins specific platform user ids,
     * comma-separated.
     *
     * @return array{list: list<array<string, mixed>>, total_count: int, total_page: int}|null
     */
    public function querySponsors(int $page = 1, int $perPage = 20, string $userIds = ''): ?array
    {
        $params = ['page' => max(1, $page), 'per_page' => max(1, min(100, $perPage))];
        if ($userIds !== '') {
            $params['user_id'] = $userIds;
        }

        return $this->paged('query-sponsor', $params);
    }

    /**
     * Plan detail by id. `product_type` distinguishes the plan kinds
     * (0 subscription, 1 goods, 2 bundle, 3 custom bundle, 4 ticket);
     * subscription plans carry no `skus`. Null on failure or unknown id.
     *
     * @return array<string, mixed>|null
     */
    public function queryPlan(string $planId): ?array
    {
        $result = $this->query('query-plan', ['plan_id' => $planId]);
        if ($result === null || (int) ($result['ec'] ?? 0) !== 200) {
            return null;
        }

        $plan = $result['data']['plan'] ?? null;

        return is_array($plan) ? $plan : null;
    }

    /**
     * Sends one platform private message to an Afdian user. The platform
     * throttles this endpoint (10/s, 1000/h) — callers must pace volume.
     */
    public function sendMsg(string $recipient, string $content): bool
    {
        $result = $this->query('send-msg', ['recipient' => $recipient, 'content' => $content]);

        return $result !== null && (int) ($result['ec'] ?? 0) === 200;
    }

    /**
     * Shared pager over the documented envelope (list + total_count +
     * total_page); null on transport or platform failure.
     *
     * @param array<string, mixed> $params
     * @return array{list: list<array<string, mixed>>, total_count: int, total_page: int}|null
     */
    private function paged(string $endpoint, array $params): ?array
    {
        $result = $this->query($endpoint, $params);
        if ($result === null || (int) ($result['ec'] ?? 0) !== 200) {
            return null;
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || !is_array($data['list'] ?? null)) {
            return null;
        }

        return [
            'list' => array_values(array_filter($data['list'], 'is_array')),
            'total_count' => (int) ($data['total_count'] ?? 0),
            'total_page' => (int) ($data['total_page'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function query(string $endpoint, array $params): ?array
    {
        if ($this->transport === null || $this->userId === '' || $this->token === '') {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- signature bytes must stay identical to the platform-side md5 over the same JSON
        $paramsJson = json_encode($params);
        $ts = time();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        $payload = json_encode([
            'user_id' => $this->userId,
            'params' => $paramsJson,
            'ts' => $ts,
            'sign' => md5("{$this->token}params{$paramsJson}ts{$ts}user_id{$this->userId}"),
        ]);

        if ($payload === false) {
            return null;
        }

        $body = ($this->transport)(sprintf(self::API_ROOT, $endpoint), $payload);
        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
