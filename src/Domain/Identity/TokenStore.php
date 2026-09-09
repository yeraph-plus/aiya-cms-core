<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use RuntimeException;

/**
 * Opaque bearer tokens for the headless API, stored as HMAC hashes in the
 * dedicated `aiya_auth_tokens` table (never plaintext). The plain token
 * carries the user id as its first segment (`{userId}.{secret}`) so a
 * malformed presentation short-circuits before the lookup; the stored
 * hash resolves through a unique-index point query.
 *
 * Several devices may hold valid tokens at once; the per-user cap is
 * enforced by trimming the oldest rows after every insert, and expired
 * rows are pruned lazily per user on issue plus globally by the daily
 * IdentityModule cron. Changing the password revokes everything (see
 * revokeAll()).
 *
 * This replaces the legacy usermeta array store (2026-09-09 plan): the
 * array shape lost tokens under concurrent logins (unsynchronized
 * read-append-write) and rewrote the meta row on every login. Tokens
 * issued before 0.28.0 are not migrated — holders simply sign in again.
 *
 * TTLs mirror the classic WordPress cookie lifetimes: 14 days when the
 * client asks to be remembered, 2 days otherwise.
 */
final class TokenStore
{
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

        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert(
            $this->table(),
            [
                'token_hash' => $this->hash($plain),
                'user_id' => $userId,
                'expires_at' => $expiresAt,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%d', '%s']
        );

        if ($inserted === false) {
            throw new RuntimeException(__('The session token could not be stored.', 'aiya-core'));
        }

        // Keep at most MAX tokens per user (drop the oldest), and sweep the
        // user's expired rows while we are touching them anyway.
        $trimSql = $wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d AND (expires_at < %d OR id NOT IN (
                SELECT id FROM (
                    SELECT id FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d
                ) AS keep_rows
            ))',
            $this->table(),
            $userId,
            time(),
            $this->table(),
            $userId,
            self::MAX_TOKENS_PER_USER
        );
        if (is_string($trimSql)) {
            $wpdb->query($trimSql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        return new AuthToken($plain, $expiresAt);
    }

    /** Resolves a presented token to a user id; 0 when unknown or expired. */
    public function resolve(string $token): int
    {
        $userId = $this->parseUserId($token);
        if ($userId === 0) {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM %i WHERE token_hash = %s AND expires_at > %d LIMIT 1',
            $this->table(),
            $this->hash($token),
            time()
        ));

        return $found !== null ? (int) $found : 0;
    }

    /** Removes one presented token (logout). */
    public function revoke(string $token): bool
    {
        $userId = $this->parseUserId($token);
        if ($userId === 0) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE token_hash = %s', $this->table(), $this->hash($token));
        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }
        // Only a hard DB failure reports false; "no such row" is a fine
        // logout outcome.
        return is_string($sql);
    }

    /**
     * Invalidates every token of a user (password change / reset).
     * False means the sweep could not run — callers that rely on the
     * security property (old sessions must die) must surface it.
     */
    public function revokeAll(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE user_id = %d', $this->table(), $userId);
        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        return is_string($sql);
    }

    /** Deletes every expired row globally; the daily cron entry point. */
    public static function pruneExpired(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE expires_at < %d', $wpdb->prefix . 'aiya_auth_tokens', time());
        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
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

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_auth_tokens';
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }
}
