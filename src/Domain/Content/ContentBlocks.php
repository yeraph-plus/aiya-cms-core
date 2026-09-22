<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Api\Contract\AdSlot;
use Aiya\Core\Api\Contract\CarouselSlide;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\MenuItem;
use Aiya\Core\Api\Contract\SiteBlocks;

/**
 * Projects the Blocks settings rows into the shell contract's `blocks`
 * group: the two navigation menus plus the advertisement and carousel
 * slot lists. The row list IS the block — no WP nav-menu model, no
 * locations, no ad rotation; rows render in listed order and the
 * 1-based row position doubles as the contract id. A missing label or
 * title drops the row. Primary rows may carry an optional Lucide icon
 * name; secondary rows (footer menu) never project one.
 */
final class ContentBlocks
{
    /**
     * Projects every block group in one pass — /site is the single
     * consumer and the payload travels as one shell-cached unit.
     */
    public function all(): SiteBlocks
    {
        return new SiteBlocks(
            $this->menu((array) aiya_core_opt(BlocksModule::PAGE_SLUG, 'primary_items', []), true),
            $this->menu((array) aiya_core_opt(BlocksModule::PAGE_SLUG, 'secondary_items', [])),
            $this->ads((array) aiya_core_opt(BlocksModule::PAGE_SLUG, 'ads_top', [])),
            $this->ads((array) aiya_core_opt(BlocksModule::PAGE_SLUG, 'ads_bottom', [])),
            $this->carousel((array) aiya_core_opt(BlocksModule::PAGE_SLUG, 'carousel', [])),
        );
    }

    /**
     * @param list<mixed> $rows
     * @return list<MenuItem>
     */
    private function menu(array $rows, bool $withIcon = false): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $icon = $withIcon ? sanitize_text_field((string) ($row['icon'] ?? '')) : '';
            $items[] = new MenuItem(
                count($items) + 1,
                $label,
                $this->normalizeUrl((string) ($row['url'] ?? '')),
                ($row['target'] ?? '') === 'blank' ? 'blank' : 'self',
                $icon !== '' ? $icon : null,
                []
            );
        }

        return $items;
    }

    /**
     * @param list<mixed> $rows
     * @return list<AdSlot>
     */
    private function ads(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $image = $this->attachmentImage((int) ($row['image'] ?? 0), $label);
            if ($label === '' || $image === null) {
                continue;
            }
            $items[] = new AdSlot(
                $this->normalizeUrl((string) ($row['url'] ?? '')),
                $label,
                $image
            );
        }

        return $items;
    }

    /**
     * @param list<mixed> $rows
     * @return list<CarouselSlide>
     */
    private function carousel(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = sanitize_text_field((string) ($row['title'] ?? ''));
            $image = $this->attachmentImage((int) ($row['image'] ?? 0), $title);
            if ($title === '' || $image === null) {
                continue;
            }
            $items[] = new CarouselSlide(
                $title,
                $this->normalizeUrl((string) ($row['url'] ?? '')),
                $image
            );
        }

        return $items;
    }

    /**
     * Resolves a media-library attachment to the contract image; rows
     * without a usable image are drops, not broken banners.
     */
    private function attachmentImage(int $attachmentId, string $alt): ?Image
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($attachmentId, 'full');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }

        $width = (int) $src[1];
        $height = (int) $src[2];

        return new Image($src[0], $alt, $width > 0 ? $width : null, $height > 0 ? $height : null);
    }

    /**
     * Entries are front-end paths or absolute URLs; anything pointing at
     * this site (or carrying no host at all) collapses to a bare
     * front-end path, the rest passes through as-is.
     */
    private function normalizeUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '/';
        }
        if (str_starts_with($raw, '/')) {
            return $raw;
        }

        $host = (string) wp_parse_url($raw, PHP_URL_HOST);
        $homeHost = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $homeHost) === 0) {
            $path = (string) wp_parse_url($raw, PHP_URL_PATH);
            $query = (string) wp_parse_url($raw, PHP_URL_QUERY);

            return '/' . ltrim($path, '/') . ($query !== '' ? '?' . $query : '');
        }

        return $raw;
    }
}
