<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Owns the front-end navigation: two plain repeaters of label/url/target
 * rows on the "Navigation" settings page — primary_items and
 * secondary_items, named for the menu groups they feed. The row list IS
 * the menu — no WP nav-menu model and no locations; PrimaryMenu projects
 * the rows into the contract DTOs the Astro shell consumes. Rows render
 * in listed order.
 */
final class NavigationModule implements Module
{
    public const OPTION_NAME = 'aiya_core_navigation';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => 'navigation',
            'title' => __('Navigation', 'aiya-core'),
            'menu_title' => __('Navigation', 'aiya-core'),
            'parent' => 'aiya-core-sample',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'note_source',
                    'type' => 'note',
                    'variant' => 'info',
                    'label' => __('These lists replace the WordPress menu system: the front end reads them through GET /aiya/core/v1/menus/primary and /menus/secondary, rows in listed order.', 'aiya-core'),
                    'default' => null,
                ],
                [
                    'id' => 'primary_items',
                    'type' => 'repeater',
                    'label' => __('Primary menu', 'aiya-core'),
                    'description' => __('The main navigation of the front-end shell (site header). Internal targets are front-end paths (/posts/), external ones absolute https URLs.', 'aiya-core'),
                    'default' => [],
                    'children' => $this->itemChildren(),
                ],
                [
                    'id' => 'secondary_items',
                    'type' => 'repeater',
                    'label' => __('Secondary menu', 'aiya-core'),
                    'description' => __('The auxiliary navigation of the front-end shell (footer, legal links). Same rules as the primary menu.', 'aiya-core'),
                    'default' => [],
                    'children' => $this->itemChildren(),
                ],
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function itemChildren(): array
    {
        return [
            [
                'id' => 'label',
                'type' => 'text',
                'label' => __('Label', 'aiya-core'),
                'required' => true,
            ],
            [
                'id' => 'url',
                'type' => 'url',
                'label' => __('URL', 'aiya-core'),
                'description' => __('Front-end path (/posts/) or external URL; empty falls back to the home path.', 'aiya-core'),
            ],
            [
                'id' => 'target',
                'type' => 'select',
                'label' => __('Open in', 'aiya-core'),
                'default' => 'self',
                'options' => [
                    'self' => __('Same window', 'aiya-core'),
                    'blank' => __('New window', 'aiya-core'),
                ],
            ],
        ];
    }
}
