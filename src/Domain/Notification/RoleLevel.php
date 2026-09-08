<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Notification;

/**
 * Visibility ladder for notifications, aligned with the UserPresenter role
 * semantics (administrator / author / sponsor / subscriber) and extended
 * with the guest level for logged-out visitors. A row's role level is the
 * minimum rank a viewer needs: a broadcast row marked `subscriber` is
 * visible to every signed-in user, `sponsor` additionally requires a
 * valid sponsorship (the presenter resolves that from the protocol meta),
 * and so on. `guest`-level rows are the public site notices.
 */
final class RoleLevel
{
    public const GUEST = 'guest';
    public const SUBSCRIBER = 'subscriber';
    public const SPONSOR = 'sponsor';
    public const AUTHOR = 'author';
    public const ADMINISTRATOR = 'administrator';

    /** Ordered lowest to highest; the value is the rank. */
    public const LADDER = [
        self::GUEST => 0,
        self::SUBSCRIBER => 1,
        self::SPONSOR => 2,
        self::AUTHOR => 3,
        self::ADMINISTRATOR => 4,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LADDER);
    }

    public static function isValid(string $level): bool
    {
        return isset(self::LADDER[$level]);
    }

    /** Unknown or malformed levels collapse to the guest rank. */
    public static function rank(string $level): int
    {
        return self::LADDER[$level] ?? 0;
    }

    /**
     * Levels visible to a viewer of the given rank (the stored level is
     * the minimum a viewer needs).
     *
     * @return list<string>
     */
    public static function upTo(int $rank): array
    {
        $levels = [];
        foreach (self::LADDER as $level => $levelRank) {
            if ($levelRank <= $rank) {
                $levels[] = $level;
            }
        }

        return $levels;
    }
}
