<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;

/**
 * Classic-editor TinyMCE extensions: four upstream 4.x plugins the core
 * bundle does not ship — table, codesample, toc, advlist — carried in
 * assets/js/mce/ and registered through mce_external_plugins. The core
 * runs TinyMCE 4.9.11, the same major line these builds target.
 *
 * Buttons: `toc` joins the first row after wp_more, `underline` and
 * `strikethrough` join row one after italic, and the second row gains
 * `fontsizeselect` / `fontselect` beside the core forecolor plus
 * `table` / `codesample` at the end, idempotent on every filter pass.
 * advlist ships no button —
 * it augments the bundled bullist/numlist buttons once loaded.
 * `textpattern` is deliberately NOT carried: the core ships the
 * overlapping wptextpattern and both together double-transform input;
 * `image`/`media` are core-bundled already.
 *
 * Two editor-side conveniences: the post
 * author dropdown lists content authors only (who=authors — subscribers
 * are not authors), and the "most used tags" cloud drops core's 45-term
 * cap so the picker shows every tag (scoped to that one AJAX request).
 */
final class EditorPlugins implements Module
{
    private const EXTERNAL = ['advlist', 'table', 'toc', 'codesample'];

    public function register(): void
    {
        add_filter('mce_external_plugins', [$this, 'registerPlugins']);
        add_filter('mce_buttons', [$this, 'firstRowButtons']);
        add_filter('mce_buttons_2', [$this, 'secondRowButtons']);
        add_filter('wp_dropdown_users_args', [$this, 'limitAuthorDropdown'], 10, 2);
        // Priority 5: the filter must be in place before the core handler
        // (priority 10) runs its get_terms call.
        add_action('wp_ajax_get-tagcloud', [$this, 'liftTagcloudCap'], 5);
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
        if (!in_array('underline', $buttons, true)) {
            $after = array_search('italic', $buttons, true);
            if ($after !== false) {
                array_splice($buttons, $after + 1, 0, ['underline', 'strikethrough']);
            } else {
                $buttons = array_merge($buttons, ['underline', 'strikethrough']);
            }
        }

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
        if (!in_array('fontsizeselect', $buttons, true)) {
            $after = array_search('forecolor', $buttons, true);
            if ($after !== false) {
                array_splice($buttons, $after + 1, 0, ['fontsizeselect', 'fontselect']);
            } else {
                $buttons = array_merge(['fontsizeselect', 'fontselect'], $buttons);
            }
        }

        foreach (['table', 'codesample'] as $button) {
            if (!in_array($button, $buttons, true)) {
                $buttons[] = $button;
            }
        }

        return $buttons;
    }

    /**
     * The post author dropdown lists content authors only: subscribers
     * (the register-a-account crowd) are not authoring candidates. Scoped
     * to the `post_author` dropdown; every other users dropdown keeps its
     * own semantics.
     *
     * @param array<string, mixed> $queryArgs
     * @param array<string, mixed> $parsedArgs
     * @return array<string, mixed>
     */
    public function limitAuthorDropdown(array $queryArgs, array $parsedArgs): array
    {
        if (($parsedArgs['name'] ?? '') === 'post_author') {
            $queryArgs['who'] = 'authors';
        }

        return $queryArgs;
    }

    /**
     * Registers the term-cap lift for the running get-tagcloud request;
     * the core handler's own get_terms call (next, at priority 10) then
     * answers with every term instead of the 45 most used.
     */
    public function liftTagcloudCap(): void
    {
        add_filter('get_terms_args', [$this, 'dropTermCap']);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function dropTermCap(array $args): array
    {
        if ((int) ($args['number'] ?? 0) === 45) {
            $args['number'] = 0;
        }

        return $args;
    }
}
