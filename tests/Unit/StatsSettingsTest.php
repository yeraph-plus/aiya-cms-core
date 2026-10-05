<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Operations\StatsSettings;
use PHPUnit\Framework\TestCase;

/**
 * The normalized reader for the report's dedicated option: the unit cost
 * lives in `aiya_core_operations.ops_unit_cost`, anything unset, malformed
 * or negative answers the zero default — the report may price at zero but
 * never at a loss.
 */
final class StatsSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testUnitCostDefaultsToZeroWithoutConfiguration(): void
    {
        self::assertSame(0.0, StatsSettings::unitCost());
    }

    public function testUnitCostIgnoresANonArrayOption(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = '12.5';

        self::assertSame(0.0, StatsSettings::unitCost());
    }

    public function testUnitCostReadsTheDedicatedArrayKey(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => '2.5'];

        self::assertSame(2.5, StatsSettings::unitCost());
    }

    public function testUnitCostAcceptsNumericScalars(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => 3];
        self::assertSame(3.0, StatsSettings::unitCost());

        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => 1.25];
        self::assertSame(1.25, StatsSettings::unitCost());
    }

    public function testUnitCostClampsNegativeValuesToZero(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => -3];
        self::assertSame(0.0, StatsSettings::unitCost());

        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => -0.01];
        self::assertSame(0.0, StatsSettings::unitCost());
    }

    public function testUnitCostFallsBackWhenTheKeyIsMissing(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['other_key' => 5];

        self::assertSame(0.0, StatsSettings::unitCost());
    }

    public function testUnitCostTreatsNullValueAsUnset(): void
    {
        $GLOBALS['__aiya_test_options'][StatsSettings::OPTION_NAME] = ['ops_unit_cost' => null];

        self::assertSame(0.0, StatsSettings::unitCost());
    }
}
