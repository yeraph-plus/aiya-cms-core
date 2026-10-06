<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\SlugModule;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;

/**
 * The ID slug modes force the generated candidate on every save through the
 * wp_unique_post_slug filter, whose return value core never re-sanitizes —
 * so the case semantics live or die in idCandidate. Since 0.98.0 the
 * candidate keeps the XDE output's native mixed case (the frozen 62-char
 * alphabet is the algorithm's strength; the site-owner dropped legacy
 * byte-identity), while the pinyin path stays lowercased exactly as before.
 */
final class SlugModuleTest extends TestCase
{
    private SlugModule $module;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $this->module = new SlugModule(new Registry());
    }

    public function testTheBvCandidateKeepsTheNativeMixedCase(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['slug_post_mode'] = 'id_bv';
        $GLOBALS['__aiya_test_options']['backend']['slug_id_prefix'] = 'TV';

        $expected = 'TV' . (new IdSlugEncoder(8))->encodeId(383);
        self::assertNotSame(strtolower($expected), $expected, 'the fixture must actually carry uppercase');
        self::assertSame($expected, $this->module->forcedIdSlug('whatever-old', 383, 'publish', 'post', 0));
    }

    public function testTheAvCandidateStaysDigitPadded(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['slug_post_mode'] = 'id_av';
        $GLOBALS['__aiya_test_options']['backend']['slug_id_prefix'] = '';

        self::assertSame('00000383', $this->module->forcedIdSlug('old', 383, 'publish', 'post', 0));
    }

    public function testModeOffLeavesTheIncomingSlugAlone(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['slug_post_mode'] = 'off';

        self::assertSame('editor-slug', $this->module->forcedIdSlug('editor-slug', 383, 'publish', 'post', 0));
    }

    public function testUnsupportedTypesAreNeverRewritten(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['slug_post_mode'] = 'id_bv';

        self::assertSame('editor-slug', $this->module->forcedIdSlug('editor-slug', 383, 'publish', 'nav_menu_item', 0));
    }

    public function testTheCandidateSurvivesTheDedupeRoundTrip(): void
    {
        // forcedIdSlug re-runs the candidate through wp_unique_post_slug for
        // deduplication; the pass-through double proves the mixed case comes
        // back out the other side untouched.
        $GLOBALS['__aiya_test_options']['backend']['slug_post_mode'] = 'id_bv';
        $GLOBALS['__aiya_test_options']['backend']['slug_id_prefix'] = '';

        $expected = (new IdSlugEncoder(8))->encodeId(383);
        self::assertSame($expected, $this->module->forcedIdSlug(strtolower($expected), 383, 'publish', 'post', 0),
            'a stored lowercase slug migrates to the native mixed case on the next save');
    }
}
