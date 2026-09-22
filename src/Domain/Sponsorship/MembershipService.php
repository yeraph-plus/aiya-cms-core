<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Core\Domain\Identity\UserBan;

/**
 * Membership reads on the entitlement queue (`{prefix}aiya_memberships`,
 * the 0.50.0 tier model). The legacy `sponsor_expiration` /
 * `aya_force_cancel_sponsor` / `aya_trigger_count_sponsor` protocol meta
 * are retired: validity derives from the holder's active queue rows alone.
 *
 * The forced-cancel path is gone with them (0.86.0) — a purchase cannot be
 * cut short server-side. The one lever that withholds membership is the
 * account-level disable switch (`Domain\Identity\UserBan`), applied here
 * at the gates so every consumer inherits it: `isSponsor()` (the content
 * gate), `isActive()` (the wire state) and `expiresAt()` (the derived
 * readers). `currentTier()` stays a plain data read — the admin still
 * sees a disabled holder's tier — but entitlement authorization must ask
 * the gates, never this.
 *
 * `isSponsor()` keeps the editorial bypass of the legacy
 * `aya_is_sponsor()`: editors and above always qualify — unless the
 * account is disabled, which outranks the bypass.
 */
final class MembershipService
{
    public function __construct(private EntitlementService $entitlements = new EntitlementService())
    {
    }

    /** An active queue row whose window still covers now. */
    public function isActive(int $userId): bool
    {
        if ($userId <= 0 || UserBan::isBanned($userId)) {
            return false;
        }

        return $this->entitlements->window($userId)['expiresAt'] > time();
    }

    /**
     * Queue end as a unix timestamp, 0 when the holder has no membership.
     * A disabled account answers 0 as well: readers that derive the
     * membership state from this number (the public profile block, the
     * admin users list) must not disagree with isActive().
     */
    public function expiresAt(int $userId): int
    {
        if ($userId <= 0 || UserBan::isBanned($userId)) {
            return 0;
        }

        return $this->entitlements->window($userId)['expiresAt'];
    }

    /**
     * Legacy `aya_is_sponsor()`: editors and above always qualify. A
     * disabled account never does — the ban outranks the staff bypass.
     */
    public function isSponsor(int $userId): bool
    {
        if ($userId <= 0 || UserBan::isBanned($userId)) {
            return false;
        }
        if (user_can($userId, 'edit_pages')) {
            return true;
        }

        return $this->isActive($userId);
    }

    /**
     * The holder's currently covering tier — the active queue row whose
     * window ends last (the row that keeps the membership alive). Null
     * when inactive; editors get null too (their bypass is a staff
     * privilege, not a purchased tier).
     *
     * This is a data read, not a gate: a disabled holder still shows the
     * tier they paid for (the admin users list leans on it). Authorization
     * asks isActive()/isSponsor(), which the disable switch intercepts.
     *
     * @return array{tierKey:string, tierName:string, expiresAt:int}|null
     */
    public function currentTier(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return $this->currentTiersFor([$userId])[$userId] ?? null;
    }

    /**
     * The covering tier of many holders in ONE queue read — the users-list
     * membership column's batch path, the same fold as currentTier() with
     * the per-row query hoisted out of the loop. Disabled accounts are not
     * filtered here either (data read, not a gate — see currentTier()).
     *
     * @param list<int> $userIds
     * @return array<int, array{tierKey:string, tierName:string, expiresAt:int}>
     */
    public function currentTiersFor(array $userIds): array
    {
        $covering = [];
        $now = time();

        foreach ($this->entitlements->activeQueueFor($userIds) as $userId => $rows) {
            foreach ($rows as $row) {
                // Queued-future rows are NOT yet the current tier: benefits
                // must not go live before their own window starts.
                $startsAt = (int) get_date_from_gmt($row['starts_at'], 'U');
                if ($startsAt > $now) {
                    continue;
                }
                $endsAt = (int) get_date_from_gmt($row['ends_at'], 'U');
                if ($endsAt <= $now) {
                    continue;
                }
                if (!isset($covering[$userId]) || $endsAt > $covering[$userId]['expiresAt']) {
                    $covering[$userId] = [
                        'tierKey' => $row['tier_key'],
                        'tierName' => $row['tier_name'],
                        'expiresAt' => $endsAt,
                    ];
                }
            }
        }

        return $covering;
    }
}
