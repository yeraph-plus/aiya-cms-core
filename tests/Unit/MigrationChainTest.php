<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentManagementModule;
use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Credit\CreditModule;
use Aiya\Core\Domain\Discussion\DiscussionModule;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\IdentityModule;
use Aiya\Core\Domain\Membership\MembershipModule;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Operations\OperationsModule;
use Aiya\Core\Domain\Payment\PaymentModule;
use Aiya\Core\Domain\Redeem\RedeemModule;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The flattened chain: every migration entry lives at the single version
 * '1.0.0' and is a pure table installer (eight dbDelta entries — the
 * 0.111.0 domain split turned the membership module's three-table
 * installer into the membership, payment and redeem modules' one-table
 * installers each — idempotent by construction: they create fresh and
 * reconcile pre-1.0 databases; the discussion entry alone installs
 * boards, threads, replies and likes). The four data carriers of the
 * pre-1.0 era retired with the 1.0.0 clean model — databases that need
 * them were reconciled by the 0.102.0 chain before this trim landed. A
 * post-1.0 migration landing at a new version is a conscious act that
 * updates this test, never an accident of copying an old constant.
 */
final class MigrationChainTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_filters'] = [];
    }

    public function testEveryRegisteredMigrationSharesTheFlatVersion(): void
    {
        $settings = new Registry();
        $modules = [
            new DiscussionModule(),
            new IdentityModule(new MetadataRegistry()),
            new NotificationModule(),
            new CreditModule($settings),
            new MembershipModule($settings),
            new PaymentModule($settings),
            new RedeemModule(),
            new OperationsModule(),
            new ContentManagementModule($settings),
            new FrontendModule($settings),
            new AvatarModule($settings),
        ];
        foreach ($modules as $module) {
            $module->register();
        }

        $migrations = apply_filters('aiya_core_schema_migrations', []);

        self::assertCount(8, $migrations, 'eight table installers, the data carriers retired with the clean 1.0.0 model');
        foreach ($migrations as $migration) {
            self::assertSame('1.0.0', $migration['version'], 'the chain stays flat at one version');
            self::assertIsCallable($migration['callback']);
        }
    }
}
