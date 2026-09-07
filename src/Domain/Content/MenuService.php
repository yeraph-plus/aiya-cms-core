<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Api\Contract\MenuItem;
use WP_Post;

/**
 * Primary navigation for the front-end shell: the WP menu assigned to the
 * `primary` location, projected to contract items. Site-internal targets
 * are normalized to front-end paths, external links stay absolute. The
 * tree is cached per menu id and invalidated when a menu is saved.
 */
final class MenuService
{
    private const CACHE_GROUP = 'aiya_core';
    private const CACHE_KEY_PREFIX = 'menu_primary_';

    /** @return list<MenuItem> */
    public function primary(): array
    {
        $locations = get_nav_menu_locations();
        $menuId = is_array($locations) ? (int) ($locations['primary'] ?? 0) : 0;
        if ($menuId <= 0) {
            return [];
        }

        $cached = wp_cache_get(self::CACHE_KEY_PREFIX . $menuId, self::CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<MenuItem> */
            return array_values(array_filter($cached, static fn ($item): bool => $item instanceof MenuItem));
        }

        $items = $this->build($menuId);
        wp_cache_set(self::CACHE_KEY_PREFIX . $menuId, $items, self::CACHE_GROUP);

        return $items;
    }

    public function register(): void
    {
        add_action('wp_update_nav_menu', [$this, 'flushCache']);
    }

    public function flushCache(): void
    {
        // The menu id may change between saves; a group flush is cheapest.
        wp_cache_flush_group(self::CACHE_GROUP);
    }

    /** @return list<MenuItem> */
    private function build(int $menuId): array
    {
        $rows = wp_get_nav_menu_items($menuId);
        if (!is_array($rows)) {
            return [];
        }

        $nodes = [];
        $childrenOf = [];
        foreach ($rows as $row) {
            if (!$row instanceof WP_Post) {
                continue;
            }
            $parentId = (int) get_post_meta((int) $row->ID, '_menu_item_menu_item_parent', true);
            $node = new MenuItem(
                (int) $row->ID,
                (string) $row->post_title,
                $this->normalizeUrl((string) get_post_meta((int) $row->ID, '_menu_item_url', true), (int) $row->ID),
                get_post_meta((int) $row->ID, '_menu_item_target', true) === '_blank' ? 'blank' : 'self',
                []
            );
            $nodes[(int) $row->ID] = $node;
            $childrenOf[$parentId][] = (int) $row->ID;
        }

        /** @param list<MenuItem> $nodes */
        $attach = function (int $parentId) use (&$attach, $childrenOf, &$nodes): array {
            $branch = [];
            foreach ($childrenOf[$parentId] ?? [] as $childId) {
                $node = $nodes[$childId] ?? null;
                if ($node === null) {
                    continue;
                }
                $node = new MenuItem(
                    $node->id,
                    $node->label,
                    $node->url,
                    $node->target,
                    $attach($childId)
                );
                $branch[] = $node;
            }

            return $branch;
        };

        return $attach(0);
    }

    /**
     * Menu entries may be raw URLs, home-relative, or empty (object
     * archives resolve to their archive). Anything pointing at this site
     * becomes a bare path; the rest passes through as-is.
     */
    private function normalizeUrl(string $raw, int $itemId): string
    {
        $raw = trim($raw);
        if ($raw !== '') {
            $home = (string) home_url();
            $host = (string) wp_parse_url($raw, PHP_URL_HOST);
            $homeHost = (string) wp_parse_url($home, PHP_URL_HOST);
            if ($host === '' || strcasecmp($host, $homeHost) === 0) {
                $path = (string) wp_parse_url($raw, PHP_URL_PATH);
                $query = (string) wp_parse_url($raw, PHP_URL_QUERY);

                return '/' . ltrim($path, '/') . ($query !== '' ? '?' . $query : '');
            }

            return $raw;
        }

        // Empty URL: a post-type archive or term entry; resolve its object.
        $objectType = (string) get_post_meta($itemId, '_menu_item_object', true);
        $objectId = (int) get_post_meta($itemId, '_menu_item_object_id', true);
        if ($objectType === 'page' && $objectId > 0) {
            return '/' . ltrim((string) wp_parse_url((string) get_permalink($objectId), PHP_URL_PATH), '/');
        }

        return '/';
    }
}
