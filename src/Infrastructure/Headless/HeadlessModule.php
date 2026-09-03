<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Headless;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Strips WordPress surfaces that are meaningless for a headless backend
 * (block editor, site editor, customizer, block widgets, font library,
 * block patterns, pingbacks/trackbacks, XML-RPC, emoji, oEmbed discovery)
 * and cleans the remaining front-end head output.
 *
 * Comments are deliberately NOT stripped by default: WordPress keeps acting
 * as the comment store and moderation surface while the Astro front end
 * talks to the comments REST routes. disable_comments exists only as a
 * kill switch for setups that truly want no comment system at all.
 * Pingbacks and trackbacks are protocol-level spam vectors and stay
 * disabled by default regardless.
 *
 * Everything here works from a normal active plugin: core loads plugins
 * before admin_menu / init / rest_endpoints, and every guard below is a
 * runtime filter or a hook owned by admin requests. No MU plugin is
 * required, and staying a normal plugin keeps the master switch as a real
 * escape hatch instead of a hard-wired state.
 *
 * The toggles live in the aiya_core_headless option and default to "strip"
 * (comments: keep) so a fresh activation is headless by design; flipping
 * headless_mode off restores stock WordPress behaviour without code changes.
 */
final class HeadlessModule implements Module
{
    private const PAGE_SLUG = 'headless';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        add_action('init', [$this, 'apply'], 5);
        add_action('admin_menu', [$this, 'menus'], 99);
        add_action('admin_init', [$this, 'guardAdminPages']);
        add_filter('rest_endpoints', [$this, 'filterRestEndpoints']);
    }

    /**
     * @return bool True when a feature toggle is enabled; toggles default to
     *              enabled through the field defaults, so an unsaved option
     *              still yields a stripped site.
     */
    private function enabled(string $field): bool
    {
        return $this->masterOn() && (bool) aiya_core_opt(self::PAGE_SLUG, $field, true);
    }

    private function masterOn(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'headless_mode', true);
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Headless optimization', 'aiya-core'),
            'menu_title' => __('Headless optimization', 'aiya-core'),
            'parent' => 'aiya-core-sample',
            'option_name' => 'aiya_core_headless',
            'fields' => [
                [
                    'id' => 'headless_mode',
                    'type' => 'switch',
                    'label' => __('Master switch', 'aiya-core'),
                    'checkbox_label' => __('Strip headless-irrelevant WordPress features', 'aiya-core'),
                    'description' => __('Turn off to restore stock WordPress behaviour without code changes.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_block_editor',
                    'type' => 'switch',
                    'label' => __('Block editor (Gutenberg)', 'aiya-core'),
                    'checkbox_label' => __('Force the classic editor for every post type', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_appearance',
                    'type' => 'switch',
                    'label' => __('Appearance, themes, customizer, site editor', 'aiya-core'),
                    'checkbox_label' => __('Remove the appearance screens and block direct access', 'aiya-core'),
                    'description' => __('Theme switching stays available through WP-CLI.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_block_widgets',
                    'type' => 'switch',
                    'label' => __('Block widgets', 'aiya-core'),
                    'checkbox_label' => __('Revert the widgets screen to the classic interface', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_fonts_global_styles',
                    'type' => 'switch',
                    'label' => __('Font library and global styles', 'aiya-core'),
                    'checkbox_label' => __('Remove the font library screen and the font/global-style REST routes', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_block_patterns',
                    'type' => 'switch',
                    'label' => __('Block patterns and block directory', 'aiya-core'),
                    'checkbox_label' => __('Drop core block patterns, remote patterns and their REST routes', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_comments',
                    'type' => 'switch',
                    'label' => __('Comments (kill switch)', 'aiya-core'),
                    'checkbox_label' => __('Disable the comment system entirely: store, moderation screen and REST routes', 'aiya-core'),
                    'description' => __('Leave off by default: WordPress stays the comment store and moderation surface for the Astro front end.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'disable_pings',
                    'type' => 'switch',
                    'label' => __('Pingbacks and trackbacks', 'aiya-core'),
                    'checkbox_label' => __('Disable the pingback/trackback protocols entirely', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_xmlrpc',
                    'type' => 'switch',
                    'label' => __('XML-RPC', 'aiya-core'),
                    'checkbox_label' => __('Disable the XML-RPC server entirely', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_emoji',
                    'type' => 'switch',
                    'label' => __('Emoji', 'aiya-core'),
                    'checkbox_label' => __('Remove the emoji detection script and staticization filters', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'disable_oembed',
                    'type' => 'switch',
                    'label' => __('oEmbed discovery', 'aiya-core'),
                    'checkbox_label' => __('Remove discovery links, the embed route and auto-embeds', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'strip_frontend_head',
                    'type' => 'switch',
                    'label' => __('Front-end head cleanup', 'aiya-core'),
                    'checkbox_label' => __('Strip generator, RSD, shortlink, REST hints and block CSS from front-end output', 'aiya-core'),
                    'default' => true,
                ],
            ],
        ]);
    }

    /**
     * Applies the runtime strips. Runs on init priority 5, after the settings
     * registry has been populated on init priority 0.
     */
    public function apply(): void
    {
        if (!$this->masterOn()) {
            return;
        }

        if ($this->enabled('disable_block_editor')) {
            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');
        }

        if ($this->enabled('disable_block_widgets')) {
            add_filter('use_widgets_block_editor', '__return_false');
        }

        if ($this->enabled('disable_fonts_global_styles')) {
            remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles');
            remove_action('enqueue_block_assets', 'wp_enqueue_classic_theme_styles');
        }

        if ($this->enabled('disable_block_patterns')) {
            remove_theme_support('core-block-patterns');
            add_filter('should_load_remote_block_patterns', '__return_false');
        }

        if ($this->enabled('disable_pings')) {
            $this->stripPings();
        }

        if ($this->enabled('disable_comments')) {
            $this->stripComments();
        }

        if ($this->enabled('disable_xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
            remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
        }

        if ($this->enabled('disable_emoji')) {
            $this->stripEmoji();
        }

        if ($this->enabled('disable_oembed')) {
            $this->stripOembed();
        }

        if ($this->enabled('strip_frontend_head')) {
            $this->stripFrontendHead();
        }
    }

    /**
     * Protocol-level ping disablement. Runs by default and is independent of
     * the comment kill switch.
     */
    private function stripPings(): void
    {
        add_filter('pings_open', '__return_false');

        foreach (get_post_types(['public' => true]) as $post_type) {
            remove_post_type_support($post_type, 'trackbacks');
        }

        add_filter('xmlrpc_methods', static function (array $methods): array {
            $methods['pingback.ping'] = '__return_false';
            $methods['pingback.extensions.getPingbacks'] = '__return_false';
            return $methods;
        });
        remove_action('do_pings', 'do_all_pings', 10);
        remove_action('publish_post', '_publish_post_hook', 5);
    }

    /**
     * Full comment kill switch: store, moderation screen and REST routes.
     * Off by default — see the class docblock.
     */
    private function stripComments(): void
    {
        add_filter('comments_open', '__return_false');
        add_filter('option_default_comment_status', static fn (): string => 'closed');

        foreach (get_post_types(['public' => true]) as $post_type) {
            remove_post_type_support($post_type, 'comments');
        }
    }

    private function stripEmoji(): void
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('embed_head', 'print_emoji_detection_script');

        remove_filter('the_content', 'capital_P_dangit', 11);
        remove_filter('the_title', 'capital_P_dangit', 11);
        remove_filter('wp_title', 'capital_P_dangit', 11);
        remove_filter('document_title', 'capital_P_dangit', 11);
        remove_filter('comment_text', 'capital_P_dangit', 31);
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        add_filter('emoji_svg_url', '__return_false');
        add_filter('emoji_svg_ext', '__return_false');
        add_filter('tiny_mce_plugins', static fn (array $plugins): array => array_values(array_diff($plugins, ['wpemoji'])));
    }

    private function stripOembed(): void
    {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        remove_filter('rest_pre_serve_request', '_oembed_rest_pre_serve_request', 10);
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
        remove_filter('oembed_response_data', 'get_oembed_response_data_rich', 10);
        remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
        add_filter('embed_oembed_discover', '__return_false');

        if (isset($GLOBALS['wp_embed']) && $GLOBALS['wp_embed'] instanceof \WP_Embed) {
            $wp_embed = $GLOBALS['wp_embed'];
            remove_filter('the_content', [$wp_embed, 'autoembed'], 8);
            remove_filter('widget_text_content', [$wp_embed, 'autoembed'], 8);
            remove_filter('widget_block_content', [$wp_embed, 'autoembed'], 8);
        }

        add_action('wp_enqueue_scripts', [$this, 'dequeueEmbedScript'], 999);
    }

    private function stripFrontendHead(): void
    {
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        remove_action('template_redirect', 'wp_shortlink_header', 11);
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        remove_action('wp_head', 'wp_resource_hints', 2);
        remove_action('wp_head', 'wp_render_img_auto_sizes_contain_css');

        add_action('wp_enqueue_scripts', [$this, 'dequeueBlockAssets'], 999);
    }

    public function dequeueEmbedScript(): void
    {
        wp_deregister_script('wp-embed');
    }

    public function dequeueBlockAssets(): void
    {
        foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles'] as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    /**
     * Removes the appearance screens when the theme no longer owns any
     * presentation duties.
     */
    public function menus(): void
    {
        if ($this->enabled('disable_appearance')) {
            remove_menu_page('themes.php');
        }

        if ($this->enabled('disable_comments')) {
            remove_menu_page('edit-comments.php');
        }

        if ($this->enabled('disable_fonts_global_styles')) {
            remove_submenu_page('themes.php', 'font-library.php');
        }
    }

    /**
     * Blocks direct URL access to the removed screens; the admin menu alone
     * does not stop a hand-typed path.
     */
    public function guardAdminPages(): void
    {
        global $pagenow;

        if (!is_admin() || !is_string($pagenow)) {
            return;
        }

        $denied = [];
        if ($this->enabled('disable_appearance')) {
            $denied = ['themes.php', 'customize.php', 'site-editor.php'];
        }
        if ($this->enabled('disable_fonts_global_styles')) {
            $denied[] = 'font-library.php';
        }
        if ($this->enabled('disable_block_widgets')) {
            $denied[] = 'widgets.php';
        }

        if (in_array($pagenow, $denied, true)) {
            wp_die(
                esc_html__('This screen is disabled because the site runs headless. Manage the setting under AIYA Core > Headless optimization.', 'aiya-core'),
                '',
                ['response' => 403]
            );
        }
    }

    /**
     * Unregisters REST routes that only exist for the stripped surfaces.
     *
     * @param array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public function filterRestEndpoints(array $endpoints): array
    {
        $wpV2 = [];
        if ($this->enabled('disable_comments')) {
            $wpV2[] = 'comments';
        }
        if ($this->enabled('disable_fonts_global_styles')) {
            $wpV2[] = 'font-families';
            $wpV2[] = 'font-collections';
            $wpV2[] = 'global-styles';
        }
        if ($this->enabled('disable_block_patterns')) {
            $wpV2[] = 'block-patterns/patterns';
            $wpV2[] = 'block-patterns/categories';
        }

        $alternatives = [];
        if ($wpV2 !== []) {
            $alternatives[] = '^/wp/v2/(' . implode('|', $wpV2) . ')(?:/|$)';
        }
        if ($this->enabled('disable_oembed')) {
            $alternatives[] = '^/oembed/1\\.0/';
        }

        if ($alternatives === []) {
            return $endpoints;
        }

        $expression = '#(' . implode('|', $alternatives) . ')#';

        foreach (array_keys($endpoints) as $route) {
            if (preg_match($expression, (string) $route) === 1) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }
}
