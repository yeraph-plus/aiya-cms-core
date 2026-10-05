<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Shared\DateLabels;
use PHPUnit\Framework\TestCase;

/**
 * The admin-side localized date label authority. The wp_date and
 * get_date_from_gmt shims are UTC-anchored, so site-local wall time
 * equals GMT here — every expectation below is a fixed-epoch value,
 * never a wall-clock comparison.
 */
final class DateLabelsTest extends TestCase
{
    private const STAMP = 1767323045; // 2026-01-02 03:04:05 UTC

    protected function setUp(): void
    {
        delete_option('date_format');
        delete_option('time_format');
    }

    protected function tearDown(): void
    {
        delete_option('date_format');
        delete_option('time_format');
    }

    public function testZeroTimestampRendersTheEmDashPlaceholder(): void
    {
        self::assertSame('—', DateLabels::fromTimestamp(0));
    }

    public function testNegativeTimestampRendersTheEmDashPlaceholder(): void
    {
        self::assertSame('—', DateLabels::fromTimestamp(-3600));
    }

    public function testUnparseableGmtColumnRendersTheEmDashPlaceholder(): void
    {
        self::assertSame('—', DateLabels::fromGmt('not-a-date'));
    }

    public function testPreEpochGmtColumnRendersTheEmDashPlaceholder(): void
    {
        // Dates before 1970 cast to a negative epoch, which counts as absent.
        self::assertSame('—', DateLabels::fromGmt('1969-07-20 20:17:40'));
    }

    public function testTimestampRendersWithTheSiteFormatPair(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');

        self::assertSame('2026-01-02 03:04', DateLabels::fromTimestamp(self::STAMP));
    }

    public function testTimestampWithoutTimeDropsTheTimePart(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');

        self::assertSame('2026-01-02', DateLabels::fromTimestamp(self::STAMP, false));
    }

    public function testGmtColumnRendersThroughTheSameLabel(): void
    {
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');

        self::assertSame('2026-01-02 03:04', DateLabels::fromGmt('2026-01-02 03:04:05'));
    }

    public function testCustomSiteFormatsAreHonored(): void
    {
        update_option('date_format', 'd.m.Y');
        update_option('time_format', 'H:i:s');

        self::assertSame('02.01.2026 03:04:05', DateLabels::fromGmt('2026-01-02 03:04:05'));
    }
}
