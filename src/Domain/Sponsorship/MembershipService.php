<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Membership reads on the persistent protocol meta (`sponsor_expiration`,
 * `aya_force_cancel_sponsor`, `aya_trigger_count_sponsor` — workspace
 * AGENTS.md). The protocol key is written exclusively by OrderService after
 * every order mutation; this service is the read side plus the gated-resource
 * trigger counter.
 *
 * `isSponsor()` keeps the legacy `aya_is_sponsor()` semantics exactly,
 * including the editor-capability bypass.
 */
final class MembershipService
{
    public const EXPIRATION_KEY = 'sponsor_expiration';
    public const FORCE_CANCEL_KEY = 'aya_force_cancel_sponsor';
    public const TRIGGER_COUNT_KEY = 'aya_trigger_count_sponsor';

    public function expiration(int $userId): int
    {
        return (int) get_user_meta($userId, self::EXPIRATION_KEY, true);
    }

    public function forceCancelled(int $userId): bool
    {
        return (string) get_user_meta($userId, self::FORCE_CANCEL_KEY, true) === '1';
    }

    public function triggerCount(int $userId): int
    {
        return (int) get_user_meta($userId, self::TRIGGER_COUNT_KEY, true);
    }

    /** One gated-resource use; consumed per view of sponsor-only material. */
    public function incrementTriggerCount(int $userId): void
    {
        $count = $this->triggerCount($userId);
        update_user_meta($userId, self::TRIGGER_COUNT_KEY, $count + 1);
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

        return $this->expiration($userId) > $this->localNow() && !$this->forceCancelled($userId);
    }

    /** Whole days left on an active membership, 0 when expired. */
    public function leftDays(int $userId): int
    {
        $left = $this->expiration($userId) - $this->localNow();

        return $left > 0 ? (int) ceil($left / DAY_IN_SECONDS) : 0;
    }

    /**
     * Local-clock unix timestamp — the numeric twin of the legacy
     * current_time('timestamp') calls, which the stored protocol values were
     * written with; switching to plain UTC time() would misread every
     * existing expiration by the site's UTC offset.
     */
    private function localNow(): int
    {
        return time() + wp_timezone()->getOffset(new \DateTimeImmutable('now'));
    }
}
