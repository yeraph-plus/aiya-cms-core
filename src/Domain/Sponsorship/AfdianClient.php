<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\SlugToolkit\IdSlugEncoder;

/**
 * Afdian open-API client. Signatures follow the legacy client byte-for-byte
 * (md5 of `token + "params" + params + "ts" + ts + "user_id" + user_id`);
 * the webhook check uses the platform's push scheme without the user_id
 * segment. Transport is injected (returns the raw body or null) so this
 * class never touches WordPress.
 */
final class AfdianClient
{
    private const API_ROOT = 'https://afdian.com/api/open/%s';

    private IdSlugEncoder $binding;

    public function __construct(
        private string $userId,
        private string $token,
        private mixed $transport = null, // fn(string $url, string $jsonBody): string|null
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
     * Webhook signature check: the platform signs md5(token + "params" +
     * data-json + "ts" + ts) over the pushed order payload (both sides use
     * PHP's default json_encode escaping).
     *
     * @param array<string, mixed> $body
     */
    public function verifyWebhook(array $body): bool
    {
        $sign = (string) ($body['sign'] ?? '');
        $ts = (string) ($body['ts'] ?? '');
        $data = $body['data'] ?? null;

        if ($sign === '' || $ts === '' || !is_array($data)) {
            return false;
        }

        $expected = md5("{$this->token}params" . json_encode($data) . "ts{$ts}");

        return hash_equals($expected, $sign);
    }

    public function ping(): bool
    {
        $result = $this->query('ping', ['ping' => 'hello world']);

        return $result !== null && (int) ($result['ec'] ?? 0) === 200;
    }

    /**
     * One paid order by its platform trade number, or null.
     *
     * @return array<string, mixed>|null
     */
    public function queryOrder(string $outTradeNo): ?array
    {
        $result = $this->query('query-order', ['out_trade_no' => $outTradeNo]);
        if ($result === null || (int) ($result['ec'] ?? 0) !== 200) {
            return null;
        }

        $first = $result['data']['list'][0] ?? null;

        return is_array($first) ? $first : null;
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

        $paramsJson = json_encode($params);
        $ts = time();
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
