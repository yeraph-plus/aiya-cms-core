<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\FrontendLocales;
use Aiya\Core\Settings\Registry;

/**
 * Owns the front-end shell configuration served through GET
 * /aiya/core/v1/site: presentation defaults (initial color mode, the
 * site-wide fallback cover, the header banner switch + image), the
 * compliance footer strings, the site-level SEO/analytics head values,
 * and the page blocks (home sections, the two navigation repeaters and
 * the page-top/bottom ad lists — contributed by BlocksModule through
 * addFields). Media fields store attachment IDs; SitePresenter resolves
 * them to URLs.
 *
 * One cover setting serves every surface that has to supply an image —
 * list cards, category cards and the article hero. Each consumer derives
 * its own crop from that single attachment (640x360 for cards, 1000x240
 * for the hero), so the surfaces never drift apart.
 *
 * The SEO/analytics fields have lived on this page before (0.96.0 moved
 * them to the content-management page; the settings regroup moved them
 * back, because they are shell head values served through the same GET
 * /site payload). Retention periods and the NSFW vocabularies stay
 * operational knobs and live on the Backend page.
 *
 * The basic-settings group carries the front-end domain: the canonical
 * origin the back end uses when it has to name the front end itself
 * (password-reset links and the admin-bar shortcut since 0.97.0, more
 * consumers planned).
 */
final class FrontendModule implements Module
{
    public const OPTION_NAME = 'aiya_core_frontend';

    /**
     * The configured front-end default locale, or null when the setting is
     * auto/unset/unusable — callers fall back to the WP site language. The
     * front end reads the same value through GET /site's `language` field,
     * so its dictionaries and this conversion trigger can never disagree.
     */
    public static function defaultLanguage(): ?string
    {
        $value = (string) aiya_core_opt('frontend', 'default_language', 'auto');

        return in_array($value, FrontendLocales::ALL, true) ? $value : null;
    }

