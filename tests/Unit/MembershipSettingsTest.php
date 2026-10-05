<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\MembershipSettings;
use PHPUnit\Framework\TestCase;

final class MembershipSettingsTest extends TestCase
{
    /** @var list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}> */
    private array $tiers = [
        ['key' => 'month', 'name' => 'Month', 'price' => 10.0, 'cycleDays' => 30, 'creditsPerCycle' => 100],
        ['key' => 'season', 'name' => 'Season', 'price' => 25.0, 'cycleDays' => 90, 'creditsPerCycle' => 350],
    ];

    public function testTierByKeyResolvesExactMatchOnly(): void
    {
        $tier = MembershipSettings::tierByKey($this->tiers, 'season');
        self::assertNotNull($tier);
        self::assertSame('season', $tier['key']);
        self::assertNull(MembershipSettings::tierByKey($this->tiers, ''));
        self::assertNull(MembershipSettings::tierByKey($this->tiers, 'nope'));
    }

    public function testTierRowsNormalizeAndDropKeylessRows(): void
    {
        $normalized = MembershipSettings::tiers([
            'tiers' => [
                ['key' => 'Gold', 'name' => 'Gold', 'description' => '  Entry plan  ', 'price' => '15.5', 'cycle_days' => '45', 'credits_per_cycle' => '200', 'cycles' => '12'],
                ['key' => '', 'name' => 'Keyless'],
                'garbage',
            ],
        ]);

        self::assertSame([
            ['key' => 'gold', 'name' => 'Gold', 'description' => 'Entry plan', 'enabled' => true, 'price' => 15.5, 'cycleDays' => 45, 'creditsPerCycle' => 200, 'cycles' => 12],
        ], $normalized);
    }

    public function testTierDefaultsApplyWhenFieldsMissing(): void
    {
        $normalized = MembershipSettings::tiers([
            'tiers' => [['key' => 'basic', 'name' => 'Basic']],
        ]);

        self::assertSame(0.0, $normalized[0]['price']);
        self::assertSame(30, $normalized[0]['cycleDays']);
        self::assertSame(0, $normalized[0]['creditsPerCycle']);
        self::assertSame(1, $normalized[0]['cycles'], 'missing cycles falls back to a single cycle');
    }

    public function testTierPriceClampsToTwoDecimalsAndCap(): void
    {
        $normalized = MembershipSettings::tiers([
            'tiers' => [
                ['key' => 'precise', 'name' => 'Precise', 'price' => '12.3456'],
                ['key' => 'greedy', 'name' => 'Greedy', 'price' => '999'],
            ],
        ]);

        self::assertSame(12.35, $normalized[0]['price'], 'extra precision folds to two decimals');
        self::assertSame(500.0, $normalized[1]['price'], 'rows saved before the cap cannot exceed 500');
    }
}
