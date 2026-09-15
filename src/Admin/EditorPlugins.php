<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;

/**
 * Classic-editor TinyMCE extensions (0.70.0 batch, legacy
 * classic-editor-modify rebuild): four upstream 4.x plugins the core
 * bundle does not ship — table, codesample, toc, advlist — carried in
 * assets/js/mce/ and registered through mce_external_plugins. The core
 * runs TinyMCE 4.9.11, the same major line these builds target.
 *
 * Buttons: `toc` joins the first row after wp_more (the legacy layout),
 * `table` and `codesample` append to the second row. advlist ships no
 * button — it augments the bundled bullist/numlist buttons once loaded.
 * `textpattern` is deliberately NOT carried: the core ships the
 * overlapping wptextpattern and both together double-transform input;
 * `image`/`media` are core-bundled already.
 */
final class EditorPlugins implements Module
{
    private const EXTERNAL = ['advlist', 'table', 'toc', 'codesample'];

    public function register(): void
    {
        add_filter('mce_external_plugins', [$this, 'registerPlugins']);
        add_filter('mce_buttons', [$this, 'firstRowButtons']);
        add_filter('mce_buttons_2', [$this, 'secondRowButtons']);
    }

    /**
     * @param array<string, string> $plugins
     * @return array<string, string>
     */
    public function registerPlugins(array $plugins): array
    {
        foreach (self::EXTERNAL as $name) {
            if (!isset($plugins[$name])) {
                $plugins[$name] = AIYA_CORE_URL . 'assets/js/mce/' . $name . '.plugin.min.js';
            }
        }

        return $plugins;
    }

    /**
     * @param list<string> $buttons
     * @return list<string>
     */
    public function firstRowButtons(array $buttons): array
    {
        if (in_array('toc', $buttons, true)) {
            return $buttons;
        }

        $after = array_search('wp_more', $buttons, true);
        if ($after !== false) {
            array_splice($buttons, $after + 1, 0, ['toc']);

            return $buttons;
        }

        $buttons[] = 'toc';

        return $buttons;
    }

    /**
     * @param list<string> $buttons
     * @return list<string>
     */
    public function secondRowButtons(array $buttons): array
    {
        foreach (['table', 'codesample'] as $button) {
            if (!in_array($button, $buttons, true)) {
                $buttons[] = $button;
            }
        }

        return $buttons;
    }
}