    /** The locale a viewer starts from without an explicit choice of their own. */
    public static function anonymousLocale(): string
    {
        return self::defaultLanguage() ?? (string) get_locale();
    }


    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        // The /site payload (and everything else in the presenter cache
        // group) is cached for a few minutes; a settings save — or a
        // Reset, which goes through delete_option — drops the group so
        // the new shell configuration shows immediately.
        foreach (['update_option_' . self::OPTION_NAME, 'delete_option_' . self::OPTION_NAME] as $hook) {
            add_action($hook, static function (): void {
                // A drop-in older than the WP 6.1 flush_group contract has no
                // flush_group(); let the mirrors expire on their own TTLs
                // rather than fataling on a settings save.
                if (!function_exists('wp_cache_flush_group')
                    || (function_exists('wp_cache_supports') && !wp_cache_supports('flush_group'))
                ) {
                    return;
                }

                wp_cache_flush_group('aiya_core_content');
            }, 10, 0);
        }
    }

    public function settings(): void
    {
        // No parent: this page owns the plugin's top-level menu ("AIYA CMS Core")
        // and sits first in the submenu list — the shell settings are the
        // most-used surface. The mirror entry names the page itself so the
        // rail reads Frontend / Optimization / Backend / …
        $this->settings->addPage([
            'slug' => 'frontend',
            'title' => __('Frontend', 'aiya-core'),
            'menu_title' => __('AIYA CMS Core', 'aiya-core'),
            'mirror_title' => __('Frontend', 'aiya-core'),
            'icon' => 'dashicons-admin-generic',
            'position' => 81,
            'menu_position' => 1,
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_basic',
                    'type' => 'heading',
                    'label' => __('Basic settings', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'frontend_domain',
                    'type' => 'text',
                    'label' => __('Frontend domain', 'aiya-core'),
                    'description' => __('The canonical front-end origin including the scheme, for example https://www.example.com. When set, password-reset links always point here; more front-end-facing features will reuse this value. Leave empty to keep reset links on this site address.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'default_language',
                    'type' => 'select',
                    'label' => __('Front-end language', 'aiya-core'),
                    'description' => __('The interface language signed-out visitors start with; signed-in members override it from their account settings. Auto follows the WordPress site language.', 'aiya-core'),
                    'default' => 'auto',
                    'options' => [
                        'auto' => __('Auto (site language)', 'aiya-core'),
                        'zh_CN' => '简体中文',
                        'zh_TW' => '繁體中文',
                        'zh_HK' => '繁體中文（香港）',
                        'en_US' => 'English',
                    ],
                ],
                [
                    'id' => 'color_primary',
                    'type' => 'color',
                    'label' => __('Theme color', 'aiya-core'),
                    'description' => __('Buttons, links and active states across the front end derive from this color.', 'aiya-core'),
                    'default' => '#e94f69',
                ],
                [
                    'id' => 'default_color_mode',
                    'type' => 'select',
                    'label' => __('Default color mode', 'aiya-core'),
                    'description' => __('Color scheme a first-time visitor starts with.', 'aiya-core'),
                    'default' => 'system',
                    'options' => [
                        'system' => __('Follow the system', 'aiya-core'),
                        'dark' => __('Dark', 'aiya-core'),
                        'light' => __('Light', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'heading_images',
                    'type' => 'heading',
                    'label' => __('Fallback images & banner', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'default_thumb',
                    'type' => 'media',
                    'label' => __('Site fallback cover', 'aiya-core'),
                    'description' => __('Used wherever a card cover is missing: list card thumbnails and category cards — each surface derives its own crop from this one image. The article hero has its own default below.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'default_hero',
                    'type' => 'media',
                    'label' => __('Default article hero', 'aiya-core'),
                    'description' => __('The banner crop served as the article hero for posts whose author did not set a featured image. Leave unset to let those heroes fall back to the card cover chain instead.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'empty_image',
                    'type' => 'media',
                    'label' => __('Empty state image', 'aiya-core'),
                    'description' => __('Placeholder shown on empty lists and error cards across the front end.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'banner_enabled',
                    'type' => 'switch',
                    'label' => __('Show the header banner', 'aiya-core'),
                    'description' => __('Turns the compact top bar into a tall header backed by the banner image.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'banner_image',
                    'type' => 'media',
                    'label' => __('Banner image', 'aiya-core'),
                    'description' => __('Wide image shown behind the top navigation while the banner is on.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'heading_seo',
                    'type' => 'heading',
                    'label' => __('SEO & analytics', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'seo_keywords',
                    'type' => 'text',
                    'label' => __('SEO keywords', 'aiya-core'),
                    'description' => __('Comma-separated keywords for the site home page.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'seo_description',
                    'type' => 'textarea',
                    'label' => __('SEO description', 'aiya-core'),
                    'description' => __('Meta description for the site home page.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'ga_measurement_id',
                    'type' => 'text',
                    'label' => __('Google Analytics ID', 'aiya-core'),
                    'description' => __('Measurement ID (e.g. G-XXXXXXXXXX); the front end renders the analytics snippet from it. Leave empty to disable.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'heading_compliance',
                    'type' => 'heading',
                    'label' => __('Compliance footer', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'hitokoto',
                    'type' => 'switch',
                    'label' => __('Footer hitokoto', 'aiya-core'),
                    'description' => __('Show a random one-liner quote as the footer sign-off.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'beian_links',
                    'type' => 'repeater',
                    'label' => __('Compliance links', 'aiya-core'),
                    'description' => __('One row per filing shown in the footer, e.g. ICP or public security.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'label',
                            'type' => 'text',
                            'label' => __('Text', 'aiya-core'),
                            'default' => '',
                        ],
                        [
                            'id' => 'url',
                            'type' => 'url',
                            'label' => __('Link', 'aiya-core'),
                            'default' => '',
                        ],
                        [
                            'id' => 'icon',
                            'type' => 'radio',
                            'label' => __('Icon', 'aiya-core'),
                            'default' => 'shield',
                            'options' => [
                                'shield' => __('Shield (ICP)', 'aiya-core'),
                                'police' => __('Police badge', 'aiya-core'),
                                'custom' => __('Custom image', 'aiya-core'),
                            ],
                        ],
                        [
                            'id' => 'icon_url',
                            'type' => 'text',
                            'label' => __('Custom icon URL', 'aiya-core'),
                            'description' => __('Used when the icon template is set to custom.', 'aiya-core'),
                            'default' => '',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
