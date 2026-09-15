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
 * Resolutions are mirrored into the object cache (`aiya_core_auth`
 * group, short TTL): every authenticated headless request used to pay
 * one point query on this table. Two accepted soft edges of the mirror:
 * tokens dropped by the per-user cap trim (not a revocation) keep
 * authenticating until the mirror expires, and expiry itself carries the
 * same sub-TTL drift — both bounded, both hygiene rather than security. Cache entries carry the user's
 * revocation generation (bumped on every revoke/revokeAll), so a hit is
 * re-validated against the current generation and revocations take
 * effect immediately even though the DB row may sit behind the mirror
 * for up to the TTL. Without an object cache drop-in the mirror lives
 * for the request only and every resolve still hits the table.
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

    private const CACHE_GROUP = 'aiya_core_auth';
    private const CACHE_TTL = 300;
    private const GENERATION_KEY = 'aiya_core_auth_gen';

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
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            throw new RuntimeException(__('The session token could not be stored.', 'aiya-core'));
        }

        // Keep at most MAX tokens per user (drop the oldest), and sweep the
        // user's expired rows while we are touching them anyway.
        $now = gmdate('Y-m-d H:i:s');
        $trimSql = $wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d AND (expires_at < %s OR id NOT IN (
                SELECT id FROM (
                    SELECT id FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d
                ) AS keep_rows
            ))',
            $this->table(),
            $userId,
            $now,
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

        $key = 'token_' . $this->hash($token);
        /** @var array{user: int, generation: int}|false $cached */
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached)) {
            // Negative mirrors (user 0) are final: a token secret is random
            // and its validity only ever shrinks, so a dead hash stays dead.
            if ($cached['user'] === 0) {
                return 0;
            }
            if ($cached['generation'] === $this->generation($cached['user'])) {
                return $cached['user'];
            }
            // Stale generation — the rows died under a revocation after this
            // mirror was written; fall through and let the table confirm.
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        // The generation is read BEFORE the table: if a concurrent
        // revocation lands between the two reads (generation changed under
        // us), the row this query saw may already be deleted — the result
        // is returned but NOT mirrored, because a mirror stamped with the
        // post-revocation generation would survive the very revocation
        // that raced it.
        $generationBefore = $this->generation($userId);

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM %i WHERE token_hash = %s AND expires_at > %s LIMIT 1',
            $this->table(),
            $this->hash($token),
            gmdate('Y-m-d H:i:s')
        ));

        if ($found === null) {
            // Negative mirrors (user 0) are final: a token secret is random
            // and its validity only ever shrinks, so a dead hash stays dead.
            wp_cache_set($key, ['user' => 0, 'generation' => $generationBefore], self::CACHE_GROUP, self::CACHE_TTL);

            return 0;
        }

        $generationAfter = $this->generation($userId);
        if ($generationAfter === $generationBefore) {
            wp_cache_set($key, ['user' => (int) $found, 'generation' => $generationAfter], self::CACHE_GROUP, self::CACHE_TTL);
        }

        return (int) $found;
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
            $this->bumpGeneration($userId);
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
            $this->bumpGeneration($userId);
        }

        return is_string($sql);
    }

    /** Deletes every expired row globally; the daily cron entry point. */
    public static function pruneExpired(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE expires_at < %s', $wpdb->prefix . 'aiya_auth_tokens', gmdate('Y-m-d H:i:s'));
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

    /** Per-user revocation counter; cache mirrors carrying an older value are dead. */
    private function generation(int $userId): int
    {
        return (int) get_user_meta($userId, self::GENERATION_KEY, true);
    }

    /**
     * Atomic increment (raw SQL — the meta API has no increment and a
     * read-modify-write could lose a bump to a concurrent revocation),
     * fall back to seeding the row on its first use. The user-meta cache
     * is invalidated either way: the raw statement bypasses it, and a
     * stale cached generation would re-validate a revoked token's mirror
     * for up to CACHE_TTL.
     */
    private function bumpGeneration(int $userId): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare(
            'UPDATE %i SET meta_value = meta_value + 1 WHERE user_id = %d AND meta_key = %s',
            $wpdb->usermeta,
            $userId,
            self::GENERATION_KEY
        );
        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }
        wp_cache_delete((string) $userId, 'user_meta');
        if ((int) $wpdb->rows_affected === 0) {
            // First revocation for this user: seed the row. Concurrent
            // seeds may append duplicate rows — harmless, they increment
            // in lockstep and reads take the first.
            add_user_meta($userId, self::GENERATION_KEY, 1, true);
            wp_cache_delete((string) $userId, 'user_meta');
        }
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }
}
