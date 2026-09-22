<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

/**
 * The account-level disable switch (`aiya_core_banned` user meta) — the
 * one moderation lever the runtime honors. A disabled account cannot
 * check in, cannot spend its credit balance, and is not a member for any
 * gate. On the profile screen the switch is an admin-gated code-declared
 * user field (the Identity module registers it there; MetaboxAdmin hides
 * it from the holder's own screen), and `set()` is the canonical
 * programmatic write API for tests and future REST consumers — the
 * profile form's generic save loop lands on the same representation (a
 * truthy stored value when enabling, the key deleted when clearing), so
 * the two writers cannot drift on what "banned" looks like.
 *
 * Enforcement lives at the points that already decide those questions, so
 * each question keeps exactly one answer:
 *
 * - sign-in credits: `CreditController::checkin()` refuses up front;
 * - spending: `LedgerService::spend()` refuses before touching the ledger
 *   — the deduction point, so every consumer (the FileServe download
 *   endpoints included) inherits it without knowing about the ban;
 * - membership: `MembershipService`'s gates (isSponsor / isActive /
 *   expiresAt) answer "not a member", which covers the content gate, the
 *   wire state and every derived reader such as the public profile block.
 *
 * The ledger and the entitlement queue are deliberately NOT touched: a
 * disabled holder keeps what was granted and keeps receiving what was
 * purchased (the cycles keep landing, they simply cannot be spent), and
 * nothing is refunded, deleted or cancelled. Disabling is a policy
 * override, not an accounting event — the balance keeps expiring on its
 * own schedule.
 */
final class UserBan
{
    /**
     * The user meta key doubles as the field id registered on the profile
     * screen — one string, one meaning.
     */
    public const META_KEY = 'aiya_core_banned';

    public static function isBanned(int $userId): bool
    {
        return $userId > 0 && (bool) get_user_meta($userId, self::META_KEY, true);
    }

    /**
     * Flips the switch. The meta is deleted when clearing, so "not banned"
     * has exactly one representation (the key is absent).
     */
    public static function set(int $userId, bool $banned): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($banned) {
            update_user_meta($userId, self::META_KEY, '1');

            return;
        }

        delete_user_meta($userId, self::META_KEY);
    }
}
