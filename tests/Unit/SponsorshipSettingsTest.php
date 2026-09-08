<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use PHPUnit\Framework\TestCase;

final class SponsorshipSettingsTest extends TestCase
{
    /** @var list<array{key:string,name:string,price:float,days:int}> */
    private array $plans = [
        ['key' => 'month', 'name' => 'Month', 'price' => 10.0, 'days' => 30],
        ['key' => 'quarter', 'name' => 'Quarter', 'price' => 25.0, 'days' => 90],
        ['key' => 'year', 'name' => 'Year', 'price' => 25.0, 'days' => 365],
    ];

    public function testPlanByKeyResolvesExactMatchOnly(): void
    {
        self::assertSame('quarter', SponsorshipSettings::planByKey($this->plans, 'quarter')['key']);
        self::assertNull(SponsorshipSettings::planByKey($this->plans, ''));
        self::assertNull(SponsorshipSettings::planByKey($this->plans, 'nope'));
    }
}
