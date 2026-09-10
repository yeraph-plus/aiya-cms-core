<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

use Aiya\Core\Contracts\Module;

/**
 * The editor-side inserter UI for template parts, replacing the legacy
 * Thickbox inserter: a `media_buttons` action prints the toolbar button
 * (kept in the classic position), an admin-footer block carries the
 * dialog markup, and `wpdialogs` — the same jQuery UI Dialog wrapper the
 * core link dialog uses — opens it. Insertion goes through
 * window.send_to_editor so both the classic TinyMCE editor and the
 * QuickTags fallback receive the markup. Parts live in
 * Domain/Parts; this module only wires the editor surface.
 */
final class PartModule implements Module
{
    public function __construct(private PartRegistry $registry)
    {
    }

    public function register(): void
    {
        add_action('media_buttons', [$this, 'toolbarButton'], 20);
        add_action('admin_footer', [$this, 'dialogMarkup']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('init', [$this, 'registerRenderers'], 11);
    }

    /**
     * Parts that declare a renderer become real shortcodes: the stored
     * `[tag]` markup renders into the part's custom HTML tag during
     * `the_content`, and the front end parses those tags into islands.
     * Runs at init 11, after the settings registry (init 0) has populated
     * the catalog with filter registrations.
     */
    public function registerRenderers(): void
    {
        foreach ($this->registry->all() as $part) {
            if ($part->render === null || $part->tag === '' || shortcode_exists($part->tag)) {
                continue;
            }

            add_shortcode($part->tag, static function (array $atts, string|null $content, string $tag) use ($part): string {
                $attrs = \shortcode_atts($part->attributeDefaults(), $atts, $tag);

                return ($part->render)($attrs, $content ?? '');
            });
        }
    }

    /** The classic "Add media" toolbar position, as the legacy inserter kept. */
    public function toolbarButton(string $editorId = 'content'): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen->base !== 'post') {
            return;
        }

        printf(
            '<button type="button" class="button aiya-parts-open" data-editor="%s"><span class="dashicons dashicons-shortcode" aria-hidden="true"></span> %s</button>',
            esc_attr($editorId),
            esc_html__('Template parts', 'aiya-core')
        );
    }

    /** One hidden dialog skeleton; its lists fill at open time. */
    public function dialogMarkup(): void
    {
        global $pagenow;
        if ($pagenow !== 'post.php' && $pagenow !== 'post-new.php') {
            return;
        }

        $parts = [];
        foreach ($this->registry->all() as $part) {
            $parts[] = [
                'tag' => $part->tag,
                'label' => $part->label,
                'note' => $part->note,
                'template' => $part->template,
                'fields' => $part->fields,
            ];
        }

        ?>
        <div id="aiya-parts-dialog" class="hidden">
            <div class="aiya-parts-layout">
                <div class="aiya-parts-nav" role="listbox" aria-label="<?php esc_attr_e('Template parts', 'aiya-core'); ?>">
                    <ul id="aiya-parts-list"></ul>
                </div>
                <div class="aiya-parts-body">
                    <p id="aiya-parts-note" class="description"></p>
                    <form id="aiya-parts-fields" onsubmit="return false;"></form>
                    <p class="aiya-parts-preview-wrap">
                        <label for="aiya-parts-preview"><?php esc_html_e('Markup preview', 'aiya-core'); ?></label>
                        <textarea id="aiya-parts-preview" rows="3" readonly class="large-text code"></textarea>
                    </p>
                </div>
            </div>
            <div class="aiya-parts-actions">
                <button type="button" class="button button-primary" id="aiya-parts-insert"><?php esc_html_e('Insert', 'aiya-core'); ?></button>
                <button type="button" class="button" id="aiya-parts-cancel"><?php esc_html_e('Cancel', 'aiya-core'); ?></button>
            </div>
        </div>
        <script id="aiya-parts-bootstrap" type="application/json">
        <?php
            echo wp_json_encode([
                'title' => __('Template parts', 'aiya-core'),
                'emptyText' => __('No template parts are registered yet.', 'aiya-core'),
                'parts' => $parts,
            ]);
		?>
        </script>
        <?php
    }

    public function assets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'post.php' && $hookSuffix !== 'post-new.php') {
            return;
        }

        wp_enqueue_script('wpdialogs');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style(
            'aiya-parts-dialog',
            AIYA_CORE_URL . 'assets/css/parts-dialog.css',
            ['wp-jquery-ui-dialog'],
            AIYA_CORE_VERSION
        );
        wp_enqueue_script(
            'aiya-parts-dialog',
            AIYA_CORE_URL . 'assets/js/parts-dialog.js',
            ['wpdialogs'],
            AIYA_CORE_VERSION,
            true
        );
    }
}
