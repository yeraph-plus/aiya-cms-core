<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The operations report math, kept WordPress-free so the unit suite can
 * pin the business formulas (the CreditAllocator / MembershipScheduler
 * precedent). Everything here works on absolute unix timestamps, plain
 * `YYYY-MM` keys and `YYYY-MM-DD` bounds — the timezone-aware
 * conversions (site-local month ↔ GMT) stay one layer up in StatsQuery.
 *
 * Two conventions carry the report and are pinned by tests:
 *
 * - Entitlement windows are half-open: `[starts_at, ends_at)`, matching
 *   EntitlementService (`ends_at = starts_at + cycles × cycle_days`).
 * - Revenue is recognized evenly over the service period and prorated by
 *   elapsed seconds, so the full order amount is recognized exactly once
 *   across all months and a 12-cycle purchase never spikes one month.
 */
final class StatsMath
{
    /** WP-free day literal, the house convention for pure classes. */
    private const DAY = 86400;

    private const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public static function isMonth(string $month): bool
    {
        return preg_match(self::MONTH_PATTERN, $month) === 1;
    }

    /**
     * Seconds of `[start, end)` that fall inside `[windowStart, windowEnd)`;
     * 0 when the two do not overlap.
     */
    public static function overlapSeconds(int $start, int $end, int $windowStart, int $windowEnd): int
    {
        return max(0, min($end, $windowEnd) - max($start, $windowStart));
    }

    /**
     * Revenue recognized inside one month for a single entitlement: the
     * order amount spread over the service period (cycles × cycle days),
     * prorated by the overlapping seconds.
     *
     * @param float $amount    the order's paid amount (0 for code-granted memberships)
     * @param int   $cycles    cycles_total snapshot of the row
     * @param int   $cycleDays cycle_days snapshot of the row
     */
    public static function recognized(
        float $amount,
        int $cycles,
        int $cycleDays,
        int $start,
        int $end,
        int $windowStart,
        int $windowEnd
    ): float {
        if ($amount <= 0.0) {
            return 0.0;
        }

        $period = max(1, $cycles) * max(1, $cycleDays) * self::DAY;
        $overlap = self::overlapSeconds($start, $end, $windowStart, $windowEnd);
        if ($overlap <= 0) {
            return 0.0;
        }

        return $amount * $overlap / $period;
    }

    /**
     * A panel ratio. A zero (or negative) denominator answers null —
     * "not computable" — never 0 and never INF: no paying users in a
     * month means revenue per paying user has no value at all.
     */
    public static function ratio(int|float $numerator, int|float $denominator): ?float
    {
        $denominator = (float) $denominator;
        if ($denominator <= 0.0) {
            return null;
        }

        return (float) $numerator / $denominator;
    }

    /**
     * The five indicators the operations report is built around, derived
     * from one month row.
     *
     * @param array<string, mixed> $row a StatsQuery month row (extra keys are ignored)
     * @return array<string, float|null>
     */
    public static function derived(array $row): array
    {
        $granted = (float) ($row['granted'] ?? 0);

        return [
            // 积分消耗率 — consumption against what was handed out.
            'consumptionRate' => self::ratio((float) ($row['consumed'] ?? 0), $granted),
            // 过期率 — the share of grants nobody ever spent.
            'expiryRate' => self::ratio((float) ($row['expired'] ?? 0), $granted),
            // 用户平均下载量 — traffic per active user.
            'downloadsPerActive' => self::ratio((float) ($row['downloads'] ?? 0), (float) ($row['activeUsers'] ?? 0)),
            // 付费用户平均下载量.
            'downloadsPerPaying' => self::ratio((float) ($row['downloads'] ?? 0), (float) ($row['payingUsers'] ?? 0)),
            // 每个付费用户带来的收入.
            'revenuePerPaying' => self::ratio((float) ($row['mrr'] ?? 0), (float) ($row['payingUsers'] ?? 0)),
            // 每个付费用户的实际成本.
            'costPerPaying' => self::ratio((float) ($row['cost'] ?? 0), (float) ($row['payingUsers'] ?? 0)),
        ];
    }

    /** The month key of the calendar day one month before `$month`. */
    public static function previousMonth(string $month): string
    {
        return self::shift($month, -1);
    }

    /**
     * The last `$count` month keys in ascending order, ending at
     * `$lastMonth` included.
     *
     * @return list<string>
     */
    public static function monthKeys(string $lastMonth, int $count): array
    {
        $count = max(1, $count);
        $keys = [];
        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $keys[] = self::shift($lastMonth, -$offset);
        }

        return $keys;
    }

    /**
     * The half-open calendar bounds of one month as date strings:
     * `['start' => '2026-09-01', 'end' => '2026-10-01']`.
     *
     * @return array{start: string, end: string}
     */
    public static function monthBounds(string $month): array
    {
        $start = self::parse($month);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $start->modify('+1 month')->format('Y-m-d'),
        ];
    }

    private static function shift(string $month, int $months): string
    {
        return self::parse($month)->modify(sprintf('%+d months', $months))->format('Y-m');
    }

    private static function parse(string $month): DateTimeImmutable
    {
        // Calendars are date arithmetic, not clock arithmetic: the
        // conversions run in UTC and every caller supplies explicit GMT
        // bounds, so no local timezone (and no DST rule) can shift a
        // month boundary.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m') !== $month) {
            throw new InvalidArgumentException(sprintf('Not a month key: %s', $month));
        }

        return $parsed;
    }
}
