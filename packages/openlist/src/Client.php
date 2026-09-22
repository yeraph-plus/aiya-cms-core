<?php

declare(strict_types=1);

namespace Aiya\Infra\OpenList;

use Closure;

/**
 * OpenList (alist-family) read client: login and the read-only fs operations
 * the file listings need. Write operations are deliberately not ported.
 *
 * The transport callable
 * (fn(string $method, string $url, ?string $jsonBody, string $token):
 * array{status:int, body:string}|null) receives the bearer token on every call
 * and answers null when the request never completed, so tests never touch the
 * network and the client never assumes a WordPress HTTP API. Errors come back
 * as package Errors with a stable category.
 */
final class Client
{
    private const FS_ENDPOINTS = [
        'list' => '/api/fs/list',
        'get' => '/api/fs/get',
        'dirs' => '/api/fs/dirs',
        'search' => '/api/fs/search',
    ];

    /**
     * @param Closure(string, string, ?string, string): (array{status:int, body:string}|null) $transport
     */
    public function __construct(
        private string $server,
        private string $token,
        private Closure $transport,
    ) {
        $this->server = rtrim($server, '/');
    }

    public function ping(): bool
    {
        $response = ($this->transport)('GET', $this->server . '/ping', null, '');

        return is_array($response) && $response['status'] === 200 && $response['body'] === 'pong';
    }

    /** Exchanges credentials for a bearer token. */
    public function login(string $username, string $password, ?string $otpCode = null): string|Error
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        $body = (string) json_encode([
            'username' => $username,
            'password' => $password,
            'otp_code' => $otpCode,
        ]);
        $response = ($this->transport)('POST', $this->server . '/api/auth/login', $body, '');
        if (!is_array($response)) {
            return new Error(Error::UNREACHABLE, 'The file service is unreachable.');
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            // An unparseable body (an HTML error page, a proxy notice) says
            // nothing about the credentials: that is the same classification
            // fs() gives a garbage body, and it carries the HTTP status so
            // the caller can tell a 502 page from a 200 of wrong shape.
            return new Error(
                Error::UNREACHABLE,
                sprintf('The file service returned an invalid response (HTTP %d).', (int) $response['status'])
            );
        }

        if ((int) ($data['code'] ?? 0) === 200) {
            $token = $data['data']['token'] ?? null;
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return new Error(
            Error::UNAUTHORIZED,
            sprintf('Login failed (%s)', self::platformMessage($data))
        );
    }

    /**
     * Runs one read-only fs operation ('list' | 'get' | 'dirs' | 'search').
     * Success returns the platform data payload (an empty array is a valid
     * empty listing); failures return an Error.
     *
     * @param array<string, mixed> $payload
     * @return array<int|string, mixed>|Error
     */
    public function fs(string $method, array $payload): array|Error
    {
        $endpoint = self::FS_ENDPOINTS[$method] ?? null;
        if ($endpoint === null) {
            return new Error(Error::INVALID, sprintf('Unsupported file operation "%s".', $method));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        $body = (string) json_encode($payload);
        $response = ($this->transport)('POST', $this->server . $endpoint, $body, $this->token);
        if (!is_array($response)) {
            return new Error(Error::UNREACHABLE, 'The file service is unreachable.');
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            return new Error(Error::UNREACHABLE, 'The file service returned an invalid response.');
        }

        $code = (int) ($data['code'] ?? 0);
        if ($code === 200) {
            $payload = $data['data'] ?? [];

            return is_array($payload) ? $payload : [];
        }

        [$failureCode, $status] = self::classify((int) $response['status'], $code);

        return new Error(
            $failureCode,
            sprintf('%s (%s)', self::platformMessage($data, 'OpenList error'), $code),
            $status
        );
    }

    /**
     * The platform's own error taxonomy, mapped onto the package's categories.
     *
     * The platform folds several upstream faults into one: alist answers
     * HTTP 500 (or body code 500) for anything it cannot do — "failed get
     * storage", a broken driver, a hung backend. Those surface here as
     * NOT_FOUND, the "nothing readable at that path" answer. That is a
     * knowing trade: the site treats a failed group as absent from the
     * public listing either way, so the folded classification changes the
     * reported wording, not the public behavior.
     *
     * @return array{0: string, 1: int} category and the HTTP answer it deserves
     */
    private static function classify(int $status, int $code): array
    {
        return match (true) {
            $status === 401 || $code === 401 => [Error::UNAUTHORIZED, 502],
            $status === 403 || $code === 403 => [Error::DENIED, 502],
            in_array($status, [404, 500], true) || in_array($code, [404, 500], true) => [Error::NOT_FOUND, 404],
            default => [Error::UNREACHABLE, 502],
        };
    }

    private static function platformMessage(mixed $data, string $fallback = 'Login failed'): string
    {
        $message = is_array($data) ? ($data['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
