<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\ExpirationFold;
use PHPUnit\Framework\TestCase;

final class ExpirationFoldTest extends TestCase
{
    public function testNoOrdersYieldZero(): void
    {
        self::assertSame(0, ExpirationFold::compute([]));
    }

    public function testSingleOrderEndsAtStartPlusDuration(): void
    {
        self::assertSame(1000 + 30 * 86400, ExpirationFold::compute([
            ['start_time' => 1000, 'duration_days' => 30],
        ]));
    }

    public function testOverlappingOrdersStackOnTheRunningExpiration(): void
    {
        // Bought early (start 2000) while already covered until 1000+30d:
        // its days append to the running window, nothing is lost.
        $folded = ExpirationFold::compute([
            ['start_time' => 1000, 'duration_days' => 30],
            ['start_time' => 2000, 'duration_days' => 10],
        ]);

        self::assertSame(1000 + 40 * 86400, $folded);
    }

    public function testGapOrderExtendsFromItsOwnStart(): void
    {
        // Starts after the current window ran out: extends from its start,
        // the gap never creates extra time.
        $folded = ExpirationFold::compute([
            ['start_time' => 1000, 'duration_days' => 30],
            ['start_time' => 1000 + 90 * 86400, 'duration_days' => 10],
        ]);

        self::assertSame(1000 + 100 * 86400, $folded);
    }

    public function testStringValuesFromDatabaseRowsAreAccepted(): void
    {
        $folded = ExpirationFold::compute([
            ['start_time' => '1000', 'duration_days' => '30'],
            ['start_time' => '2000', 'duration_days' => '10'],
        ]);

        self::assertSame(1000 + 40 * 86400, $folded);
    }
}
