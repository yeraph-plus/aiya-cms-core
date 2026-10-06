<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\PaymentSettings;
use PHPUnit\Framework\TestCase;

/**
 * The payment domain's settings reader — the gateway credentials half of
 * the former merged membership reader (0.111.0 split); the assertions
 * travelled with the code.
 */
final class PaymentSettingsTest extends TestCase
{
    public function testBindingRowsNormalizePlanAndTierKeys(): void
    {
        $bindings = PaymentSettings::bindings([
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

        self::assertSame([], PaymentSettings::bindings([]));
    }

}
