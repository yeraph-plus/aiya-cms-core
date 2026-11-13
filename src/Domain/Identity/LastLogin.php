<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

/**
 * The account's most recent successful sign-in stamp (`aiya_core_last_login`
 * user meta, unix seconds). One write per login — logins are rare by
 * construction (tokens live days), so the stamp needs no throttling.
 *
 * Two writers feed it: the headless token issue (TokenStore::issue) and
 * the classic `wp_login` hook (IdentityModule) — every authentication
 * path the runtime honors lands on one of the two. Readers: the
 * `/users/me` projection (WireDates) and the account-deletion executor's
 * "came back during the grace window" guard.
 */
final class LastLogin
{
    public const META_KEY = 'aiya_core_last_login';

    public static function stamp(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        update_user_meta($userId, self::META_KEY, time());
    }

    /** Unix seconds; 0 when the account was never signed into. */
    public static function of(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) get_user_meta($userId, self::META_KEY, true);
    }
}
