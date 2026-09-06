<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use RuntimeException;

/**
 * Opaque bearer tokens for the headless API, stored per user as HMAC
 * hashes in user meta (never plaintext). The plain token carries the user
 * id as its first segment (`{userId}.{secret}`) so presenting it resolves
 * with a single meta read; only the HMAC of the secret part is stored.
 *
 * Several devices may hold valid tokens at once; expired entries are
 * pruned on write and a cap bounds the list. Changing the password
 * revokes everything (see revokeAll()).
 *
 * TTLs mirror the classic WordPress cookie lifetimes: 14 days when the
 * client asks to be remembered, 2 days otherwise.
 */
final class TokenStore
{
    private const META_KEY = 'aiya_core_auth_tokens';
    private const MAX_TOKENS_PER_USER = 10;
    public const SHORT_TTL = 2 * DAY_IN_SECONDS;
    public const LONG_TTL = 14 * DAY_IN_SECONDS;

    /**
     * @throws RuntimeException When the user does not exist.
     */
    public function issue(int $userId, bool $remember = false): AuthToken
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            throw new RuntimeException(__('No user to issue a token for.', 'aiya-core'));
        }

        $plain = $userId . '.' . wp_generate_password(64, false, false);
        $expiresAt = time() + ($remember ? self::LONG_TTL : self::SHORT_TTL);

        $stored = $this->storedTokens($userId);
        $stored[] = [
            'hash' => $this->hash($plain),
            'created' => time(),
            'expires' => $expiresAt,
        ];

        update_user_meta($userId, self::META_KEY, array_slice($stored, -self::MAX_TOKENS_PER_USER));

        return new AuthToken($plain, $expiresAt);
    }

    /** Resolves a presented token to a user id; 0 when unknown or expired. */
    public function resolve(string $token): int
    {
        $userId = $this->parseUserId($token);
        if ($userId === 0) {
            return 0;
        }

        $hash = $this->hash($token);
        foreach ($this->storedTokens($userId) as $entry) {
            if (is_string($entry['hash'] ?? null) && hash_equals($entry['hash'], $hash)) {
                $expires = (int) ($entry['expires'] ?? 0);
                if ($expires > 0 && $expires < time()) {
                    return 0;
                }

                return $userId;
            }
        }

        return 0;
    }

    /** Removes one presented token (logout). */
    public function revoke(string $token): void
    {
        $userId = $this->parseUserId($token);
        if ($userId === 0) {
            return;
        }

        $hash = $this->hash($token);
        $entries = $this->storedTokens($userId);
        $kept = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => !is_string($entry['hash'] ?? null) || !hash_equals($entry['hash'], $hash)
        ));
        if (count($kept) !== count($entries)) {
            update_user_meta($userId, self::META_KEY, $kept);
        }
    }

    /** Invalidates every token of a user (password change / reset). */
    public function revokeAll(int $userId): void
    {
        if ($userId > 0) {
            delete_user_meta($userId, self::META_KEY);
        }
    }

    /** Splits `{userId}.{secret}`; 0 for anything malformed. */
    private function parseUserId(string $token): int
    {
        $separator = strpos($token, '.');
        if ($separator === false || $separator === 0 || $separator === strlen($token) - 1) {
            return 0;
        }

        $userId = absint(substr($token, 0, $separator));

        return $userId > 0 ? $userId : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedTokens(int $userId): array
    {
        $meta = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($meta)) {
            return [];
        }

        return array_values(array_filter(
            $meta,
            static fn (mixed $entry): bool => is_array($entry) && isset($entry['hash'])
        ));
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }
}
