<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\PaymentSettings;
use PHPUnit\Framework\TestCase;

/**
 * The payment domain's settings reader — the gateway credentials half of
 * the former merged membership reader (0.111.0 split); the assertions
 * travelled with the code. The plan/tier pointer the Afdian half used to
 * carry is gone (2026-10-27): the plans have their own table, read by
 * AfdianTiers, so this reader stays about credentials and channels only.
 */
final class PaymentSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
    }

    public function testAfdianCredentialsRead(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 'token',
        ]);

        $settings = PaymentSettings::read();

        self::assertTrue($settings['afdianEnable']);
        self::assertSame('user-1', $settings['afdianUserId']);
        self::assertSame('token', $settings['afdianToken']);
    }

    /**
     * The retired pointer keys are dead storage: an option that still carries
     * them from a pre-0.134.0 site reads exactly the same shape, and nothing
     * surfaces them to a consumer.
     */
    public function testRetiredPointerKeysDoNotLeakIntoTheShape(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 'token',
            'afdian_plan_id' => 'plan-gold',
            'afdian_tier' => 'gold',
        ]);

        $settings = PaymentSettings::read();

        self::assertSame(
            ['epayEnable', 'epayPid', 'epayKey', 'epayGateway', 'epayMethods', 'afdianEnable', 'afdianUserId', 'afdianToken'],
            array_keys($settings)
        );
    }

    public function testUnconfiguredAfdianHalfReadsEmpty(): void
    {
        $settings = PaymentSettings::read();

        self::assertFalse($settings['afdianEnable']);
        self::assertSame('', $settings['afdianUserId']);
        self::assertSame('', $settings['afdianToken']);
    }
}
