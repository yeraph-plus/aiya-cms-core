<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\MembershipScheduler;
use PHPUnit\Framework\TestCase;

final class MembershipSchedulerTest extends TestCase
{
    private const DAY = 86400;

    public function testCyclesRunBackToBackFromStart(): void
    {
        $windows = MembershipScheduler::cycles(1000, 30, 3);

        self::assertSame([
            ['cycle' => 1, 'startsAt' => 1000, 'endsAt' => 1000 + 30 * self::DAY],
            ['cycle' => 2, 'startsAt' => 1000 + 30 * self::DAY, 'endsAt' => 1000 + 60 * self::DAY],
            ['cycle' => 3, 'startsAt' => 1000 + 60 * self::DAY, 'endsAt' => 1000 + 90 * self::DAY],
        ], $windows);
    }

    public function testNoCyclesWhenTotalIsZero(): void
    {
        self::assertSame([], MembershipScheduler::cycles(1000, 30, 0));
    }

    public function testCycleDaysFloorAtOne(): void
    {
        $windows = MembershipScheduler::cycles(1000, 0, 1);

        self::assertSame(1000 + self::DAY, $windows[0]['endsAt']);
    }

    public function testDueCyclesSkipsGrantedAndFutureCycles(): void
    {
        // Two granted, cycle 3 started, cycle 4 still in the future.
        $now = 1000 + 65 * self::DAY;
        $due = MembershipScheduler::dueCycles(1000, 30, 5, 2, $now);

        self::assertSame([3], array_column($due, 'cycle'));
    }

    public function testDueCyclesCatchesUpAfterDowntime(): void
    {
        // Cron was down for over three cycles: every due cycle is returned.
        $now = 1000 + 95 * self::DAY;
        $due = MembershipScheduler::dueCycles(1000, 30, 5, 0, $now);

        self::assertSame([1, 2, 3, 4], array_column($due, 'cycle'));
    }

    public function testNothingDueWhenAllGrantedOrNotStarted(): void
    {
        self::assertSame([], MembershipScheduler::dueCycles(1000, 30, 3, 3, 1000 + 900 * self::DAY));
        self::assertSame([], MembershipScheduler::dueCycles(1000, 30, 3, 0, 999));
    }
}
