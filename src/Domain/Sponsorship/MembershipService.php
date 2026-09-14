<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Membership reads on the entitlement queue (`{prefix}aiya_memberships`,
 * the 0.50.0 tier model). The legacy `sponsor_expiration` /
 * `aya_force_cancel_sponsor` / `aya_trigger_count_sponsor` protocol meta
 * are retired: validity derives from the holder's active queue rows and
 * forced cancel flips those rows, so nothing lives in user meta any more.
 *
 * `isSponsor()` keeps the editorial bypass of the legacy
 * `aya_is_sponsor()`: editors and above always qualify.
 */
final class MembershipService
{
    public function __construct(private EntitlementService $entitlements = new EntitlementService())
    {
    }

    /** An active queue row whose window still covers now. */
    public function isActive(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->entitlements->window($userId)['expiresAt'] > time();
    }

    /** Queue end as a unix timestamp, 0 when the holder has no membership. */
    public function expiresAt(int $userId): int
    {
        return $this->entitlements->window($userId)['expiresAt'];
    }

    /** Whole days left on an active membership, 0 when expired. */
    public function leftDays(int $userId): int
    {
        $left = $this->expiresAt($userId) - time();

        return $left > 0 ? (int) ceil($left / DAY_IN_SECONDS) : 0;
    }

    /** Forced cancel: flips every queue row to `cancelled` (idempotent). */
    public function cancel(int $userId): void
    {
        $this->entitlements->cancelAll($userId);
    }

    /** Legacy `aya_is_sponsor()`: editors and above always qualify. */
    public function isSponsor(int $userId): bool
    {
        if ($userId <= 0) {
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
     * privilege, not a purchased tier). Future benefit logic keys off
     * this instead of walking the queue itself.
     *
     * @return array{tierKey:string, tierName:string, expiresAt:int}|null
     */
    public function currentTier(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $covering = null;
        $now = time();
        foreach ($this->entitlements->queueFor($userId) as $row) {
            if ($row['status'] !== EntitlementService::STATUS_ACTIVE) {
                continue;
            }
            $endsAt = (int) get_date_from_gmt($row['ends_at'], 'U');
            if ($endsAt <= $now) {
                continue;
            }
            if ($covering === null || $endsAt > $covering['expiresAt']) {
                $covering = [
                    'tierKey' => $row['tier_key'],
                    'tierName' => $row['tier_name'],
                    'expiresAt' => $endsAt,
                ];
            }
        }

        return $covering;
    }
}
