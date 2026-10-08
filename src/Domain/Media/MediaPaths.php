<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

/**
 * WP-touching path/URL resolution for the media domain: content-dir rooted
 * file locations, URL translation and the on-disk layout the media stack
 * writes to (thumbnails, generated covers, the pic-bed pool).
 *
 * Everything is normalized to forward slashes internally so Windows
 * unit-test environments and the Linux runtime behave identically.
 */
final class MediaPaths
{
    public function contentDir(): string
    {
        return str_replace('\\', '/', (string) untrailingslashit((string) WP_CONTENT_DIR));
    }

    public function contentUrl(): string
    {
        return rtrim((string) untrailingslashit(content_url()), '/');
    }

    /**
     * Resolves a content URL, an absolute filesystem path or a
     * content-relative path to an existing local file inside the content
     * directory. Everything else (external URLs, query strings, paths
     * outside wp-content, traversal segments, missing files) resolves to
     * null. Traversal is rejected before resolution, not after: realpath
     * collapses `a/../..` segments first, and a prefix check on the
     * collapsed result would let `/content/a/../../x` through.
     */
    public function urlToLocal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $value);

        if (preg_match('#^https?://#i', $normalized) === 1) {
            $path = ltrim((string) wp_parse_url($normalized, PHP_URL_PATH), '/');
            if ($path === '' || in_array('..', explode('/', $path), true)) {
                return null;
            }

            $base = trim((string) wp_parse_url($this->contentUrl(), PHP_URL_PATH), '/');
            if ($base !== '') {
                if (!str_starts_with($path, $base . '/')) {
                    return null;
                }
                $path = substr($path, strlen($base) + 1);
            }

            return $this->existingInsideContent($this->contentDir() . '/' . $path);
        }

        if (in_array('..', explode('/', trim($normalized, '/')), true)) {
            return null;
        }

        if (str_starts_with($normalized, '/')) {
            return $this->existingInsideContent($normalized);
        }

        return $this->existingInsideContent($this->contentDir() . '/' . ltrim($normalized, './'));
    }

    /** Absolute path to content URL, or null when outside the content dir. */
    public function localToUrl(string $absolutePath): ?string
    {
        $relative = $this->relativePath($absolutePath);

        return $relative === null ? null : $this->keyToUrl($relative);
    }

    /**
     * The one URL derivation from a content-relative key — the storage
     * seam an external-driver iteration replaces (a CDN/S3 driver swaps
     * this base; every consumer keeps calling the same method).
     */
    public function keyToUrl(string $relativeKey): string
    {
        return $this->contentUrl() . '/' . ltrim(str_replace('\\', '/', $relativeKey), './');
    }

    /** Content-relative, forward-slashed path, or null when outside. */
    public function relativePath(string $absolutePath): ?string
    {
        $file = str_replace('\\', '/', $absolutePath);
        $dir = $this->contentDir();
        if (!str_starts_with($file, $dir . '/')) {
            return null;
        }

        $relative = substr($file, strlen($dir) + 1);
        if ($relative === '' || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        return $relative;
    }

    /** Directory for generated thumbnails, created on demand. */
    public function thumbnailDir(int $width, int $height): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_thumbnail/' . $width . 'x' . $height);
    }

    /**
     * The file-avatar recipe, single author of all three shapes: the
     * directory (writes), the storage key (the `basic_user_avatar` meta
     * protocol), and the versioned URL (the wire). Avatars deliberately
     * never meet the watermark: the crop leg is their whole pipeline.
     */
    public function avatarDir(int $userId): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_thumbnail/avatars/' . $userId);
    }

    /** The content-relative storage key of one avatar size file. */
    public function avatarKey(int $userId, int $size): string
    {
        return 'aiya_thumbnail/avatars/' . $userId . '/' . $size . '.jpg';
    }

    /** The versioned wire URL of one avatar size file. */
    public function avatarUrl(int $userId, int $size, int $version = 0): string
    {
        $url = $this->keyToUrl($this->avatarKey($userId, $size));

        return $version > 0 ? $url . '?v=' . $version : $url;
    }

    /**
     * The generated-cover filename recipe: timestamp + random suffix.
     * Single author for the recipe AND its recognition pattern — a
     * change here moves both the writers and the managed-file check
     * together, instead of silently breaking the cover swap's delete.
     */
    public function coverFilename(string $format): string
    {
        return wp_date('YmdHis') . '_' . wp_rand(1000, 9999) . '.' . $format;
    }

    /** Whether a local path is a managed generated cover (the recipe above). */
    public function isManagedCoverFile(string $localPath): bool
    {
        return preg_match('/\/\d{14}_\d{4}\.(?:jpg|webp|avif)$/', $localPath) === 1;
    }

    /**
     * The stable cover tree root, without date shard and without creating
     * it: the prefix every "is this a managed cover file?" check matches
     * against — it must not carry the current month, or stale files from
     * earlier months would survive their replacement forever.
     */
    public function coverTreeDir(): string
    {
        return $this->contentDir() . '/aiya_thumbnail/cover';
    }

    /**
     * Editor-generated titled covers (CoverService). A manual cover wins
     * over the automatic card pipeline: the save/cron card path skips
     * posts whose `_thumb` points into this subtree.
     */
    public function coverManualDir(): string
    {
        return $this->ensureDir($this->coverManualTree() . '/' . wp_date('Y/m'));
    }

    /** Whether a stored `_thumb` value (key or URL) names a manual cover — the recognition rides the same tree the writer builds into. */
    public function isManualCover(string $value): bool
    {
        $normalized = str_replace('\\', '/', trim($value));
        if (preg_match('#^https?://#i', $normalized) === 1) {
            $base = trim((string) wp_parse_url($this->contentUrl(), PHP_URL_PATH), '/');
            $path = ltrim((string) wp_parse_url($normalized, PHP_URL_PATH), '/');
            if ($base !== '' && str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base) + 1);
            }
            $normalized = $path;
        }

        return str_contains('/' . ltrim($normalized, '/'), '/' . $this->coverManualTreeFragment());
    }

    private function coverManualTree(): string
    {
        return $this->contentDir() . '/aiya_thumbnail/cover/manual';
    }

    private function coverManualTreeFragment(): string
    {
        return $this->relativePath($this->coverManualTree()) . '/';
    }

    /** Automatic card pipeline output (save hook / cron), no title. */
    public function coverAutoDir(): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_thumbnail/cover/auto/' . wp_date('Y/m'));
    }

    /** Month-sharded pic-bed pool, created on demand. */
    public function picBedDir(): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_upload_pics/' . wp_date('Y/m'));
    }

    /** Root of the pic-bed pool, without creating it. */
    public function picBedRoot(): string
    {
        return $this->contentDir() . '/aiya_upload_pics';
    }

    /**
     * Per-user namespace under the pic-bed pool for front-end community
     * uploads, kept separate from the operator-curated root so moderation
     * and cleanup can target one author's files.
     */
    public function userPicBedDir(int $userId): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_upload_pics/u/' . $userId . '/' . wp_date('Y/m'));
    }

    /**
     * Month-sharded pool subtree for the Telegram channel mirror's
     * transferred photos, kept separate from the operator-curated root so
     * mirror cleanup can target its own files.
     */
    public function telegramDir(): string
    {
        return $this->ensureDir($this->contentDir() . '/aiya_upload_pics/telegram/' . wp_date('Y/m'));
    }

    /**
     * The operator-shipped smilies tree: one directory recipe, one URL
     * recipe — the same pair every other pool subtree answers through.
     */
    public function smiliesDir(): string
    {
        return $this->contentDir() . '/aiya_smilies';
    }

    public function smiliesUrl(): string
    {
        return $this->keyToUrl('aiya_smilies');
    }

    private function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                error_log('[aiya-core] Could not create media directory: ' . $dir);
            }
        }

        return $dir;
    }

    private function existingInsideContent(string $absolutePath): ?string
    {
        // realpath collapses `..` segments and symlink escapes before the
        // prefix check; a naive starts-with would let /wp-content/../x pass.
        $contentDir = realpath($this->contentDir());
        $real = realpath($absolutePath);
        if ($contentDir === false || $real === false || !str_starts_with($real, $contentDir . '/') || !is_file($real)) {
            return null;
        }

        return $real;
    }
}
