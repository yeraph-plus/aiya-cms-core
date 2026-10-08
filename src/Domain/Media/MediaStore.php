<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use SplFileInfo;
use Throwable;

/**
 * The one file-operations point over the media pool: everything an
 * upload surface needs once a file exists — the built-in image pipeline
 * (scale, format, quality) with a per-call watermark switch, the store
 * fact sheet every face answers with (wire URL + content-relative key),
 * and the delete verbs. Path and URL recipes stay in MediaPaths; this
 * class is the storage seam — an external-driver iteration (S3/CDN)
 * swaps the internals of facts()/delete()/deleteTree() and every call
 * site keeps its shape.
 *
 * The watermark switch is per call by design: user-uploaded pool content
 * carries it, avatar crops deliberately never do (the crop leg is their
 * whole pipeline), and generated covers decide their own treatment.
 */
final class MediaStore
{
    /**
     * @param Closure(string, bool=): (string|false)|null $processor The
     *                                                                built-in pipeline over a landed local file; null keeps the raw
     *                                                                file (a surface that disabled processing stores as-is).
     */
    public function __construct(
        private readonly MediaPaths $paths = new MediaPaths(),
        private readonly ?Closure $processor = null,
    ) {
    }

    /**
     * Runs the pipeline over a landed local file. The watermark rides the
     * second argument; a failed run answers null and the caller decides —
     * keep the raw file (the mirror's degrade-not-drop stance) or reject
     * (the pic-bed's fail-closed stance).
     */
    public function process(string $localPath, bool $watermark = true): ?string
    {
        if (!is_file($localPath)) {
            return null;
        }
        if ($this->processor === null) {
            return $localPath;
        }

        try {
            $processed = ($this->processor)($localPath, $watermark);
        } catch (Throwable) {
            return null;
        }

        return is_string($processed) && $processed !== '' && is_file($processed) ? $processed : null;
    }

    /**
     * The store fact sheet for a landed pool file: the wire URL and the
     * content-relative key. Null when the file sits outside the pool —
     * the caller rejects rather than serving an unresolvable reference.
     *
     * @return array{url: string, path: string}|null
     */
    public function facts(string $absolutePath): ?array
    {
        $url = $this->paths->localToUrl($absolutePath);
        $key = $this->paths->relativePath($absolutePath);
        if ($url === null || $key === null) {
            return null;
        }

        return ['url' => $url, 'path' => $key];
    }

    /**
     * Deletes one pool file by any reference shape MediaPaths understands
     * (absolute path, content-relative key, or a content URL). References
     * that resolve nowhere — external URLs, traversal, missing files —
     * are silent no-ops, the purge path's standing tolerance.
     */
    public function delete(string $reference): void
    {
        $local = $this->paths->urlToLocal($reference);
        if ($local !== null) {
            wp_delete_file($local);
        }
    }

    /** Deletes a whole subtree with its empty directories (the per-user avatar namespace). */
    public function deleteTree(string $absoluteDir): void
    {
        if (!is_dir($absoluteDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of our own pool.
                @rmdir($item->getPathname());
            } else {
                wp_delete_file($item->getPathname());
            }
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of our own pool.
        @rmdir($absoluteDir);
    }
}
