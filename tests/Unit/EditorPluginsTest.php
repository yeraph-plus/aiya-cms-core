<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\EditorPlugins;
use PHPUnit\Framework\TestCase;

/**
 * The carried-in TinyMCE plugins: exactly the four core-unbundled 4.x
 * builds register as external plugins, and their buttons join the tool
 * bars idempotently. The bars also grow the legacy layout's own additions
 * (0.100.0): underline/strikethrough on row one, font controls on row two,
 * the post author dropdown narrowing to content authors, and the tag
 * picker's 45-term cap lifted for its own AJAX request.
 */
final class EditorPluginsTest extends TestCase
{
    public function testRegistersExactlyTheFourCarriedPlugins(): void
    {
        $plugins = (new EditorPlugins())->registerPlugins([]);

        self::assertSame(
            [
                'advlist' => 'https://aiya.test/wp-content/plugins/aiya-core/assets/js/mce/advlist.plugin.min.js',
                'table' => 'https://aiya.test/wp-content/plugins/aiya-core/assets/js/mce/table.plugin.min.js',
                'toc' => 'https://aiya.test/wp-content/plugins/aiya-core/assets/js/mce/toc.plugin.min.js',
                'codesample' => 'https://aiya.test/wp-content/plugins/aiya-core/assets/js/mce/codesample.plugin.min.js',
            ],
            $plugins
        );
    }

    public function testForeignPluginRegistrationsSurvive(): void
    {
        $plugins = (new EditorPlugins())->registerPlugins(['other' => 'https://aiya.test/other.js']);

        self::assertSame('https://aiya.test/other.js', $plugins['other']);
        self::assertCount(5, $plugins);
    }

    public function testRowOneGainsUnderlineStrikethroughAndToc(): void
    {
        // The core's own first row: underline/strikethrough follow italic,
        // toc follows wp_more (the legacy layout).
        $row = (new EditorPlugins())->firstRowButtons([
            'formatselect', 'bold', 'italic', 'bullist', 'numlist', 'blockquote',
            'alignleft', 'alignright', 'link', 'wp_more', 'spellchecker',
        ]);

        self::assertSame([
            'formatselect', 'bold', 'italic', 'underline', 'strikethrough', 'bullist', 'numlist',
            'blockquote', 'alignleft', 'alignright', 'link', 'wp_more', 'toc', 'spellchecker',
        ], $row);
    }

    public function testRowOneAppendsWhenAnchorsAreAbsent(): void
    {
        $row = (new EditorPlugins())->firstRowButtons(['bold', 'wp_page']);

        self::assertSame(['bold', 'wp_page', 'underline', 'strikethrough', 'toc'], $row);
    }

    public function testTocJoinsRowOneAfterWpMore(): void
    {
        $row = (new EditorPlugins())->firstRowButtons(['bold', 'wp_more', 'wp_page']);

        // No italic anchor: the pair appends at the end, toc still slots
        // after wp_more.
        self::assertSame(
            ['bold', 'wp_more', 'toc', 'wp_page', 'underline', 'strikethrough'],
            $row
        );
    }

    public function testTableAndCodesampleJoinRowTwoOnce(): void
    {
        $editor = new EditorPlugins();

        $row = $editor->secondRowButtons(['formatselect']);
        self::assertSame(['fontsizeselect', 'fontselect', 'formatselect', 'table', 'codesample'], $row);
        self::assertSame(
            ['fontsizeselect', 'fontselect', 'formatselect', 'table', 'codesample'],
            $editor->secondRowButtons($row),
            'idempotent on re-filter'
        );
        self::assertSame(['toc', 'underline', 'strikethrough'], $editor->firstRowButtons(['toc']), 'toc idempotent; the pair lands once');
    }

    public function testFontControlsFollowForecolorWhenPresent(): void
    {
        $row = (new EditorPlugins())->secondRowButtons(['strikethrough', 'forecolor', 'charmap']);

        self::assertSame(['strikethrough', 'forecolor', 'fontsizeselect', 'fontselect', 'charmap', 'table', 'codesample'], $row);
    }

    public function testThePostAuthorDropdownNarrowsToContentAuthors(): void
    {
        $editor = new EditorPlugins();

        $args = $editor->limitAuthorDropdown(['show_option_all' => ''], ['name' => 'post_author']);
        self::assertSame('authors', $args['who']);

        // Any other users dropdown keeps its own semantics.
        $untouched = $editor->limitAuthorDropdown(['name' => 'reassign_user'], ['name' => 'reassign_user']);
        self::assertArrayNotHasKey('who', $untouched);
    }

    public function testTheTagcloudCapLiftsOnlyForThe45TermRequest(): void
    {
        $editor = new EditorPlugins();

        self::assertSame(['number' => 0, 'taxonomy' => 'post_tag'], $editor->dropTermCap(['number' => 45, 'taxonomy' => 'post_tag']));
        self::assertSame(['number' => 12], $editor->dropTermCap(['number' => 12]), 'other term queries keep their cap');
        self::assertSame([], $editor->dropTermCap([]));
    }
}
