<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Identity\AvatarModule;
use PHPUnit\Framework\TestCase;

/**
 * 0.99.0: the default-avatar field switched from a hand-typed URL
 * (`avatar_default_url`) to a media-library attachment (`avatar_default`).
 * Local upload URLs convert through attachment_url_to_postid, everything
 * else resets to empty, and the old key leaves the option in every case.
 */
final class AvatarDefaultAvatarMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_attachment_urls'] = [];
        $GLOBALS['__aiya_test_attachment_files'] = [];
    }

    public function testCarriesALocalUploadUrlAcrossAsAnAttachmentId(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_optimization'] = [
            'avatar_cdn_mirror' => 'qiniu',
            'avatar_default_url' => 'https://aiya.test/wp-content/uploads/2026/01/default.jpg',
        ];
        $GLOBALS['__aiya_test_attachment_urls']['https://aiya.test/wp-content/uploads/2026/01/default.jpg'] = 42;

        AvatarModule::migrateDefaultAvatar();

        self::assertSame(42, $GLOBALS['__aiya_test_options']['aiya_core_optimization']['avatar_default']);
        self::assertSame('qiniu', $GLOBALS['__aiya_test_options']['aiya_core_optimization']['avatar_cdn_mirror']);
        self::assertArrayNotHasKey('avatar_default_url', $GLOBALS['__aiya_test_options']['aiya_core_optimization']);
    }

    public function testAnExternalUrlResetsToEmpty(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_optimization'] = [
            'avatar_default_url' => 'https://cdn.example/avatar.png',
        ];

        AvatarModule::migrateDefaultAvatar();

        // Nothing carried over and the option held nothing else: no new
        // key, no option.
        self::assertArrayNotHasKey('aiya_core_optimization', $GLOBALS['__aiya_test_options']);
    }

    public function testNeverOverwritesAnExistingAttachment(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_optimization'] = [
            'avatar_default' => 7,
            'avatar_default_url' => 'https://aiya.test/wp-content/uploads/2026/01/old.jpg',
        ];
        $GLOBALS['__aiya_test_attachment_urls']['https://aiya.test/wp-content/uploads/2026/01/old.jpg'] = 42;

        AvatarModule::migrateDefaultAvatar();

        self::assertSame(7, $GLOBALS['__aiya_test_options']['aiya_core_optimization']['avatar_default']);
        self::assertArrayNotHasKey('avatar_default_url', $GLOBALS['__aiya_test_options']['aiya_core_optimization']);
    }

    public function testMigratesNothingWhenTheLegacyKeyIsAbsent(): void
    {
        $GLOBALS['__aiya_test_options']['aiya_core_optimization'] = ['avatar_cdn_mirror' => 'weavatar'];

        AvatarModule::migrateDefaultAvatar();

        self::assertSame(['avatar_cdn_mirror' => 'weavatar'], $GLOBALS['__aiya_test_options']['aiya_core_optimization']);
        self::assertArrayNotHasKey('avatar_default', $GLOBALS['__aiya_test_options']['aiya_core_optimization']);
    }

    public function testForceDefaultAvatarResolvesTheAttachmentAndFallsBack(): void
    {
        // The aiya_core_opt boundary stub keys by page slug, not option name.
        $GLOBALS['__aiya_test_options']['optimization'] = ['avatar_default' => 7];
        $GLOBALS['__aiya_test_attachment_files'][7] = 'https://aiya.test/wp-content/uploads/2026/01/default.jpg';
        $module = new AvatarModule(new \Aiya\Core\Settings\Registry());

        self::assertSame(
            'https://aiya.test/wp-content/uploads/2026/01/default.jpg',
            $module->forceDefaultAvatar('mystery')
        );

        $GLOBALS['__aiya_test_options']['optimization'] = ['avatar_default' => 0];
        self::assertSame('mystery', $module->forceDefaultAvatar('mystery'), 'no attachment leaves the core choice alone');

        $GLOBALS['__aiya_test_options']['optimization'] = ['avatar_default' => 99];
        self::assertSame('mystery', $module->forceDefaultAvatar('mystery'), 'a deleted attachment leaves the core choice alone');
    }
}
