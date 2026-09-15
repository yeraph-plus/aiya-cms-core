<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\EditorPlugins;
use PHPUnit\Framework\TestCase;

/**
 * The carried-in TinyMCE plugins: exactly the four core-unbundled 4.x
 * builds register as external plugins, and their buttons join the tool
 * bars idempotently (the legacy layout put toc on row one, table and
 * codesample on row two).
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

    public function testTocJoinsRowOneAfterWpMore(): void
    {
        $row = (new EditorPlugins())->firstRowButtons(['bold', 'wp_more', 'wp_page']);

        self::assertSame(['bold', 'wp_more', 'toc', 'wp_page'], $row);
    }

    public function testTocAppendsWhenWpMoreIsAbsent(): void
    {
        $row = (new EditorPlugins())->firstRowButtons(['bold', 'italic']);

        self::assertSame(['bold', 'italic', 'toc'], $row);
    }

    public function testTableAndCodesampleJoinRowTwoOnce(): void
    {
        $editor = new EditorPlugins();

        $row = $editor->secondRowButtons(['formatselect']);
        self::assertSame(['formatselect', 'table', 'codesample'], $row);
        self::assertSame(['formatselect', 'table', 'codesample'], $editor->secondRowButtons($row), 'idempotent on re-filter');
        self::assertSame(['toc'], $editor->firstRowButtons(['toc']), 'idempotent on re-filter');
    }
}
