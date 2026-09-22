<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Owns the front-end page blocks: the two navigation repeaters
 * (primary_items / secondary_items, named for the menu groups they
 * feed), the page-top and page-bottom advertisement lists and the
 * carousel. The row lists ARE the blocks — no WP nav-menu model and no
 * ad-rotation machinery; ContentBlocks projects the rows into the
 * contract DTOs the Astro shell consumes, through `GET /site`'s
 * `blocks` group. Rows render in listed order.
 */
final class BlocksModule implements Module
{
    public const OPTION_NAME = 'aiya_core_blocks';

    public const PAGE_SLUG = 'blocks';

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
            'slug' => 'blocks',
            'title' => __('Blocks', 'aiya-core'),
            'menu_title' => __('Blocks', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'note_source',
                    'type' => 'note',
                    'variant' => 'info',
                    'label' => __('These lists replace the WordPress menu system and feed the shell\'s dynamic slots: the front end reads them through the `blocks` group of GET /aiya/core/v1/site, rows in listed order.', 'aiya-core'),
                    'default' => null,
                ],
                [
                    'id' => 'primary_items',
                    'type' => 'repeater',
                    'label' => __('Primary menu', 'aiya-core'),
                    'description' => __('The main navigation of the front-end shell (site header). Internal targets are front-end paths (/posts/), external ones absolute https URLs.', 'aiya-core'),
                    'default' => [
                        ['label' => 'Home', 'url' => '/', 'icon' => 'home', 'target' => 'self'],
                        ['label' => 'Posts', 'url' => '/posts/', 'icon' => 'file-text', 'target' => 'self'],
                        ['label' => 'Pages', 'url' => '/pages/', 'icon' => 'files', 'target' => 'self'],
                        ['label' => 'Resources', 'url' => '/resources/', 'icon' => 'image', 'target' => 'self'],
                        ['label' => 'Categories', 'url' => '/categories/', 'icon' => 'tags', 'target' => 'self'],
                        ['label' => 'Community', 'url' => '/community/', 'icon' => 'message-circle', 'target' => 'self'],
                    ],
                    'children' => $this->itemChildren(true),
                ],
                [
                    'id' => 'secondary_items',
                    'type' => 'repeater',
                    'label' => __('Secondary menu', 'aiya-core'),
                    'description' => __('The auxiliary navigation of the front-end shell (footer, legal links). Same rules as the primary menu.', 'aiya-core'),
                    'default' => [
                        ['label' => 'Robots', 'url' => '/robots.txt', 'target' => 'self'],
                        ['label' => 'Sitemap', 'url' => '/sitemap.xml', 'target' => 'self'],
                    ],
                    'children' => $this->itemChildren(),
                ],
                [
                    'id' => 'ads_top',
                    'type' => 'repeater',
                    'label' => __('Page-top advertisements', 'aiya-core'),
                    'description' => __('Ad slots rendered at the top of front-end pages, in listed order.', 'aiya-core'),
                    'default' => [],
                    'children' => $this->slotChildren(),
                ],
                [
                    'id' => 'ads_bottom',
                    'type' => 'repeater',
                    'label' => __('Page-bottom advertisements', 'aiya-core'),
                    'description' => __('Ad slots rendered at the bottom of front-end pages, in listed order.', 'aiya-core'),
                    'default' => [],
                    'children' => $this->slotChildren(),
                ],
                [
                    'id' => 'carousel',
                    'type' => 'repeater',
                    'label' => __('Carousel', 'aiya-core'),
                    'description' => __('Carousel slides for the front-end banner slot, in listed order.', 'aiya-core'),
                    'default' => [],
                    'children' => $this->slideChildren(),
                ],
            ],
        ]);
    }

    /**
     * Row fields for one menu repeater. Primary rows additionally carry
     * an optional `icon` (a Lucide name the front-end sidebar renders);
     * when empty the front end picks an icon from the row's URL shape.
     *
     * @return list<array<string, mixed>>
     */
    private function itemChildren(bool $withIcon = false): array
    {
        $children = [
            [
                'id' => 'label',
                'type' => 'text',
                'label' => __('Label', 'aiya-core'),
                'required' => true,
            ],
            [
                'id' => 'url',
                'type' => 'url',
                'allow_path' => true,
                'label' => __('URL', 'aiya-core'),
                'description' => __('Front-end path (/posts/) or external URL; empty falls back to the home path.', 'aiya-core'),
            ],
        ];
        if ($withIcon) {
            $children[] = [
                'id' => 'icon',
                'type' => 'text',
                'label' => __('Icon', 'aiya-core'),
                'description' => __('Optional Lucide icon name for the sidebar (e.g. "home", "image", "file-text").', 'aiya-core'),
            ];
        }
        $children[] = [
            'id' => 'target',
            'type' => 'select',
            'label' => __('Open in', 'aiya-core'),
            'default' => 'self',
            'options' => [
                'self' => __('Same window', 'aiya-core'),
                'blank' => __('New window', 'aiya-core'),
            ],
        ];

        return $children;
    }

    /**
     * Row fields for one advertisement slot: the click target, the link
     * text (also the image alt) and the banner image from the media
     * library.
     *
     * @return list<array<string, mixed>>
     */
    private function slotChildren(): array
    {
        return [
            [
                'id' => 'url',
                'type' => 'url',
                'allow_path' => true,
                'label' => __('Link', 'aiya-core'),
                'description' => __('Front-end path or external URL; empty renders the banner without a link.', 'aiya-core'),
            ],
            [
                'id' => 'label',
                'type' => 'text',
                'label' => __('Link text', 'aiya-core'),
                'description' => __('Also used as the banner image alt text.', 'aiya-core'),
            ],
            [
                'id' => 'image',
                'type' => 'media',
                'label' => __('Ad image', 'aiya-core'),
                'description' => __('The banner artwork from the media library.', 'aiya-core'),
                'default' => 0,
            ],
        ];
    }

    /**
     * Row fields for one carousel slide.
     *
     * @return list<array<string, mixed>>
     */
    private function slideChildren(): array
    {
        return [
            [
                'id' => 'title',
                'type' => 'text',
                'label' => __('Title', 'aiya-core'),
                'required' => true,
            ],
            [
                'id' => 'url',
                'type' => 'url',
                'allow_path' => true,
                'label' => __('Link', 'aiya-core'),
                'description' => __('Front-end path or external URL; empty renders the slide without a link.', 'aiya-core'),
            ],
            [
                'id' => 'image',
                'type' => 'media',
                'label' => __('Slide image', 'aiya-core'),
                'description' => __('The slide artwork from the media library.', 'aiya-core'),
                'default' => 0,
            ],
        ];
    }
}
