<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\WireDates;
use PHPUnit\Framework\TestCase;

/**
 * The contract wire's single date projection: GMT column in, offset ISO
 * 8601 out, empty string for absent stamps. The wp_date shim is
 * UTC-anchored, so the offset is always +00:00 here — the stamp values
 * are fixed epochs, never wall-clock comparisons.
 */
final class WireDatesTest extends TestCase
{
    private const STAMP = 1767323045; // 2026-01-02 03:04:05 UTC

    public function testEmptyGmtColumnProjectsEmptyString(): void
    {
        self::assertSame('', WireDates::fromGmt(''));
    }

    public function testZeroDateProjectsEmptyString(): void
    {
        self::assertSame('', WireDates::fromGmt('0000-00-00 00:00:00'));
    }

    public function testGmtColumnProjectsAnOffsetIso8601Stamp(): void
    {
        self::assertSame('2026-01-02T03:04:05+00:00', WireDates::fromGmt('2026-01-02 03:04:05'));
    }

    public function testTimestampProjectsAnOffsetIso8601Stamp(): void
    {
        self::assertSame('2026-01-02T03:04:05+00:00', WireDates::fromTimestamp(self::STAMP));
    }

    public function testZeroTimestampProjectsEmptyString(): void
    {
        self::assertSame('', WireDates::fromTimestamp(0));
    }

    public function testNegativeTimestampProjectsEmptyString(): void
    {
        self::assertSame('', WireDates::fromTimestamp(-60));
    }
}
