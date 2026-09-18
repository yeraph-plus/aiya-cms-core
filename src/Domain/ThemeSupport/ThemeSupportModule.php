<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\ThemeSupport;

use Aiya\Core\Contracts\Module;

/**
 * Plugin-owned theme supports for the headless back end.
 *
 * The legacy theme registered its support set on after_setup_theme
 * (framework-required register-theme-support); the shell theme boots
 * nothing, so this domain owns the declarations the admin editing surface
 * still needs — declared after themes (priority 20) so a theme's own
 * narrower registration is expanded rather than fought over.
 *
 *  - post-thumbnails: the featured-image metabox and the media-modal
 *    "set as featured" action are editing surfaces, not theme features.
 *    Declared with no arguments (all types at the check level); the real
 *    whitelist is each type's own `thumbnail` support — built-in post and
 *    page carry it, the resource CPT declares it, anything else never
 *    shows the UI. REST featured_media and the featured contract field
 *    are independent of this declaration and work regardless.
 *  - image_default_link_type pinned to 'none': legacy parity, the same
 *    legacy block forced this option on every boot. Content images must
 *    not link to WP-rendered attachment pages — the Astro front end has
 *    no route for them. Filtered, not persisted, so a fresh install or a
 *    reset option table yields the same behaviour.
 *  - Title prefixes and the excerpt continuation marker are trimmed the
 *    same way: password/private posts carry no "Protected:"/"Private:"
 *    prepend (the badge system is the front end's signal — a string
 *    prefix would leak into admin list tables and the API title alike),
 *    and the auto-excerpt tail is plain '...' instead of the core
 *    bracketed ellipsis for the WP-native surfaces (feeds, fallback template). The API strips the
 *    marker itself; the filter only changes what it has to strip.
 * Everything else the legacy theme declared stays retired with the theme
 * front end it served: title-tag and automatic-feed-links only render
 * wp_head output (the fallback template writes its own <title> and must
 * not grow feed links), menus have no headless consumer (the Navigation
 * settings are the API's source of truth; the screen is entrance-hidden
 * by the missing support and URL-blocked by HeadlessModule),
 * post-formats' only consumer (the Tweet domain) was cancelled, html5
 * shapes theme-rendered core markup, and custom-logo/custom-background
 * are customizer features with no shell consumer — the shell page
 * embeds the core Site Icon (Settings → General) directly instead.
 */
final class ThemeSupportModule implements Module
{
    public function register(): void
    {
        add_action('after_setup_theme', [$this, 'declareSupports'], 20);
        add_filter('pre_option_image_default_link_type', static fn (): string => 'none');
        // Both title filters pass a sprintf format wrapping the title, so
        // the prefix-free shape is a bare '%s' — returning '' would blank
        // the title itself.
        add_filter('protected_title_format', static fn (): string => '%s');
        add_filter('private_title_format', static fn (): string => '%s');
        add_filter('excerpt_more', static fn (): string => '...');
    }

    /**
     * Declares the plugin-owned supports on the canonical hook.
     */
    public function declareSupports(): void
    {
        add_theme_support('post-thumbnails');
    }
}
