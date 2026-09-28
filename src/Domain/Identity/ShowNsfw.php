<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

/**
 * The "always show NSFW content" preference (`aiya_core_show_nsfw` user
 * meta) — the hard half of the NSFW switch pair. When it holds for the
 * signed-in viewer, the read path ignores every NSFW exclusion request:
 * NsfwFilter answers an empty list and the configured vocabularies stay
 * visible to that account everywhere (listings, term directories).
 *
 * The other half is the front end's own soft switch (browser-stored);
 * this meta is the account-level override that wins over it. The field
 * is self-serviceable: it renders on the holder's own profile screen (no
 * capability gate — unlike UserBan) and `PATCH users/me/profile` writes
 * it through set(), so both writers land on the same representation (the
 * key deleted when cleared).
 */
final class ShowNsfw
{
    /**
     * The user meta key doubles as the field id registered on the profile
     * screen — one string, one meaning.
     */
    public const META_KEY = 'aiya_core_show_nsfw';

    public static function always(int $userId): bool
    {
        return $userId > 0 && (bool) get_user_meta($userId, self::META_KEY, true);
    }

    /**
     * Flips the switch. The meta is deleted when clearing, so "follow the
     * soft switch" has exactly one representation (the key is absent).
     */
    public static function set(int $userId, bool $always): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($always) {
            update_user_meta($userId, self::META_KEY, '1');

            return;
        }

        delete_user_meta($userId, self::META_KEY);
    }
}
