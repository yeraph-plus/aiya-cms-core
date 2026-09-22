<?php

declare(strict_types=1);

namespace Aiya\Infra\Gofile;

use Closure;

/**
 * GoFile read client: one authenticated GET against the API base, with the
 * platform's `{status, data}` envelope read the way the platform asks for it —
 * branch on `status`, never on the HTTP code alone, because an answer can be
 * HTTP 200 carrying an error status.
 *
 * Read-only by construction: this package covers the account and content reads
 * only. The management endpoints (create, update, delete, move, copy, direct
 * links, token reset) and the upload fleet are deliberately not ported.
 *
 * The transport callable (fn(string $url, string $token):
 * array{status:int, body:string}|null) receives the account token on every
 * call and answers null when the request never completed, so tests never touch
 * the network and the client never assumes a WordPress HTTP API.
 */
final class Client
{
    public const DEFAULT_BASE = 'https://api.gofile.io';

    /**
     * @param Closure(string, string): (array{status:int, body:string}|null) $transport
     */
    public function __construct(
        private string $baseUrl,
        private string $token,
        private Closure $transport,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * One authenticated read: success returns the platform's `data` payload,
     * failure an Error carrying the platform's own status string.
     *
     * @param array<string, string|int> $query
     * @return array<string, mixed>|Error
     */
    public function get(string $path, array $query = []): array|Error
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = ($this->transport)($url, $this->token);
        if (!is_array($response)) {
            return new Error(Error::UNREACHABLE, 'The file service is unreachable.');
        }

        $http = (int) $response['status'];
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded) || !is_string($decoded['status'] ?? null)) {
            return new Error(
                Error::UNREACHABLE,
                'The file service returned an invalid response.',
                $http >= 400 ? $http : 502
            );
        }

        $status = (string) $decoded['status'];
        if ($status === 'ok') {
            $data = $decoded['data'] ?? [];

            return is_array($data) ? $data : [];
        }

        return new Error(self::classify($status, $http), $status, $http >= 400 ? $http : 502);
    }

    /**
     * The platform's status vocabulary, read into categories. The HTTP code is
     * the fallback for a status string this package has not seen before, so an
     * unfamiliar answer still lands in a usable bucket.
     */
    private static function classify(string $status, int $http): string
    {
        return match (true) {
            $status === 'error-notPremium' => Error::PREMIUM,
            in_array($status, ['error-token', 'error-wrongToken', 'error-owner', 'error-notOwner'], true) => Error::UNAUTHORIZED,
            in_array($status, ['error-accountId', 'error-limits'], true) => Error::DENIED,
            $status === 'error-notFound' => Error::NOT_FOUND,
            $status === 'error-rateLimit' => Error::RATE_LIMITED,
            str_starts_with($status, 'error-field') => Error::INVALID,
            default => match ($http) {
                401 => Error::UNAUTHORIZED,
                403 => Error::DENIED,
                404 => Error::NOT_FOUND,
                429 => Error::RATE_LIMITED,
                default => Error::INVALID,
            },
        };
    }
}
