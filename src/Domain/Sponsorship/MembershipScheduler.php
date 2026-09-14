<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * The tier-cycle scheduling math, kept WordPress-free so the unit suite
 * can pin the subscription model: an entitlement starts at a fixed GMT
 * timestamp and its cycles run back-to-back (`starts_at + k * cycle_days`),
 * no gaps and no overlaps. Cycle k (1-based) covers
 * [starts_at + (k-1) * cycle_days, starts_at + k * cycle_days).
 *
 * The queue semantics live one layer up (EntitlementService): a new
 * purchase starts at max(now, the holder's queue tail), so multiple
 * purchases and tiers run sequentially in purchase order.
 */
final class MembershipScheduler
{
    /** WP-free day literal, the house convention for pure classes. */
    private const DAY = 86400;

    /**
     * The 1-based cycle windows of one entitlement row.
     *
     * @return list<array{cycle:int, startsAt:int, endsAt:int}>
     */
    public static function cycles(int $startsAt, int $cycleDays, int $cyclesTotal): array
    {
        $cycleDays = max(1, $cycleDays);
        $windows = [];
        for ($cycle = 1; $cycle <= $cyclesTotal; $cycle++) {
            $windows[] = [
                'cycle' => $cycle,
                'startsAt' => $startsAt + ($cycle - 1) * $cycleDays * self::DAY,
                'endsAt' => $startsAt + $cycle * $cycleDays * self::DAY,
            ];
        }

        return $windows;
    }

    /**
     * The cycles whose grant moment (the cycle's start) has arrived but
     * whose credit bucket was not handed out yet, in due order.
     *
     * @return list<array{cycle:int, startsAt:int, endsAt:int}>
     */
    public static function dueCycles(int $startsAt, int $cycleDays, int $cyclesTotal, int $cyclesGranted, int $now): array
    {
        $windows = self::cycles($startsAt, $cycleDays, $cyclesTotal);

        return array_values(array_filter(
            $windows,
            static fn (array $window): bool => $window['cycle'] > $cyclesGranted && $window['startsAt'] <= $now
        ));
    }
}
