<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shortcodes;

use Aiya\Core\Contracts\Module;

/**
 * The shortcode surface of the template-part catalog: parts that declare
 * a renderer become real shortcodes — the stored `[tag]` markup renders
 * into the part's custom HTML tag during `the_content`, and the front
 * end parses those tags into islands. The editor-side inserter UI is the
 * Admin layer's PartsDialog; this module owns rendering only.
 *
 * The domain also owns the shortcode namespace: the caption/gallery/media
 * shortcodes have no headless consumer, and `[embed]` cannot be removed
 * with remove_shortcode() — WP_Embed
 * re-registers it on every the_content pass — so BOTH of its content
 * channels are unhooked instead: run_shortcode (explicit `[embed]`
 * markup) and autoembed (bare URL lines, which would fire server-side
 * oEmbed requests during API rendering). Runs at init 11, after the
 * settings registry (init 0) has populated the catalog with filter
 * registrations.
 */
final class ShortcodesModule implements Module
{
    public function __construct(private ShortcodeRegistry $registry)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerRenderers'], 11);
    }

    public function registerRenderers(): void
    {
        foreach (['wp_caption', 'caption', 'gallery', 'playlist', 'audio', 'video'] as $retired) {
            remove_shortcode($retired);
        }
        if (isset($GLOBALS['wp_embed']) && $GLOBALS['wp_embed'] instanceof \WP_Embed) {
            $wp_embed = $GLOBALS['wp_embed'];
            foreach (['run_shortcode', 'autoembed'] as $method) {
                remove_filter('the_content', [$wp_embed, $method], 8);
                remove_filter('widget_text_content', [$wp_embed, $method], 8);
                remove_filter('widget_block_content', [$wp_embed, $method], 8);
            }
        }

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
}
