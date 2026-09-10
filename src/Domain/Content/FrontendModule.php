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
        // No parent: this page owns the plugin's top-level menu ("AIYA Core")
        // and sits first in the submenu list — the shell settings are the
        // most-used surface.
        $this->settings->addPage([
            'slug' => 'frontend',
            'title' => __('Frontend', 'aiya-core'),
            'menu_title' => __('AIYA Core', 'aiya-core'),
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
                    'id' => 'heading_branding',
                    'type' => 'heading',
                    'label' => __('Branding', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'color_primary',
                    'type' => 'color',
                    'label' => __('Brand color', 'aiya-core'),
                    'description' => __('Buttons, links and active states across the front end derive from this color.', 'aiya-core'),
                    'default' => '#e94f69',
                ],
                [
                    'id' => 'heading_appearance',
                    'type' => 'heading',
                    'label' => __('Presentation defaults', 'aiya-core'),
                    'level' => '2',
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
                    'id' => 'icp_beian',
                    'type' => 'text',
                    'label' => __('ICP filing', 'aiya-core'),
                    'description' => __('ICP filing number shown in the footer, e.g. 京ICP备2026000001号.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'mps_beian',
                    'type' => 'text',
                    'label' => __('Public security filing', 'aiya-core'),
                    'description' => __('Public security network filing number, e.g. 京公网安备11010000000001号.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'mps_code',
                    'type' => 'text',
                    'label' => __('Public security filing code', 'aiya-core'),
                    'description' => __('Digits only; the front end links the filing number to the police search page.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'footer_note',
                    'type' => 'textarea',
                    'label' => __('Footer note', 'aiya-core'),
                    'description' => __('Free-form footer text such as copyright or site statements.', 'aiya-core'),
                    'default' => '',
                ],
            ],
        ]);
    }
}
