<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Shared\FrontendLocales;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The FrontendModule settings surface: the default-language setting and
 * the two distinct image-default sources (2026-10-05 consolidation of
 * FrontendLanguageTest + FrontendImageDefaultsTest).
 */
final class FrontendModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testTheSettingRegistersOnTheFrontendPageWithAutoDefault(): void
    {
        $registry = new Registry();
        (new FrontendModule($registry))->settings();

        $fields = [];
        foreach ($registry->page('frontend')?->fields() ?? [] as $field) {
            $fields[$field->id()] = $field;
        }

        self::assertArrayHasKey('default_language', $fields);
        self::assertSame('select', $fields['default_language']->type());
        self::assertSame('auto', $fields['default_language']->defaultValue());
    }

    public function testDefaultLanguageAnswersOnlyConfiguredLocales(): void
    {
        $stored = &$GLOBALS['__aiya_test_options']['frontend']['default_language'];

        $stored = 'zh_TW';
        self::assertSame('zh_TW', FrontendModule::defaultLanguage());

        $stored = 'auto';
        self::assertNull(FrontendModule::defaultLanguage(), 'auto defers to the site language');

        $stored = 'xx_XX';
        self::assertNull(FrontendModule::defaultLanguage(), 'a bogus stored value degrades to the site language');

        unset($GLOBALS['__aiya_test_options']['frontend']['default_language']);
        self::assertNull(FrontendModule::defaultLanguage(), 'an unset setting defers to the site language');
    }

    public function testTheLocaleWhitelistIsTheSharedFrontEndSet(): void
    {
        self::assertSame(['zh_CN', 'zh_TW', 'zh_HK', 'en_US'], FrontendLocales::ALL);
    }

    /**
     * The image default settings stay two distinct sources: the card
     * covers' fallback attachment (default_thumb) and the article hero's
     * own (default_hero) — the banner deliberately stopped reusing the
     * card source, and both fields must exist for the split to hold.
     */
    public function testCardAndHeroDefaultsRegisterAsSeparateMediaFields(): void
    {
        $registry = new Registry();
        (new FrontendModule($registry))->settings();

        $fields = [];
        foreach ($registry->page('frontend')?->fields() ?? [] as $field) {
            $fields[$field->id()] = $field;
        }

        self::assertArrayHasKey('default_thumb', $fields);
        self::assertArrayHasKey('default_hero', $fields);
        self::assertNotSame($fields['default_thumb'], $fields['default_hero']);
        self::assertSame('media', $fields['default_thumb']->type());
        self::assertSame('media', $fields['default_hero']->type());
        self::assertSame(0, $fields['default_hero']->defaultValue());
    }
}
