<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentManagementModule;
use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Credit\CreditModule;
use Aiya\Core\Domain\Discussion\DiscussionModule;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\IdentityModule;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Operations\OperationsModule;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The flattened chain: every migration entry lives at the single version
 * '1.0.0' — six dbDelta table installer entries (idempotent by
 * construction: they create fresh and reconcile pre-1.0 databases; the
 * discussion entry alone installs boards, threads, replies and likes)
 * plus four idempotent data carriers (option moves and the avatar value
 * conversion). A post-1.0 migration landing at a new version is a
 * conscious act that updates this test, never an accident of copying an
 * old constant.
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
            new SponsorshipModule($settings),
            new OperationsModule(),
            new ContentManagementModule($settings),
            new FrontendModule($settings),
            new AvatarModule($settings),
        ];
        foreach ($modules as $module) {
            $module->register();
        }

        $migrations = apply_filters('aiya_core_schema_migrations', []);

        self::assertCount(10, $migrations, 'six table installers plus four data carriers');
        foreach ($migrations as $migration) {
            self::assertSame('1.0.0', $migration['version'], 'the chain stays flat at one version');
            self::assertIsCallable($migration['callback']);
        }
    }
}
