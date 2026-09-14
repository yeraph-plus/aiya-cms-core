<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\DevTools\ServerStatusPage;
use PHPUnit\Framework\TestCase;

final class ServerStatusPageTest extends TestCase
{
    public function testIdlePercentSumsCoreSeconds(): void
    {
        // /proc/uptime idle seconds accumulate across cores, so idle can
        // exceed wall uptime on a multicore host.
        self::assertSame(75.0, ServerStatusPage::idlePercent(300.0, 100.0, 4));
        self::assertSame(100.0, ServerStatusPage::idlePercent(200.0, 100.0, 2));
    }

    public function testIdlePercentRefusesADivideByZero(): void
    {
        self::assertNull(ServerStatusPage::idlePercent(10.0, 0.0, 4));
        self::assertNull(ServerStatusPage::idlePercent(10.0, 100.0, 0));
    }

    public function testRatioPercentStaysWithinBounds(): void
    {
        self::assertSame(50.0, ServerStatusPage::ratioPercent(30, 60));
        self::assertSame(100.0, ServerStatusPage::ratioPercent(200, 100));
        self::assertSame(0.0, ServerStatusPage::ratioPercent(-5, 100));
        self::assertSame(0.0, ServerStatusPage::ratioPercent(1, 0));
    }
}
