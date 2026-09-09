<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\ExternalFiles;

use WP_Error;

/**
 * OpenList (alist-family) read client: login and the four read-only fs
 * operations the attachment surfacing needs. Write operations are
 * deliberately not ported from the legacy client. The transport callable
 * (fn(string $method, string $url, ?string $jsonBody, string $token):
 * array{status:int, body:string}|null) receives the bearer token on every
 * call so tests never touch the network; errors come back as WP_Error
 * with stable codes the REST layer maps to statuses.
 */
final class OpenListClient
{
    private const FS_ENDPOINTS = [
        'list' => '/api/fs/list',
        'get' => '/api/fs/get',
        'dirs' => '/api/fs/dirs',
        'search' => '/api/fs/search',
    ];

    public function __construct(
        private string $server,
        private string $token,
        private mixed $transport = null,
    ) {
        $this->server = rtrim($server, '/');
    }

    public function ping(): bool
    {
        $response = ($this->transport)('GET', $this->server . '/ping', null, '');

        return is_array($response) && $response['status'] === 200 && $response['body'] === 'pong';
    }

    /**
     * Exchanges credentials for a bearer token.
     *
     * @return string|WP_Error
     */
    public function login(string $username, string $password, ?string $otpCode = null): string|WP_Error
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        $response = ($this->transport)('POST', $this->server . '/api/auth/login', (string) json_encode([
            'username' => $username,
            'password' => $password,
            'otp_code' => $otpCode,
        ]), '');

        return $this->decodeToken($response);
    }

    /**
     * Runs one read-only fs operation ('list' | 'get' | 'dirs' | 'search').
     * Success returns the platform data payload (possibly an empty array);
     * failures return WP_Error with codes aiya_oplist_unavailable / _auth /
     * _denied / _not_found.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    public function fs(string $method, array $payload): array|WP_Error
    {
        $endpoint = self::FS_ENDPOINTS[$method] ?? null;
        if ($endpoint === null) {
            return new WP_Error('aiya_oplist_error', __('Unsupported OpenList operation.', 'aiya-core'));
        }

        $response = ($this->transport)('POST', $this->server . $endpoint, (string) json_encode($payload), $this->token); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        if (!is_array($response)) {
            return new WP_Error('aiya_oplist_unavailable', __('The file service is unreachable.', 'aiya-core'), ['status' => 502]);
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            return new WP_Error('aiya_oplist_unavailable', __('The file service returned an invalid response.', 'aiya-core'), ['status' => 502]);
        }

        $code = (int) ($data['code'] ?? 0);
        if ($code === 200) {
            $payload = $data['data'] ?? [];

            return is_array($payload) ? $payload : [];
        }

        [$errorCode, $status] = match (true) {
            $response['status'] === 401 || $code === 401 => ['aiya_oplist_auth', 502],
            $response['status'] === 403 || $code === 403 => ['aiya_oplist_denied', 502],
            in_array($response['status'], [404, 500], true) || in_array($code, [404, 500], true) => ['aiya_oplist_not_found', 404],
            default => ['aiya_oplist_unavailable', 502],
        };

        return new WP_Error($errorCode, sprintf('%s (%s)', (string) ($data['message'] ?? 'OpenList error'), $code), ['status' => $status]);
    }

    /** @return string|WP_Error */
    private function decodeToken(mixed $response): string|WP_Error
    {
        if (!is_array($response)) {
            return new WP_Error('aiya_oplist_unavailable', __('The file service is unreachable.', 'aiya-core'), ['status' => 502]);
        }

        $data = json_decode((string) $response['body'], true);
        if ((int) ($data['code'] ?? 0) === 200 && isset($data['data']['token']) && is_string($data['data']['token']) && $data['data']['token'] !== '') {
            return $data['data']['token'];
        }

        return new WP_Error('aiya_oplist_auth', sprintf('%s (%s)', (string) ($data['message'] ?? 'Login failed'), (string) ($data['code'] ?? '?')), ['status' => 502]);
    }
}
