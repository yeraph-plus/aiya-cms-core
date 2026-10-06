<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\PaymentSettings;
use PHPUnit\Framework\TestCase;

/**
 * The payment domain's settings reader — the gateway credentials half of
 * the former merged membership reader (0.111.0 split); the assertions
 * travelled with the code. The Afdian half carries the single plan/tier
 * pairing (2026-10-06): the binding repeater and the fallback tier are
 * retired storage keys, dead data in existing options.
 */
final class PaymentSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
    }

    public function testAfdianPairingReadsSanitizedKeys(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 'token',
            'afdian_plan_id' => 'Plan-Month',
            'afdian_tier' => 'Month',
        ]);
        update_option('aiya_core_membership', [
            'tiers' => [['key' => 'month', 'name' => 'Month', 'price' => 10, 'cycle_days' => 30, 'credits_per_cycle' => 20]],
        ]);

        $settings = PaymentSettings::read();

        self::assertTrue($settings['afdianEnable']);
        self::assertSame('user-1', $settings['afdianUserId']);
        self::assertSame('plan-month', $settings['afdianPlanId'], 'the plan id is a key: sanitized to lowercase');
        self::assertSame('month', $settings['afdianTier']);
    }

    public function testUnconfiguredAfdianHalfReadsEmpty(): void
    {
        $settings = PaymentSettings::read();

        self::assertSame('', $settings['afdianPlanId']);
        self::assertSame('', $settings['afdianTier']);
    }
}
