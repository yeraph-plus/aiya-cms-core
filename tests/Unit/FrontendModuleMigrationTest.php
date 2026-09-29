<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\FrontendModule;
use PHPUnit\Framework\TestCase;

/**
 * 0.97.0: the Security page's password-reset host allowlist moved to the
 * Frontend page's single frontend domain. First entry carries over, the
 * new field never gets overwritten, and the old key leaves the security
 * option in every case.
 */
final class FrontendModuleMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testCarriesTheFirstAllowlistEntryIntoTheFrontendDomain(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_security'] = [
            'force_email_login' => true,
            'password_reset_allowed_hosts' => ['localhost:4321'],
        ];

        FrontendModule::migrateResetHostAllowlist();

        self::assertSame('localhost:4321', $GLOBALS['__aiya_test_options']['aiya_core_frontend']['frontend_domain']);
        self::assertArrayNotHasKey('password_reset_allowed_hosts', $GLOBALS['__aiya_test_options']['aiya_core_security']);
        self::assertTrue($GLOBALS['__aiya_test_options']['aiya_core_security']['force_email_login']);
    }

    public function testNeverOverwritesAnExistingFrontendDomain(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_security'] = [
            'password_reset_allowed_hosts' => ['old.example'],
        ];
        $GLOBALS['__aiya_test_options']['aiya_core_frontend'] = [
            'frontend_domain' => 'https://front.example',
        ];

        FrontendModule::migrateResetHostAllowlist();

        self::assertSame('https://front.example', $GLOBALS['__aiya_test_options']['aiya_core_frontend']['frontend_domain']);
        // The security option held nothing else, so the whole option is gone.
        self::assertArrayNotHasKey('aiya_core_security', $GLOBALS['__aiya_test_options']);
    }

    public function testAnEmptyAllowlistMigratesNothingButStillRemovesTheKey(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_security'] = [
            'password_reset_allowed_hosts' => ['', '   '],
        ];

        FrontendModule::migrateResetHostAllowlist();

        self::assertArrayNotHasKey('frontend_domain', $GLOBALS['__aiya_test_options']['aiya_core_frontend'] ?? []);
        // The security option held nothing else, so the whole option is gone.
        self::assertArrayNotHasKey('aiya_core_security', $GLOBALS['__aiya_test_options']);
    }

    public function testAMissingKeyIsANoOp(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_security'] = [
            'force_email_login' => true,
        ];

        FrontendModule::migrateResetHostAllowlist();

        self::assertArrayNotHasKey('aiya_core_frontend', $GLOBALS['__aiya_test_options']);
    }

    public function testAMissingSecurityOptionIsANoOp(): void
    {
        FrontendModule::migrateResetHostAllowlist();

        self::assertArrayNotHasKey('aiya_core_frontend', $GLOBALS['__aiya_test_options']);
        self::assertArrayNotHasKey('aiya_core_security', $GLOBALS['__aiya_test_options']);
    }

    public function testAMultiHostAllowlistKeepsOnlyTheFirstEntry(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_security'] = [
            'password_reset_allowed_hosts' => ['front.example', 'mirror.example'],
        ];

        FrontendModule::migrateResetHostAllowlist();

        self::assertSame('front.example', $GLOBALS['__aiya_test_options']['aiya_core_frontend']['frontend_domain']);
    }
}
