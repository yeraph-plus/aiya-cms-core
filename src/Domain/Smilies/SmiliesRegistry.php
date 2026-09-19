<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Smilies;

/**
 * Directory-convention smilies registry: packs live as plain folders under
 * `wp-content/aiya_smilies/{pack}/{code}.{ext}` — the site owner drops a ready-
 * made pack (Aru, AC-tan, ...) in through the file system and this scan
 * turns file names into the `::code::` token map. Nothing touches the
 * media library and there is no admin surface; the map is consumed by the
 * renderer and projected onto the /site contract.
 *
 * The scan is memoized per request and mirrored into the object cache
 * (`aiya_core_smilies` group, ten-minute TTL). There is no invalidation
 * signal on purpose: packs are dropped through the file system and
 * directory mtimes are unreliable across the Windows bind mounts this
 * deployment reads them through (a transient fingerprint was measured
 * missing same-second file drops), so the TTL is the freshness contract —
 * a new or removed pack surfaces within ten minutes, or immediately via a
 * cache flush. On deployments without an object cache drop-in the mirror
 * lives for the request only and every request rescans, as before. A
 * missing or empty directory is simply an empty map — the whole feature
 * degrades to a no-op instead of erroring.
 */
final class SmiliesRegistry
{
    private const EXTENSIONS = ['webp', 'png', 'gif', 'jpg', 'jpeg'];

    private const CACHE_GROUP = 'aiya_core_smilies';
    private const CACHE_TTL = 600;

    /**
     * Per-request memo; the scan runs at most once per request.
     *
     * @var list<array{slug: string, items: list<array{code: string, url: string}>}>|null
     */
    private ?array $packs = null;

    public function __construct(
        private readonly ?string $directory = null,
        private readonly ?string $baseUrl = null,
    ) {
    }

    /**
     * Packs in alphabetical directory order; items keep the directory
     * listing order. When the same code appears in two packs the earlier
     * pack wins in map().
     *
     * @return list<array{slug: string, items: list<array{code: string, url: string}>}>
     */
    public function packs(): array
    {
        return $this->packs ??= $this->cachedScan();
    }

    /**
     * Flat code => url projection across every pack; first pack wins on a
     * duplicate code.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        $flat = [];
        foreach ($this->packs() as $pack) {
            foreach ($pack['items'] as $item) {
                if (!isset($flat[$item['code']])) {
                    $flat[$item['code']] = $item['url'];
                }
            }
        }

        return $flat;
    }

    /**
     * Token rules shared with the renderer's regex: no colon (the
     * delimiter) and no whitespace or markup characters (attribute and
     * JSON safety), at most 24 characters. Purely numeric codes are
     * deliberately allowed — the common packs ship files named 01.png /
     * 0000.gif, and the `::` delimiters plus the exact alternation keep
     * ordinary prose (which never writes double colons around a number)
     * out of matches.
     */
    public static function isValidCode(string $code): bool
    {
        if ($code === '' || mb_strlen($code) > 24) {
            return false;
        }

        return preg_match('/[\s:<>&"\']/', $code) !== 1;
    }

    /**
     * The object-cache mirror in front of scan(). The key folds the
     * scanned directory and the base URL — the scan bakes absolute URLs
     * into the items, so both belong to the cache identity (and fixture
     * directories in tests never cross-contaminate).
     *
     * @return list<array{slug: string, items: list<array{code: string, url: string}>}>
     */
    private function cachedScan(): array
    {
        $key = $this->cacheKey();
        /** @var list<array{slug: string, items: list<array{code: string, url: string}>}>|false $cached */
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $packs = $this->scan();
        wp_cache_set($key, $packs, self::CACHE_GROUP, self::CACHE_TTL);

        return $packs;
    }

    private function cacheKey(): string
    {
        return 'packs_' . md5($this->directory() . '|' . $this->baseUrl());
    }

    /** @return list<array{slug: string, items: list<array{code: string, url: string}>}> */
    private function scan(): array
    {
        $base = rtrim($this->directory(), '/');
        $url = rtrim($this->baseUrl(), '/');
        if (!is_dir($base)) {
            return [];
        }

        $entries = scandir($base);
        if ($entries === false) {
            return [];
        }

        $packs = [];
        foreach ($entries as $slug) {
            $path = $base . '/' . $slug;
            if ($slug === '.' || $slug === '..' || !is_dir($path)) {
                continue;
            }

            $files = scandir($path);
            if ($files === false) {
                continue;
            }

            $items = [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || !is_file($path . '/' . $file)) {
                    continue;
                }
                if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    continue;
                }
                $code = (string) pathinfo($file, PATHINFO_FILENAME);
                if (!self::isValidCode($code)) {
                    continue;
                }
                $items[] = [
                    'code' => $code,
                    // Segment-encode so CJK pack and file names survive as URLs.
                    'url' => $url . '/' . rawurlencode($slug) . '/' . rawurlencode($file),
                ];
            }

            if ($items !== []) {
                $packs[] = ['slug' => $slug, 'items' => $items];
            }
        }

        return $packs;
    }

    private function directory(): string
    {
        return $this->directory ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/aiya_smilies' : 'smilies');
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? content_url('aiya_smilies');
    }
}
