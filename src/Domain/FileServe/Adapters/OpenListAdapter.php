<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe\Adapters;

use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\Failure;
use Aiya\Infra\OpenList\Error;
use Aiya\Infra\OpenList\Gateway;
use Closure;

/**
 * OpenList as a data group, in the two forms a post actually wants: one
 * directory listed, or one search's hits. They are separate adapters — not one
 * adapter with a mode field — so a group's own keys are exactly the fields its
 * call takes, and the editor picks the shape instead of configuring it.
 *
 * This is the only place the site's vocabulary meets the package's: the
 * gateway answers rows as plain arrays (and failures as package Errors), and
 * everything is mapped here.
 */
final class OpenListAdapter implements Adapter
{
    public const LIST_ID = 'openlist_list';
    public const SEARCH_ID = 'openlist_search';

    /**
     * @param Closure(): Gateway $gatewayFactory
     * @param Closure(): array<string, mixed>|null $siteConfig the site-level
     *        settings the rows depend on (server, link mode, credentials),
     *        folded into the listing's cache key; null when the caller has none
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $gatewayFactory,
        private readonly ?Closure $siteConfig = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->isSearch()
            ? __('OpenList search', 'aiya-core')
            : __('OpenList directory', 'aiya-core');
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        if ($this->isSearch()) {
            return [
                [
                    'id' => 'keywords',
                    'type' => 'text',
                    'label' => __('Keywords', 'aiya-core'),
                    'description' => __('What this list searches for.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'parent',
                    'type' => 'text',
                    'label' => __('Search root', 'aiya-core'),
                    'description' => __('The directory the search starts from; empty means the service root.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'password',
                    'type' => 'text',
                    'label' => __('Path password', 'aiya-core'),
                    'description' => __('The password of that directory on the OpenList side, if any.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'per_page',
                    'type' => 'number',
                    'label' => __('Per page', 'aiya-core'),
                    'description' => __('0 lists everything.', 'aiya-core'),
                    'default' => 0,
                    'min' => 0,
                    'step' => 1,
                ],
            ];
        }

        return [
            [
                'id' => 'path',
                'type' => 'text',
                'label' => __('Path', 'aiya-core'),
                'description' => __('The directory whose files this list shows; leave it empty to keep this list off.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'password',
                'type' => 'text',
                'label' => __('Path password', 'aiya-core'),
                'description' => __('The password of that directory on the OpenList side, if any. Kept readable: every request re-sends it.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'per_page',
                'type' => 'number',
                'label' => __('Per page', 'aiya-core'),
                'description' => __('0 lists everything.', 'aiya-core'),
                'default' => 0,
                'min' => 0,
                'step' => 1,
            ],
        ];
    }

    /** @param array<string, mixed> $config */
    public function configured(array $config): bool
    {
        $required = $this->isSearch() ? 'keywords' : 'path';

        return trim((string) ($config[$required] ?? '')) !== '';
    }

    public function siteConfig(): array
    {
        $resolve = $this->siteConfig;
        $config = $resolve === null ? [] : $resolve();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<Entry>|Failure
     */
    public function entries(array $config): array|Failure
    {
        $gateway = ($this->gatewayFactory)();
        if (!$gateway instanceof Gateway) {
            return new Failure(Failure::UNREACHABLE, 'The OpenList gateway is unavailable.');
        }

        $rows = $this->isSearch() ? $gateway->search($config) : $gateway->list($config);
        if ($rows instanceof Error) {
            return self::failure($rows);
        }

        $entries = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $path = (string) ($row['path'] ?? '');
            $url = $row['url'] ?? null;

            $entries[] = new Entry(
                name: $name,
                kind: (string) ($row['kind'] ?? Entry::FILE) === Entry::DIR ? Entry::DIR : Entry::FILE,
                size: max(0, (int) ($row['size'] ?? 0)),
                modified: isset($row['modified']) ? (int) $row['modified'] : null,
                path: $path !== '' ? $path : null,
                url: is_string($url) && $url !== '' ? $url : null,
            );
        }

        return $entries;
    }

    private function isSearch(): bool
    {
        return $this->id === self::SEARCH_ID;
    }

    /** The package's own taxonomy, read into the domain's. */
    private static function failure(Error $error): Failure
    {
        $code = match ($error->code) {
            Error::UNAUTHORIZED => Failure::UNAUTHORIZED,
            Error::DENIED => Failure::DENIED,
            Error::NOT_FOUND => Failure::NOT_FOUND,
            Error::INVALID => Failure::INVALID,
            default => Failure::UNREACHABLE,
        };

        return new Failure($code, $error->message, $error->status);
    }
}
