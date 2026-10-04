<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * Dashicons list (the WPJAM Basic 图标列表 page, kept display-only):
 * parses the core dashicons stylesheet for its glyph class names and lays
 * every icon out as a card grid. No interactions, no dialog, no request
 * input — a read-only reference sheet for picking class names.
 */
final class IconsPage
{
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the icon list.', 'aiya-core'));
        }

        Ui::pageHead(__('Icons', 'aiya-core'), sprintf(
            /* translators: %s: dashicons.css path */
            __('Every glyph in the core dashicons font, parsed from %s.', 'aiya-core'),
            '<code>' . esc_html(WPINC . '/css/dashicons.css') . '</code>'
        ));
        $this->gridSection();
        Ui::pageFoot();
    }

    private function gridSection(): void
    {
        $path = ABSPATH . WPINC . '/css/dashicons.css';
        if (!is_readable($path)) {
            Ui::notice(sprintf(
                /* translators: %s: dashicons.css path */
                __('The dashicons stylesheet was not found at %s.', 'aiya-core'),
                $path
            ), ['variant' => 'error']);

            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local core stylesheet, wp_remote_get does not apply
        $icons = self::parseIconNames((string) file_get_contents($path));
        if ($icons === []) {
            Ui::notice(__('No dashicons were found in the stylesheet.', 'aiya-core'), ['variant' => 'error']);

            return;
        }
        ?>
        <div class="aiya-icons-grid">
            <?php foreach ($icons as $icon) : ?>
                <span class="aiya-icon-card">
                    <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                    <span class="aiya-icon-name"><?php echo esc_html($icon); ?></span>
                </span>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Icon names in stylesheet order: every `.dashicons-x:before` selector
     * is one glyph, minus the `.dashicons-before:before` apply-glyph
     * utility. The base64 font payload cannot produce false matches — the
     * dot and colon anchors are outside the base64 alphabet.
     *
     * @return list<string>
     */
    private static function parseIconNames(string $css): array
    {
        if (!preg_match_all('/\.dashicons-([a-z0-9_-]+):before/', $css, $matches)) {
            return [];
        }

        return array_values(array_diff(array_unique($matches[1]), ['before']));
    }
}
