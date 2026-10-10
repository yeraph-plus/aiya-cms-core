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
 * Post-1.0 entries land at their own conscious version, and since the
 * 0.130.x flattening there is exactly one: 1.1.0, the entry that installs
 * both Telegram tables (the channel-feed mirror and the support chat's
 * messages) as pure CREATEs. The 1.3.0 / 1.4.0 column reconciliations
 * retired with it — the feed's CREATE has declared its final three columns
 * (chat_title / chat_username / entities) since it landed, so those two
 * guarded ALTER entries never had work to do.
 *
 * The 0.128.0 residue cleanup was the one entry that installed nothing
 * and sat at no 1.x milestone: it carried the plugin's own version on
 * purpose, so the runner's `stored < version` gate retired it by itself
 * once an install had passed through it. Reclaimed in 0.130.0 — the
 * chain is pure installers: nine entries, one version for the base and
 * one for the Telegram tables, no retirements, no option deletions, no
 * multi-step creation.
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

        self::assertCount(9, $migrations, 'eight 1.0.0 installers and the one Telegram installer');
        $flat = array_filter($migrations, static fn (array $migration): bool => $migration['version'] === '1.0.0');
        self::assertCount(8, $flat, 'the 1.0.0 base chain stays at eight (the data carriers retired with the clean model)');
        $post = array_values(array_filter($migrations, static fn (array $migration): bool => $migration['version'] !== '1.0.0'));
        self::assertSame(
            ['1.1.0'],
            array_column($post, 'version'),
            'the single conscious post-1.0 entry'
        );
        foreach ($post as $migration) {
            self::assertIsCallable($migration['callback']);
        }
    }
}
