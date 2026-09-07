<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testComputesConsistentTotals(): void
    {
        $page = Pagination::fromCounts(2, 10, 35);

        self::assertSame(
            ['page' => 2, 'perPage' => 10, 'totalItems' => 35, 'totalPages' => 4, 'hasNext' => true, 'hasPrevious' => true],
            $page->toArray()
        );
    }

    public function testEmptyListHasZeroPagesAndNoFlips(): void
    {
        $page = Pagination::fromCounts(1, 12, 0);

        self::assertSame(0, $page->totalPages);
        self::assertFalse($page->hasNext);
        self::assertFalse($page->hasPrevious);
    }

    public function testExactDivisionYieldsNoTrailingPage(): void
    {
        self::assertSame(3, Pagination::fromCounts(3, 10, 30)->totalPages);
        self::assertFalse(Pagination::fromCounts(3, 10, 30)->hasNext);
    }

    public function testOutOfRangePageAnswersEmptyWithoutSilentRepage(): void
    {
        $page = Pagination::fromCounts(99, 10, 35);

        self::assertSame(99, $page->page);
        self::assertSame(4, $page->totalPages);
        self::assertFalse($page->hasNext);
        self::assertTrue($page->hasPrevious);
    }

    public function testFirstPageHasNoPrevious(): void
    {
        self::assertFalse(Pagination::fromCounts(1, 9, 27)->hasPrevious);
        self::assertTrue(Pagination::fromCounts(1, 9, 27)->hasNext);
    }
}
