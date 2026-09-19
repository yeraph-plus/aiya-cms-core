<?php
/**
 * AIYA Headless Shell theme.
 *
 * The theme intentionally boots no framework and takes nothing from
 * aiya-core: its two appearance features ride WordPress's own surfaces —
 * the Site Icon (Settings → General, also the favicon source) is embedded
 * above the site title, and one Customizer field edits the card body —
 * so the shell stays editable with stock WP tooling even with the plugin
 * disabled. This is a classic theme: no block-editor surfaces exist here.
 * The Astro application owns the public frontend.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * The toolbar is a front-end edit affordance and this front end is Astro, so
 * it never renders on the shell pages. wp-admin is unaffected: core answers
 * is_admin_bar_showing() from is_admin() before it consults this filter.
 * index.php carries the single replacement entry for logged-in sessions.
 */
add_filter('show_admin_bar', '__return_false');

/*
 * This host is the headless backend plus the fallback shell — nothing it
 * serves belongs in a search index. The public site lives on the Astro
 * host with its own robots.txt, so both guards here are unconditional and
 * deliberately ignore the blog_public switch: that option expresses the
 * site's indexing intent (the Astro side), not this host's.
 */
add_filter('robots_txt', static function (): string {
    return "User-agent: *\nDisallow: /\n";
});

add_filter('wp_robots', static function (array $robots): array {
    $robots['noindex'] = true;
    $robots['nofollow'] = true;

    return $robots;
});

/*
 * The card body text joins the Customizer's Site Identity section, next to
 * the fields the shell page renders around it (site title, site icon).
 * Sanitized on write AND read (kses, idempotent).
 */
add_action('customize_register', static function (WP_Customize_Manager $wp_customize): void {
    $wp_customize->add_setting('aiya_shell_intro', [
        'default' => '',
        'sanitize_callback' => 'wp_kses_post',
    ]);

    $wp_customize->add_control('aiya_shell_intro', [
        'section' => 'title_tagline',
        'label' => __('Intro text', 'aiya-headless'),
        'description' => __('Body of the shell card, shown under the site title. Basic HTML is allowed (p, a, br, strong, em, code, blockquote). Empty falls back to the built-in English placeholder.', 'aiya-headless'),
        'type' => 'textarea',
    ]);
}, 10);
