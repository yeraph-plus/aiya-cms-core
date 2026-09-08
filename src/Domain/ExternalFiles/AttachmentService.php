<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\ExternalFiles;

use Aiya\Core\Domain\Sponsorship\MembershipService;
use WP_Error;

/**
 * Surfaces the OpenList files behind a resource's `oplist_client` box as
 * the attachment list (2026-09-09 gate redesign): the listing metadata is
 * public for everyone including guests, while download links are trimmed
 * per viewer — logged-in users see them when the box's sponsor gate is
 * off, only sponsors (admins via the membership bypass) when it is on.
 * The legacy per-view trigger counter has no place in this model.
 *
 * Everything the platform returns is realtime, so the legacy
 * ready/unavailable concern collapses into "listed means ready".
 */
final class AttachmentService
{
    private const METHOD_CONFIGURED = ['list', 'get', 'dirs', 'search'];

    /** @var callable():OpenListClient */
    private $clientFactory;

    /** @param callable():OpenListClient $clientFactory */
    public function __construct(callable $clientFactory, private MembershipService $membership)
    {
        $this->clientFactory = $clientFactory;
    }

    /**
     * The gate matrix as a pure decision: sponsor-only boxes reveal links
     * to sponsors only, otherwise any signed-in viewer.
     */
    public static function canSeeLinks(bool $sponsorOnly, bool $isSponsor, bool $loggedIn): bool
    {
        return $sponsorOnly ? $isSponsor : $loggedIn;
    }

    /**
     * The attachment list of one published resource.
     *
     * @return array{gated:bool, canSeeLinks:bool, items:list<array{name:string,size:int,type:string,modified:string|null,url:string|null,ready:bool}>}|WP_Error
     */
    public function forResource(int $resourceId, int $viewerId): array|WP_Error
    {
        $post = get_post($resourceId);
        if ($post === null || $post->post_type !== 'resource' || $post->post_status !== 'publish') {
            return new WP_Error('aiya_not_found', __('Resource not found.', 'aiya-core'), ['status' => 404]);
        }

        $config = get_post_meta($resourceId, 'aya_box_oplist_client', true);
        $config = is_array($config) ? $config : [];
        $method = (string) ($config['fs_method'] ?? 'off');
        $sponsorOnly = filter_var((string) ($config['sponsor_can'] ?? ''), FILTER_VALIDATE_BOOLEAN);

        $loggedIn = $viewerId > 0;
        $canSeeLinks = self::canSeeLinks($sponsorOnly, $loggedIn && $this->membership->isSponsor($viewerId), $loggedIn);
        $result = ['gated' => $sponsorOnly, 'canSeeLinks' => $canSeeLinks, 'items' => []];

        if (!in_array($method, self::METHOD_CONFIGURED, true)) {
            return $result; // box present but no surfacing mode configured
        }

        $settings = OplistSettings::read();
        $cacheMinutes = $settings['listCacheMinutes'];
        $forceRefresh = filter_var((string) ($config['refresh'] ?? ''), FILTER_VALIDATE_BOOLEAN);
        // The cache key folds every config field that shapes the listing, so
        // editing the box naturally starts a new cache generation without an
        // invalidation hook. Links are stored in full and trimmed per viewer
        // afterwards — one cached copy serves every permission level.
        $configKey = md5((string) json_encode([
            $method,
            $config['path'] ?? '',
            $config['password'] ?? '',
            $config['parent'] ?? '',
            $config['keywords'] ?? '',
            $config['per_page'] ?? 0,
        ]));
        $cacheKey = "list_{$resourceId}_{$configKey}";

        $items = null;
        if (!$forceRefresh && $cacheMinutes > 0) {
            /** @var list<array{name:string,size:int,type:string,modified:string|null,url:string|null,ready:bool}>|false $cached */
            $cached = wp_cache_get($cacheKey, OplistSettings::CACHE_GROUP);
            $items = is_array($cached) ? $cached : null;
        }

        if ($items === null) {
            $client = ($this->clientFactory)();
            $entries = $this->fetch($client, $resourceId, $method, $config);
            if ($entries === null) {
                return $result; // fetch failed — an empty list beats an error page
            }

            $items = $this->assemble($client, $settings, $config, $entries, true);
            if ($cacheMinutes > 0 && $items !== []) {
                wp_cache_set($cacheKey, $items, OplistSettings::CACHE_GROUP, $cacheMinutes * MINUTE_IN_SECONDS);
            }
        }

        if (!$canSeeLinks) {
            foreach ($items as &$item) {
                $item['url'] = null;
            }
            unset($item);
        }

        $result['items'] = $items;

        return $result;
    }

