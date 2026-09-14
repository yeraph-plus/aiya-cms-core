<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Credit\CreditAllocator;
use PHPUnit\Framework\TestCase;

final class CreditAllocatorTest extends TestCase
{
    public function testZeroAmountNeedsNoBuckets(): void
    {
        self::assertSame([], CreditAllocator::plan([], 0));
    }

    public function testEmptyLedgerCannotCoverPositiveAmount(): void
    {
        self::assertNull(CreditAllocator::plan([], 1));
    }

    public function testSingleBucketCoversExactlyAndFully(): void
    {
        $plan = CreditAllocator::plan([
            ['id' => 7, 'remaining' => 5],
        ], 5);

        self::assertSame([['id' => 7, 'take' => 5]], $plan);
    }

    public function testAllocationWalksBucketsInTheGivenOrder(): void
    {
        // Caller passes earliest-expiry first; the plan must honour it.
        $plan = CreditAllocator::plan([
            ['id' => 2, 'remaining' => 2],
            ['id' => 1, 'remaining' => 9],
        ], 10);

        self::assertSame([
            ['id' => 2, 'take' => 2],
            ['id' => 1, 'take' => 8],
        ], $plan);
    }

    public function testSpillStopsAtTheFirstBucketThatCovers(): void
    {
        $plan = CreditAllocator::plan([
            ['id' => 3, 'remaining' => 1],
            ['id' => 4, 'remaining' => 8],
            ['id' => 5, 'remaining' => 100],
        ], 4);

        self::assertSame([
            ['id' => 3, 'take' => 1],
            ['id' => 4, 'take' => 3],
        ], $plan);
    }

    public function testEmptyBucketsAreSkipped(): void
    {
        $plan = CreditAllocator::plan([
            ['id' => 1, 'remaining' => 0],
            ['id' => 2, 'remaining' => 3],
        ], 2);

        self::assertSame([['id' => 2, 'take' => 2]], $plan);
    }

    public function testInsufficientCombinedBalanceIsNull(): void
    {
        self::assertNull(CreditAllocator::plan([
            ['id' => 1, 'remaining' => 2],
            ['id' => 2, 'remaining' => 3],
        ], 6));
    }

    public function testStringValuesFromDatabaseRowsAreAccepted(): void
    {
        $plan = CreditAllocator::plan([
            ['id' => '9', 'remaining' => '4'],
        ], 4);

        self::assertSame([['id' => 9, 'take' => 4]], $plan);
    }
}
