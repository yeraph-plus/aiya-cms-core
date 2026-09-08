<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Api\Contract\MenuItem;

/**
 * Projects the Navigation settings rows into the menu contracts. Two
 * groups — primary and secondary — read their own repeater field and
 * share one row shape. No WP nav-menu dependency: the option rows are the
 * whole truth, the 1-based row position doubles as the contract id, and a
 * missing label drops the row.
 */
final class PrimaryMenu
{
    public const GROUP_PRIMARY = 'primary';
    public const GROUP_SECONDARY = 'secondary';

    private const FIELDS_BY_GROUP = [
        self::GROUP_PRIMARY => 'primary_items',
        self::GROUP_SECONDARY => 'secondary_items',
    ];

    /**
     * @param self::GROUP_* $key
     * @return list<MenuItem>
     */
    public function group(string $key): array
    {
        $field = self::FIELDS_BY_GROUP[$key] ?? null;
        if ($field === null) {
            return [];
        }

        $settings = (array) get_option(NavigationModule::OPTION_NAME, []);
        $items = [];

        foreach ((array) ($settings[$field] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $items[] = new MenuItem(
                count($items) + 1,
                $label,
                $this->normalizeUrl((string) ($row['url'] ?? '')),
                ($row['target'] ?? '') === 'blank' ? 'blank' : 'self',
                []
            );
        }

        return $items;
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
