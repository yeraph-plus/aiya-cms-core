<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Owns the front-end shell configuration served through GET
 * /aiya/core/v1/site: presentation defaults (initial color mode, the
 * site-wide fallback cover, the header banner switch + image) and the
 * compliance footer strings. Media fields store attachment IDs;
 * SitePresenter resolves them to URLs.
 *
 * One cover setting serves every surface that has to supply an image —
 * list cards, category cards and the article hero. Each consumer derives
 * its own crop from that single attachment (640x360 for cards, 1000x240
 * for the hero), so the surfaces never drift apart.
 *
 * This page is the presentation/media half of the old shell settings; the
 * operational half (retention periods, SEO head values, NSFW vocabularies)
 * lives on the content-management page since 0.96.0.
 *
 * The basic-settings group carries the front-end domain: the canonical
 * origin the back end uses when it has to name the front end itself
 * (password-reset links and the admin-bar shortcut since 0.97.0, more
 * consumers planned). It lives here rather than on the Security page,
 * whose host allowlist it replaces.
 */
final class FrontendModule implements Module
{
    public const OPTION_NAME = 'aiya_core_frontend';

    public const MIGRATION_VERSION = '0.97.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        add_filter('aiya_core_schema_migrations', static function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'migrateResetHostAllowlist']];

            return $migrations;
        });
    }

    public function settings(): void
    {
        // No parent: this page owns the plugin's top-level menu ("AIYA CMS Core")
        // and sits first in the submenu list — the shell settings are the
        // most-used surface.
        $this->settings->addPage([
            'slug' => 'frontend',
            'title' => __('Frontend', 'aiya-core'),
            'menu_title' => __('AIYA CMS Core', 'aiya-core'),
            'icon' => 'dashicons-admin-generic',
            'position' => 81,
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'note_source',
                    'type' => 'note',
                    'variant' => 'info',
                    'label' => __('These fields feed the front-end shell through GET /aiya/core/v1/site; leave a footer string empty to keep it out of the footer.', 'aiya-core'),
                    'default' => null,
                ],
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
                    'id' => 'heading_appearance',
                    'type' => 'heading',
                    'label' => __('Presentation defaults', 'aiya-core'),
                    'level' => '2',
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
                    'id' => 'default_thumb',
                    'type' => 'media',
                    'label' => __('Site fallback cover', 'aiya-core'),
                    'description' => __('Used wherever a cover is missing: list card thumbnails, category cards and the article hero — each surface derives its own crop from this one image.', 'aiya-core'),
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
                    'id' => 'heading_banner',
                    'type' => 'heading',
                    'label' => __('Header banner', 'aiya-core'),
                    'level' => '2',
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

    /**
     * 0.97.0: the Security page's password-reset host allowlist became
     * this page's single frontend domain. The first non-empty allowlist
     * entry carries over — the documented use was the one front-end
     * host, and the multi-host slack has no place in the canonical-domain
     * model — unless the new field already holds a value. The old key
     * leaves the security option either way.
     */
    public static function migrateResetHostAllowlist(): void
    {
        $security = get_option('aiya_core_security');
        $security = is_array($security) ? $security : [];
        if (!array_key_exists('password_reset_allowed_hosts', $security)) {
            return;
        }

        $hosts = $security['password_reset_allowed_hosts'];
        if (is_string($hosts)) {
            $hosts = [$hosts];
        }
        $first = '';
        foreach ((array) $hosts as $host) {
            $host = trim((string) $host);
            if ($host !== '') {
                $first = $host;
                break;
            }
        }

        unset($security['password_reset_allowed_hosts']);

        if ($first !== '') {
            $frontend = get_option('aiya_core_frontend');
            $frontend = is_array($frontend) ? $frontend : [];
            if (!array_key_exists('frontend_domain', $frontend)) {
                $frontend['frontend_domain'] = $first;
            }
            update_option('aiya_core_frontend', $frontend, false);
        }

        if ($security === []) {
            delete_option('aiya_core_security');
        } else {
            update_option('aiya_core_security', $security, false);
        }
    }
}
