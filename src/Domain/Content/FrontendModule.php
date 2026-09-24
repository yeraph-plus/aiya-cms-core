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
 */
final class FrontendModule implements Module
{
    public const OPTION_NAME = 'aiya_core_frontend';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
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
                    'label' => __('Default cover image', 'aiya-core'),
                    'description' => __('Fallback cover for posts without a featured image or a generated one.', 'aiya-core'),
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
                    'id' => 'default_post_cover',
                    'type' => 'media',
                    'label' => __('Default post cover', 'aiya-core'),
                    'description' => __('Hero cover shown on article pages when the post has no featured image of its own (auto-cropped to the article banner ratio).', 'aiya-core'),
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
                [
                    'id' => 'heading_notifications',
                    'type' => 'heading',
                    'label' => __('Notifications', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'notification_retention',
                    'type' => 'number',
                    'label' => __('Notification retention (days)', 'aiya-core'),
                    'description' => __('A daily cleanup removes stored notifications older than this many days.', 'aiya-core'),
                    'default' => 30,
                    'min' => 1,
                    'max' => 3650,
                ],
                [
                    'id' => 'heading_credit_ledger',
                    'type' => 'heading',
                    'label' => __('Credit ledger', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'credit_retention',
                    'type' => 'number',
                    'label' => __('Ledger retention (days)', 'aiya-core'),
                    'description' => __('How long closed credit history (spent rows, emptied buckets) is kept before the daily cleanup removes it. Live unexpired buckets are never touched.', 'aiya-core'),
                    'default' => 30,
                    'min' => 1,
                    'max' => 3650,
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
            ],
        ]);
    }
}
