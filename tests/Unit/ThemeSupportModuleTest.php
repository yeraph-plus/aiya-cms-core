<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\ThemeSupport\ThemeSupportModule;
use PHPUnit\Framework\TestCase;

final class ThemeSupportModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_theme_features'] = [];
    }

    public function testRegistersOnCanonicalThemeHooks(): void
    {
        (new ThemeSupportModule())->register();

        self::assertArrayHasKey('after_setup_theme', $GLOBALS['__aiya_test_filters']);
        self::assertArrayHasKey('pre_option_image_default_link_type', $GLOBALS['__aiya_test_filters']);
    }

    public function testDeclaresPostThumbnailsWithoutArguments(): void
    {
        (new ThemeSupportModule())->declareSupports();

        // No arguments means "all types at the check level"; the real
        // whitelist is each type's own thumbnail support.
        self::assertTrue($GLOBALS['__aiya_test_theme_features']['post-thumbnails']);
    }

    public function testImageDefaultLinkTypeIsPinnedToNone(): void
    {
        (new ThemeSupportModule())->register();

        self::assertSame('none', apply_filters('pre_option_image_default_link_type', false));
    }
}
