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
     * outside wp-content, missing files) resolves to null.
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
            if ($path === '') {
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

        if (str_starts_with($normalized, '/')) {
            return $this->existingInsideContent($normalized);
        }

        return $this->existingInsideContent($this->contentDir() . '/' . ltrim($normalized, './'));
    }

    /** Absolute path to content URL, or null when outside the content dir. */
    public function localToUrl(string $absolutePath): ?string
    {
        $relative = $this->relativePath($absolutePath);

        return $relative === null ? null : $this->contentUrl() . '/' . $relative;
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

        return $relative !== '' ? $relative : null;
    }

    /** Directory for generated thumbnails, created on demand. */
    public function thumbnailDir(int $width, int $height): string
    {
        return $this->ensureDir($this->contentDir() . '/thumbnail/' . $width . 'x' . $height);
    }

    /** Month-sharded directory for generated covers, created on demand. */
    public function coverDir(): string
    {
        return $this->ensureDir($this->contentDir() . '/thumbnail/cover/' . wp_date('Y/m'));
    }

    /** Month-sharded pic-bed pool, created on demand. */
    public function picBedDir(): string
    {
        return $this->ensureDir($this->contentDir() . '/upload-pics/' . wp_date('Y/m'));
    }

    /** Root of the pic-bed pool, without creating it. */
    public function picBedRoot(): string
    {
        return $this->contentDir() . '/upload-pics';
    }

    private function ensureDir(string $dir): string
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    private function existingInsideContent(string $absolutePath): ?string
    {
        if (!str_starts_with($absolutePath, $this->contentDir() . '/') || !is_file($absolutePath)) {
            return null;
        }

        return $absolutePath;
    }
}
