<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentManagementModule;
use PHPUnit\Framework\TestCase;

/**
 * The 0.96.0 settings split: the five operational fields that left the
 * Frontend page option move to the content-management option. Absent keys
 * skip (the reader falls back to the field default, so copying nothing is
 * exact), values already on the new side never get overwritten, an
 * emptied old option is deleted, and the whole migration is a no-op when
 * nothing was stored.
 */
final class ContentSettingsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testMovesTheStoredFieldsAndStripsTheOldOption(): void
    {
        update_option('aiya_core_frontend', [
            'color_primary' => '#e94f69',
            'notification_retention' => 45,
            'credit_retention' => 90,
            'seo_keywords' => 'aiya, headless',
            'seo_description' => 'A headless site',
            'ga_measurement_id' => 'G-TEST123',
            'default_thumb' => 12,
        ]);

        ContentManagementModule::migrateFrontendSplit();

        $content = get_option('aiya_core_content');
        self::assertSame([
            'notification_retention' => 45,
            'credit_retention' => 90,
            'seo_keywords' => 'aiya, headless',
            'seo_description' => 'A headless site',
            'ga_measurement_id' => 'G-TEST123',
        ], $content);
        // The presentation fields stay put; nothing of the migrated set remains.
        $frontend = get_option('aiya_core_frontend');
        self::assertSame(['color_primary' => '#e94f69', 'default_thumb' => 12], $frontend);
    }

    public function testDeletesTheOldOptionOnceItEmpties(): void
    {
        update_option('aiya_core_frontend', ['seo_keywords' => 'only']);

        ContentManagementModule::migrateFrontendSplit();

        self::assertSame(['seo_keywords' => 'only'], get_option('aiya_core_content'));
        self::assertFalse(get_option('aiya_core_frontend'));
    }

    public function testNeverOverwritesValuesAlreadyOnTheNewSide(): void
    {
        update_option('aiya_core_frontend', ['seo_keywords' => 'old']);
        update_option('aiya_core_content', ['seo_keywords' => 'newer']);

        ContentManagementModule::migrateFrontendSplit();

        self::assertSame(['seo_keywords' => 'newer'], get_option('aiya_core_content'));
    }

    public function testIsANoOpWithoutAStoredFrontendOption(): void
    {
        ContentManagementModule::migrateFrontendSplit();

        self::assertFalse(get_option('aiya_core_content', false));
        self::assertFalse(get_option('aiya_core_frontend', false));
    }
}
