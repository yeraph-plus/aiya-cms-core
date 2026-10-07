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
use Aiya\Core\Domain\Telegram\TelegramModule;
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
 * them were reconciled by the 0.102.0 chain before this trim landed.
 * Post-1.0 entries land at their own conscious version — 1.1.0 the
 * Telegram channel feed's table, 1.2.0 the support chat's messages,
 * 1.3.0 the feed's entities column, 1.4.0 the feed's channel identity — and every one of them updates
 * this test, never an accident of copying an old constant.
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
            new TelegramModule($settings),
            new ContentManagementModule($settings),
            new FrontendModule($settings),
            new AvatarModule($settings),
        ];
        foreach ($modules as $module) {
            $module->register();
        }

        $migrations = apply_filters('aiya_core_schema_migrations', []);

        self::assertCount(12, $migrations, 'eight 1.0.0 installers plus the four Telegram entries');
        $flat = array_filter($migrations, static fn (array $migration): bool => $migration['version'] === '1.0.0');
        self::assertCount(8, $flat, 'the 1.0.0 base chain stays at eight (the data carriers retired with the clean model)');
        $post = array_values(array_filter($migrations, static fn (array $migration): bool => $migration['version'] !== '1.0.0'));
        self::assertSame(['1.1.0', '1.2.0', '1.3.0', '1.4.0'], array_column($post, 'version'), 'the conscious post-1.0 chain, in order');
        foreach ($post as $migration) {
            self::assertIsCallable($migration['callback']);
        }
    }
}
