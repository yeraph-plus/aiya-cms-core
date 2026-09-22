<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Operations\StatsMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StatsMathTest extends TestCase
{
    private const DAY = 86400;

    private static function ts(string $utc): int
    {
        $parsed = strtotime($utc . ' UTC');

        return $parsed === false ? 0 : $parsed;
    }

    public function testMonthKeysRunAscendingUpToTheLastMonth(): void
    {
        self::assertSame(
            ['2026-07', '2026-08', '2026-09'],
            StatsMath::monthKeys('2026-09', 3)
        );
    }

    public function testMonthKeysCrossTheYearBoundary(): void
    {
        self::assertSame(
            ['2025-11', '2025-12', '2026-01'],
            StatsMath::monthKeys('2026-01', 3)
        );
    }

    public function testMonthKeysNeverReturnNothing(): void
    {
        self::assertSame(['2026-09'], StatsMath::monthKeys('2026-09', 0));
    }

    public function testPreviousMonthRollsOverTheYear(): void
    {
        self::assertSame('2025-12', StatsMath::previousMonth('2026-01'));
        self::assertSame('2026-08', StatsMath::previousMonth('2026-09'));
    }

    public function testMonthBoundsAreHalfOpen(): void
    {
        self::assertSame(['start' => '2026-09-01', 'end' => '2026-10-01'], StatsMath::monthBounds('2026-09'));
        self::assertSame(['start' => '2026-12-01', 'end' => '2027-01-01'], StatsMath::monthBounds('2026-12'));
        self::assertSame(['start' => '2024-02-01', 'end' => '2024-03-01'], StatsMath::monthBounds('2024-02'));
    }

    public function testIsMonthAcceptsOnlyCalendarMonths(): void
    {
        self::assertTrue(StatsMath::isMonth('2026-09'));
        self::assertTrue(StatsMath::isMonth('2026-12'));
        self::assertFalse(StatsMath::isMonth('2026-13'));
        self::assertFalse(StatsMath::isMonth('2026-00'));
        self::assertFalse(StatsMath::isMonth('2026-9'));
        self::assertFalse(StatsMath::isMonth('202609'));
        self::assertFalse(StatsMath::isMonth(''));
        self::assertFalse(StatsMath::isMonth('2026-09-01'));
    }

    public function testMonthBoundsRejectANonMonthKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StatsMath::monthBounds('2026-13');
    }

    public function testOverlapSecondsClipsToTheWindow(): void
    {
        $start = self::ts('2026-09-10 00:00:00');
        $end = self::ts('2026-10-10 00:00:00');
        $windowStart = self::ts('2026-09-01 00:00:00');
        $windowEnd = self::ts('2026-10-01 00:00:00');

        // 21 days of September (10th..30th inclusive of the 30th's seconds).
        self::assertSame(21 * self::DAY, StatsMath::overlapSeconds($start, $end, $windowStart, $windowEnd));
    }

    public function testOverlapSecondsIsZeroForDisjointWindows(): void
    {
        $windowStart = self::ts('2026-09-01 00:00:00');
        $windowEnd = self::ts('2026-10-01 00:00:00');

        self::assertSame(0, StatsMath::overlapSeconds(
            self::ts('2026-07-01 00:00:00'),
            self::ts('2026-08-01 00:00:00'),
            $windowStart,
            $windowEnd
        ));
        // A window that starts exactly when the month ends is outside it.
        self::assertSame(0, StatsMath::overlapSeconds(
            self::ts('2026-10-01 00:00:00'),
            self::ts('2026-11-01 00:00:00'),
            $windowStart,
            $windowEnd
        ));
    }

    public function testRecognizedSpreadsOneCycleOverItsMonth(): void
    {
        $start = self::ts('2026-09-01 00:00:00');

        // A 12-cycle 120 order recognizes 10 per 30-day cycle.
        self::assertSame(10.0, StatsMath::recognized(
            120.0,
            12,
            30,
            $start,
            $start + 360 * self::DAY,
            $start,
            $start + 30 * self::DAY
        ));
    }

    public function testRecognizedProratesAStraddlingPeriod(): void
    {
        $start = self::ts('2026-09-16 00:00:00');
        $end = self::ts('2026-10-16 00:00:00');
        $september = [self::ts('2026-09-01 00:00:00'), self::ts('2026-10-01 00:00:00')];
        $october = [self::ts('2026-10-01 00:00:00'), self::ts('2026-11-01 00:00:00')];

        $septemberShare = StatsMath::recognized(100.0, 1, 30, $start, $end, $september[0], $september[1]);
        $octoberShare = StatsMath::recognized(100.0, 1, 30, $start, $end, $october[0], $october[1]);

        self::assertSame(50.0, $septemberShare);
        self::assertSame(50.0, $octoberShare);
    }

    public function testRecognizedAddsUpToTheOrderAcrossMonths(): void
    {
        // 2026-01-01 → 2026-04-01 is exactly 90 days (31 + 28 + 31).
        $start = self::ts('2026-01-01 00:00:00');
        $end = self::ts('2026-04-01 00:00:00');
        $total = 0.0;
        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            $bounds = StatsMath::monthBounds($month);
            $total += StatsMath::recognized(
                360.0,
                3,
                30,
                $start,
                $end,
                self::ts($bounds['start'] . ' 00:00:00'),
                self::ts($bounds['end'] . ' 00:00:00')
            );
        }

        self::assertEqualsWithDelta(360.0, $total, 0.000001);
    }

    public function testRecognizedIgnoresUnpaidMemberships(): void
    {
        $start = self::ts('2026-09-01 00:00:00');

        self::assertSame(0.0, StatsMath::recognized(
            0.0,
            12,
            30,
            $start,
            $start + 360 * self::DAY,
            $start,
            $start + 30 * self::DAY
        ));
    }

    public function testRecognizedFloorsZeroCyclesInsteadOfDividingByZero(): void
    {
        $start = self::ts('2026-09-01 00:00:00');

        // cycles_total 0 cannot happen through activateFromPayment (it
        // clamps to 1) — the formula must still not explode.
        self::assertSame(30.0, StatsMath::recognized(
            30.0,
            0,
            30,
            $start,
            $start + 30 * self::DAY,
            $start,
            $start + 30 * self::DAY
        ));
    }

    public function testRatioAnswersNullWithoutADenominator(): void
    {
        self::assertNull(StatsMath::ratio(10, 0));
        self::assertNull(StatsMath::ratio(0, 0));
        self::assertSame(0.0, StatsMath::ratio(0, 10));
        self::assertSame(0.5, StatsMath::ratio(5, 10));
        self::assertSame(12.5, StatsMath::ratio(25.0, 2));
    }

    public function testDerivedPinsTheReportIndicators(): void
    {
        $derived = StatsMath::derived([
            'granted' => 1000,
            'consumed' => 400,
            'expired' => 100,
            'downloads' => 50,
            'activeUsers' => 10,
            'payingUsers' => 5,
            'mrr' => 250.0,
            'cost' => 25.0,
        ]);

        self::assertSame([
            'consumptionRate' => 0.4,
            'expiryRate' => 0.1,
            'downloadsPerActive' => 5.0,
            'downloadsPerPaying' => 10.0,
            'revenuePerPaying' => 50.0,
            'costPerPaying' => 5.0,
        ], $derived);
    }

    public function testDerivedDegradesPerIndicatorNotAsAWhole(): void
    {
        // A month with grants but nobody active and nobody paying: the
        // rates that need a population are absent, the others still count.
        $derived = StatsMath::derived([
            'granted' => 500,
            'consumed' => 0,
            'expired' => 0,
            'downloads' => 0,
            'activeUsers' => 0,
            'payingUsers' => 0,
            'mrr' => 0.0,
            'cost' => 0.0,
        ]);

        self::assertSame(0.0, $derived['consumptionRate']);
        self::assertSame(0.0, $derived['expiryRate']);
        self::assertNull($derived['downloadsPerActive']);
        self::assertNull($derived['downloadsPerPaying']);
        self::assertNull($derived['revenuePerPaying']);
        self::assertNull($derived['costPerPaying']);
    }

    public function testDerivedOnAnEmptyMonthAnswersOnlyNulls(): void
    {
        // A month before the report existed (or before install) has no
        // denominator anywhere: every indicator reads "not computable".
        self::assertSame([
            'consumptionRate' => null,
            'expiryRate' => null,
            'downloadsPerActive' => null,
            'downloadsPerPaying' => null,
            'revenuePerPaying' => null,
            'costPerPaying' => null,
        ], StatsMath::derived([]));
    }
}
