<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use PHPUnit\Framework\TestCase;

final class SponsorshipSettingsTest extends TestCase
{
    /** @var list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}> */
    private array $tiers = [
        ['key' => 'month', 'name' => 'Month', 'price' => 10.0, 'cycleDays' => 30, 'creditsPerCycle' => 100],
        ['key' => 'season', 'name' => 'Season', 'price' => 25.0, 'cycleDays' => 90, 'creditsPerCycle' => 350],
    ];

    public function testTierByKeyResolvesExactMatchOnly(): void
    {
        $tier = SponsorshipSettings::tierByKey($this->tiers, 'season');
        self::assertNotNull($tier);
        self::assertSame('season', $tier['key']);
        self::assertNull(SponsorshipSettings::tierByKey($this->tiers, ''));
        self::assertNull(SponsorshipSettings::tierByKey($this->tiers, 'nope'));
    }

    public function testBindingRowsNormalizePlanAndTierKeys(): void
    {
        $bindings = SponsorshipSettings::bindings([
            'afdian_bindings' => [
                ['plan_id' => ' plan-month ', 'tier_key' => 'Month'],
                'garbage',
                ['plan_id' => 'plan-only', 'tier_key' => ''],
            ],
        ]);

        self::assertSame([
            ['planId' => 'plan-month', 'tierKey' => 'month'],
            ['planId' => 'plan-only', 'tierKey' => ''],
        ], $bindings);

        self::assertSame([], SponsorshipSettings::bindings([]));
    }

    public function testTierRowsNormalizeAndDropKeylessRows(): void
    {
        $normalized = SponsorshipSettings::tiers([
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
        $normalized = SponsorshipSettings::tiers([
            'tiers' => [['key' => 'basic', 'name' => 'Basic']],
        ]);

        self::assertSame(0.0, $normalized[0]['price']);
        self::assertSame(30, $normalized[0]['cycleDays']);
        self::assertSame(0, $normalized[0]['creditsPerCycle']);
        self::assertSame(1, $normalized[0]['cycles'], 'missing cycles falls back to a single cycle');
    }
}
