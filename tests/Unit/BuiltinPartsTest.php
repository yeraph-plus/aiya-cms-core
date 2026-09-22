<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Parts\BuiltinParts;
use Aiya\Core\Domain\Parts\PartType;
use PHPUnit\Framework\TestCase;

/**
 * The core part vocabulary: dialog-side build() (stored shortcode markup)
 * and render-side markup (plain native HTML for the format parts,
 * purpose-named marker tags for alert).
 */
final class BuiltinPartsTest extends TestCase
{
    /** @var array<string, PartType> */
    private array $parts;

    protected function setUp(): void
    {
        $this->parts = (new BuiltinParts())->registerParts([]);
    }

    public function testRegistersTheCoreVocabulary(): void
    {
        self::assertSame(
            ['list', 'col_list', 'collapse', 'alert', 'button', 'clip_board', 'post_id'],
            array_keys($this->parts)
        );
        self::assertTrue((new BuiltinParts())->registerParts(['x' => 'keep-me'])['x'] === 'keep-me', 'foreign entries pass through untouched');
    }

    /**
     * The card reads a post, so its renderer is injected from outside the
     * parts domain. Uninjected it stays a declaration — the part contract's
     * editor-only state — rather than registering a shortcode that would
     * silently render nothing.
     */
    public function testTheCardIsDeclarationOnlyWithoutAnInjectedRenderer(): void
    {
        self::assertNull($this->parts[BuiltinParts::POST_CARD_TAG]->render);

        $injected = (new BuiltinParts(static fn (int $id): string => 'CARD' . $id))->registerParts([]);
        $render = $injected[BuiltinParts::POST_CARD_TAG]->render;
        self::assertNotNull($render);
        self::assertSame('CARD7', $render(['id' => '7'], ''));
    }

    public function testListBuildsMarkupAndRendersPlainListHtml(): void
    {
        $list = $this->parts['list'];
        self::assertSame('[list order="true"]a
b[/list]', $list->build(['content' => "a\nb", 'order' => true]));

        $rendered = ($list->render)(["order" => 'true'], "<p>a\nb</p>\n");
        self::assertSame("<ol>\n<li>a</li>\n<li>b</li>\n</ol>\n", $rendered);
        self::assertSame("<ul>\n<li>a</li>\n</ul>\n", ($list->render)(['order' => 'false'], 'a'));
    }

    public function testColListAlternatesTermsAndDescriptions(): void
    {
        $colList = $this->parts['col_list'];

        $rendered = ($colList->render)(['dt_width' => '2'], "term one\ndescription one\nterm two");
        self::assertSame(
            "<dl data-ratio=\"2\">\n<dt>term one</dt>\n<dd>description one</dd>\n<dt>term two</dt>\n</dl>\n",
            $rendered
        );
        // Anything off the whitelist folds back to the default ratio.
        self::assertStringContainsString('<dl data-ratio="1">', ($colList->render)(['dt_width' => 'evil'], 'x'));
    }

    public function testCollapseRendersNativeDetails(): void
    {
        $collapse = $this->parts['collapse'];

        $rendered = ($collapse->render)(['title' => 'Weird "&" <title>'], 'the body');
        self::assertSame("<details>\n<summary>Weird &quot;&amp;&quot; &lt;title&gt;</summary>\nthe body\n</details>\n", $rendered);
    }

    public function testAlertValidatesTheLevel(): void
    {
        $alert = $this->parts['alert'];

        self::assertStringContainsString('<alert level="warning" title="Heads up">', ($alert->render)(['type' => 'warning', 'name' => 'Heads up'], 'body'));
        self::assertStringContainsString('<alert level="default" title="">', ($alert->render)(['type' => 'evil'], 'body'));
    }

    public function testButtonShapesTheLink(): void
    {
        $button = $this->parts['button'];

        self::assertSame('', ($button->render)(['href' => ''], 'no href'));
        self::assertSame(
            "<a href=\"https://aiya.test\" class=\"part-button part-button-primary\">Label</a>\n",
            ($button->render)(['href' => 'https://aiya.test', 'target' => '_self', 'variant' => 'primary'], 'Label')
        );
        self::assertSame(
            "<a href=\"https://aiya.test\" target=\"_blank\" rel=\"noopener\" class=\"part-button part-button-outline\">https://aiya.test</a>\n",
            ($button->render)(['href' => 'https://aiya.test', 'target' => '_blank', 'variant' => 'outline'], '')
        );
    }

    public function testClipBoardStripsToPlainText(): void
    {
        $clip = $this->parts['clip_board'];

        self::assertSame(
            "<span data-clipboard-slot>magnet:?xt=urn:btih:xyz</span>\n",
            ($clip->render)([], "  <em>magnet:?xt=urn:btih:xyz</em>\n")
        );
        self::assertSame('', ($clip->render)([], '  <p></p> '), 'a textless body renders nothing');
    }
}
