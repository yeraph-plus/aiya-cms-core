<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The image default settings stay two distinct sources: the card covers'
 * fallback attachment (default_thumb) and the article hero's own
 * (default_hero) — the banner deliberately stopped reusing the card
 * source, and both fields must exist for the split to hold.
 */
final class FrontendImageDefaultsTest extends TestCase
{
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
