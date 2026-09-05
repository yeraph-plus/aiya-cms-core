<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

use Aiya\Infra\ImageProcessor\SaveOptions;
use Aiya\Infra\ImageProcessor\ThumbnailGenerator;
use Closure;
use Imagine\Image\ImagineInterface;

/**
 * Read-side thumbnail service for the headless API layer: generates (or
 * reuses) a fixed-size derivative for a local source image and hands back
 * local paths or content URLs.
 *
 * This service is read-only from the content store's perspective: it never
 * touches post meta. Writing _aya_thumb is the cover pipeline's job; the
 * consult order (featured image → _aya_thumb → first content image) is the
 * API layer's policy, not this service's.
 *
 * The cache key includes the quality setting, so a saved quality change
 * regenerates derivatives instead of serving stale files (a defect of the
 * legacy cache key).
 */
final class ThumbnailService
{
    private ThumbnailGenerator|null $generator = null;

    /** @param Closure(): array{format: string, quality: int} $savePolicy */
    public function __construct(
        private readonly ImagineInterface|Closure $imagine,
        private readonly MediaPaths $paths,
        private readonly Closure $savePolicy
    ) {
    }

    /**
     * Generates (or reuses) the thumbnail for a local source image.
     *
     * @return string|null Local absolute path of the derivative, or null when
     *                     the source is missing or generation failed.
     */
    public function generate(string $sourceLocalPath, int $width, int $height): ?string
    {
        $width = absint($width);
        $height = absint($height);
        if ($width <= 0 || $height <= 0 || $sourceLocalPath === '' || !is_file($sourceLocalPath)) {
            return null;
        }

        $policy = ($this->savePolicy)();
        $format = $this->targetFormat((string) $policy['format'], $sourceLocalPath);
        if ($format === null) {
            return null;
        }

        $dest = $this->paths->thumbnailDir($width, $height) . '/' . $this->cacheKey($sourceLocalPath, $width, $height, $format, (int) $policy['quality']) . '.' . $format;
        if (is_file($dest)) {
            return $dest;
        }

        $result = $this->generator()->generate($sourceLocalPath, $dest, $width, $height, SaveOptions::for($format, (int) $policy['quality']));

        return is_string($result) ? $result : null;
    }

    /** Same as generate() but resolves the result to a content URL. */
    public function urlFor(string $sourceLocalPath, int $width, int $height): ?string
    {
        $local = $this->generate($sourceLocalPath, $width, $height);

        return $local === null ? null : $this->paths->localToUrl($local);
    }

    /**
     * Generates a thumbnail for an arbitrary URL/path reference and returns
     * its content URL. References that do not resolve to a file under the
     * content directory resolve to null — the caller decides how to
     * represent them.
     */
    public function urlForReference(string $urlOrPath, int $width, int $height): ?string
    {
        $local = $this->paths->urlToLocal($urlOrPath);

        return $local === null ? null : $this->urlFor($local, $width, $height);
    }

    private function generator(): ThumbnailGenerator
    {
        return $this->generator ??= new ThumbnailGenerator($this->imagine);
    }

    private function cacheKey(string $source, int $width, int $height, string $format, int $quality): string
    {
        return substr(hash('sha1', $source . '|' . $width . '|' . $height . '|' . $format . '|' . $quality), 0, 16);
    }

    private function targetFormat(string $setting, string $source): ?string
    {
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $format = strtolower(trim($setting));
        if ($format === '' || $format === 'off' || !in_array($format, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], true)) {
            return $extension !== '' ? $extension : null;
        }

        return $format;
    }
}
