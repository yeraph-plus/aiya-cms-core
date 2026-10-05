<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Credit\CreditSettings;
use PHPUnit\Framework\TestCase;

/**
 * The normalized credit policy reader: the check-in knobs live on the
 * membership page and every value floors/clamps to a sane range, the
 * ledger retention lives on the content page with its own fallback and
 * ten-year ceiling, and the spend waiver radio maps onto a role
 * capability that is judged on the holder — never on the session, and
 * never for an anonymous id.
 */
final class CreditSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_caps'] = false;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
    }

    // ---- the check-in policy ------------------------------------------------

    public function testReadReturnsTheCheckinDefaultsOnAFreshInstall(): void
    {
        self::assertSame(
            ['checkinEnabled' => true, 'checkinCredits' => 5, 'validityDays' => 30],
            CreditSettings::read()
        );
    }

    public function testReadProjectsTheConfiguredCheckinSettings(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['checkin_enable'] = false;
        $GLOBALS['__aiya_test_options']['membership']['checkin_credits'] = 12;
        $GLOBALS['__aiya_test_options']['membership']['credit_validity_days'] = 90;

        self::assertSame(
            ['checkinEnabled' => false, 'checkinCredits' => 12, 'validityDays' => 90],
            CreditSettings::read()
        );
    }

    public function testReadCastsTheCheckinSwitchToARealBool(): void
    {
        // The stored option is a string ('0'/'1') straight from the radio;
        // a truthy string must not leak through as its string self.
        $GLOBALS['__aiya_test_options']['membership']['checkin_enable'] = '0';
        self::assertFalse(CreditSettings::read()['checkinEnabled']);

        $GLOBALS['__aiya_test_options']['membership']['checkin_enable'] = '1';
        self::assertTrue(CreditSettings::read()['checkinEnabled']);
    }

    public function testReadFloorsNegativeCheckinCreditsAtZero(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['checkin_credits'] = -3;

        self::assertSame(0, CreditSettings::read()['checkinCredits']);
    }

    public function testReadFloorsTheValidityAtOneDay(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['credit_validity_days'] = 0;

        self::assertSame(1, CreditSettings::read()['validityDays']);
    }

    // ---- the ledger retention -------------------------------------------------

    public function testRetentionFallsBackToThirtyDaysWhenUnset(): void
    {
        self::assertSame(30, CreditSettings::retentionDays());
    }

    public function testRetentionReadsTheContentPageSetting(): void
    {
        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = 14;

        self::assertSame(14, CreditSettings::retentionDays());
    }

    public function testRetentionClampsAtTheTenYearCeiling(): void
    {
        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = 99999;
        self::assertSame(3650, CreditSettings::retentionDays());

        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = 3650;
        self::assertSame(3650, CreditSettings::retentionDays());
    }

    public function testARetentionOfZeroFallsBackToTheDefault(): void
    {
        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = 0;

        self::assertSame(30, CreditSettings::retentionDays());
    }

    public function testRetentionReadsANegativeSettingThroughAbsint(): void
    {
        // absint is the house idiom: the sign never survives the read.
        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = '-7';

        self::assertSame(7, CreditSettings::retentionDays());
    }

    public function testRetentionTreatsNonNumericJunkAsUnset(): void
    {
        $GLOBALS['__aiya_test_options']['content']['credit_retention'] = 'soon';

        self::assertSame(30, CreditSettings::retentionDays());
    }

    // ---- the spend waiver -----------------------------------------------------

    public function testTheWaiverLevelDefaultsToAdministrator(): void
    {
        self::assertSame('administrator', CreditSettings::spendExemptLevel());
        self::assertSame('manage_options', CreditSettings::exemptCapability());
    }

    public function testTheWaiverLevelAcceptsExactlyItsRadioLevels(): void
    {
        foreach (CreditSettings::EXEMPT_LEVELS as $level) {
            $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = $level;
            self::assertSame($level, CreditSettings::spendExemptLevel());
        }
    }

    public function testAnUnknownStoredLevelFallsBackToTheDefault(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'superadmin';
        self::assertSame('administrator', CreditSettings::spendExemptLevel());

        // The whitelist is exact: case differences are unknown values too.
        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'Admin';
        self::assertSame('administrator', CreditSettings::spendExemptLevel());
    }

    public function testTheWaiverLevelMapsOntoItsRoleCapability(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'author';
        self::assertSame('publish_posts', CreditSettings::exemptCapability());

        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'editor';
        self::assertSame('edit_others_posts', CreditSettings::exemptCapability());

        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'administrator';
        self::assertSame('manage_options', CreditSettings::exemptCapability());
    }

    public function testAnonymousHoldersNeverWaive(): void
    {
        // The caps shim answers permissively for every real holder; the
        // anonymous zero must fail the waiver before the capability check.
        $GLOBALS['__aiya_test_caps'] = true;

        self::assertFalse(CreditSettings::spendWaived(0));
        self::assertFalse(CreditSettings::spendWaived(-1));
    }

    public function testAHoldingUserWaivesOnTheHolderNotTheSession(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;

        self::assertTrue(CreditSettings::spendWaived(7));
    }

    public function testAHolderBelowTheLevelKeepsPaying(): void
    {
        self::assertFalse(CreditSettings::spendWaived(7));
    }
}