    /**
     * Maps raw platform entries onto contract items, building download
     * links when $withLinks asks for them (the cached copy is always built
     * with links; the per-viewer trim happens after a cache read).
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $config
     * @return list<array{name:string,size:int,type:string,modified:string|null,url:string|null,ready:bool}>
     */
    private function assemble(OpenListClient $client, array $settings, array $config, array $entries, bool $withLinks): array
    {
        $icons = (bool) $settings['icons'];
        $items = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $items[] = [
                'name' => $name,
                'size' => (int) ($entry['size'] ?? 0),
                'type' => FileIcons::forEntry($name, (bool) ($entry['is_dir'] ?? false), $icons),
                'modified' => $this->iso($entry['modified'] ?? null),
                'url' => $withLinks ? $this->link($client, $settings, $config, $entry) : null,
                'ready' => true,
            ];
        }

        return $items;
    }

    /**
     * Fetches the raw entries per the configured mode, mirroring the legacy
     * proxy's parameter mapping (search entries resolve their details one
     * by one; folders are dropped in every mode).
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>|null null when the upstream call failed
     */
    private function fetch(OpenListClient $client, int $resourceId, string $method, array $config): ?array
    {
        $path = '/' . trim((string) ($config['path'] ?? ''), '/');
        $password = (string) ($config['password'] ?? '');
        $perPage = max(0, (int) ($config['per_page'] ?? 0));
        $refresh = filter_var((string) ($config['refresh'] ?? ''), FILTER_VALIDATE_BOOLEAN);

        $response = match ($method) {
            'list' => $client->fs('list', ['path' => $path, 'password' => $password, 'page' => 1, 'per_page' => $perPage, 'refresh' => $refresh]),
            'get' => $client->fs('get', ['path' => $path, 'password' => $password, 'page' => 1, 'per_page' => $perPage, 'refresh' => $refresh]),
            'dirs' => $client->fs('dirs', ['path' => $path, 'password' => $password, 'force_root' => false]),
            'search' => $client->fs('search', [
                'parent' => '/' . trim((string) ($config['parent'] ?? ''), '/'),
                'keywords' => (string) ($config['keywords'] ?? ''),
                'scope' => 2,
                'page' => 1,
                'per_page' => $perPage,
                'password' => $password,
            ]),
            default => new WP_Error('aiya_oplist_error', __('Unsupported mode.', 'aiya-core')),
        };

        if (is_wp_error($response)) {
            if ($response->get_error_code() !== 'aiya_oplist_not_found') {
                // A missing path means "no attachments yet"; anything else is
                // worth surfacing in the logs for the admin.
                do_action('aiya_core_oplist_error', $resourceId, $response);
            }

            return null;
        }

        // Single-file mode returns the object itself; list/search wrap rows
        // in `content`.
        if ($method === 'get') {
            if ((bool) ($response['is_dir'] ?? false)) {
                return [];
            }

            return [$response];
        }

        $entries = [];
        foreach ((array) ($response['content'] ?? []) as $entry) {
            if (!is_array($entry) || ((bool) ($entry['is_dir'] ?? false))) {
                continue;
            }

            $entries[] = $entry;
        }

        // Search rows carry only parent+name — resolve details like the
        // legacy proxy did, dropping entries that vanished in the meantime.
        if ($method === 'search') {
            $resolved = [];
            foreach ($entries as $entry) {
                $detail = $client->fs('get', ['path' => '/' . trim((string) ($entry['parent'] ?? ''), '/') . '/' . (string) ($entry['name'] ?? ''), 'password' => $password]);
                if (!is_wp_error($detail) && !($detail['is_dir'] ?? false)) {
                    $resolved[] = $detail;
                }
            }

            return $resolved;
        }

        return $entries;
    }

    /**
     * Builds the download link per the site's link mode: direct (/d/),
     * proxied (/p/), plain, or the platform raw_url.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $config
     */
    private function link(OpenListClient $client, array $settings, array $config, array $entry): string
    {
        $mode = (string) ($settings['linkType'] ?? 'f');
        if ($mode === 'r') {
            return (string) ($entry['raw_url'] ?? '');
        }

        $path = '/' . trim((string) ($config['path'] ?? ''), '/');
        $name = (string) ($entry['name'] ?? '');
        $sign = (string) ($entry['sign'] ?? '');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path . '/' . $name)));

        $prefix = match ($mode) {
            'd' => '/d',
            'p' => '/p',
            default => '',
        };

        return rtrim((string) $settings['server'], '/') . $prefix . $encoded . ($sign !== '' ? '?sign=' . $sign : '');
    }

    private function iso(mixed $timestamp): ?string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : null;
    }
}
