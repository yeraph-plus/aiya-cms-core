<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Shared\FrontendLocales;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The front-end default-language setting: it registers on the Frontend
 * page (auto default — the WP site language keeps ruling until the owner
 * picks), and the resolver answers only the front end's supported set,
 * so a stale or bogus stored value degrades to the site language instead
 * of leaking into get_locale's place.
 */
final class FrontendLanguageTest extends TestCase
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
}
