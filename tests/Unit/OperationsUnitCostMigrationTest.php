<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Operations\OperationsModule;
use Aiya\Core\Domain\Operations\StatsSettings;
use PHPUnit\Framework\TestCase;

/**
 * 0.99.0: the report's rate moved off the membership settings page (the
 * sponsorship option) onto the report's own page (the operations option).
 * A numeric value moves across unless the target already holds one;
 * anything else drops; an emptied sponsorship option is deleted.
 */
final class OperationsUnitCostMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testCarriesTheRateIntoTheOperationsOption(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_sponsorship'] = [
            'epay_pid' => '123',
            'ops_unit_cost' => '0.25',
        ];

        OperationsModule::migrateUnitCost();

        self::assertSame(0.25, StatsSettings::unitCost());
        self::assertArrayNotHasKey('ops_unit_cost', $GLOBALS['__aiya_test_options']['aiya_core_sponsorship']);
        self::assertSame('123', $GLOBALS['__aiya_test_options']['aiya_core_sponsorship']['epay_pid']);
    }

    public function testNeverOverwritesAnExistingRate(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_sponsorship'] = [
            'ops_unit_cost' => '0.25',
        ];
        $GLOBALS['__aiya_test_options']['aiya_core_operations'] = [
            'ops_unit_cost' => 1.5,
        ];

        OperationsModule::migrateUnitCost();

        self::assertSame(1.5, StatsSettings::unitCost());
        // The sponsorship option held nothing else, so the whole option is gone.
        self::assertArrayNotHasKey('aiya_core_sponsorship', $GLOBALS['__aiya_test_options']);
    }

    public function testANonNumericRateDropsInsteadOfMoving(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_sponsorship'] = [
            'ops_unit_cost' => 'free',
        ];

        OperationsModule::migrateUnitCost();

        self::assertSame(0.0, StatsSettings::unitCost(), 'garbage answers the default, never poisons the report');
        self::assertArrayNotHasKey('aiya_core_operations', $GLOBALS['__aiya_test_options']);
    }

    public function testRatesBelowZeroReadAsZero(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_operations'] = ['ops_unit_cost' => -3.0];

        self::assertSame(0.0, StatsSettings::unitCost());
    }

    public function testAnUnsetRateAnswersTheDefault(): void
    {
        self::assertSame(0.0, StatsSettings::unitCost());
    }
}
