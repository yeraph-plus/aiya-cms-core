<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Throwable;

/**
 * Thumbnail generator: cover-crop-center resize, with a two-layer render
 * (blurred cropped background + white wash + contain-fitted foreground)
 * when the source aspect ratio diverges too far from the target.
 *
 * Inputs and outputs are local absolute paths; caching decisions and
 * directory planning belong to the caller.
 */
final class ThumbnailGenerator extends ImagineAware
{
    /** Ratio gap (log space) above which the blur-composite render kicks in. */
    private const BLUR_RATIO_GAP = 0.35;

    /**
     * Generates (or reuses) a thumbnail for a local source image.
     *
     * @param array<string, int|bool> $saveOptions Imagine save options.
     */
    public function generate(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        array $saveOptions = []
    ): ?string {
        if ($sourcePath === '' || $destPath === '' || $width <= 0 || $height <= 0 || !is_file($sourcePath)) {
            return null;
        }

        // Direct filesystem ops are intentional: this package is
        // WordPress-free (no WP_Filesystem available in its context).
        if (!is_dir(dirname($destPath)) && !mkdir(dirname($destPath), 0755, true) && !is_dir(dirname($destPath))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            return null;
        }

        // An existing dest file is reused: the caller owns the cache key, so
        // a file being there already means the parameters match.
        if (is_file($destPath)) {
            return $destPath;
        }

        try {
            $image = $this->imagine()->open($sourcePath);
            $image = $this->renderFrame($image, $width, $height);
            $image->save($destPath, SaveOptions::withDefaults(pathinfo($destPath, PATHINFO_EXTENSION), $saveOptions));
        } catch (Throwable) {
            // A mid-write failure leaves a truncated file that the reuse
            // path above would serve forever — delete the partial output.
            if (is_file($destPath)) {
                @unlink($destPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- WordPress-free package
            }

            return null;
        }

        return is_file($destPath) ? $destPath : null;
    }

    private function renderFrame(ImageInterface $image, int $width, int $height): ImageInterface
    {
        if (!$this->shouldUseBlurComposite($image, $width, $height)) {
            return $this->coverCropCenter($image, $width, $height);
        }

        return $this->blurComposite($image, $width, $height);
    }

    private function shouldUseBlurComposite(ImageInterface $image, int $width, int $height): bool
    {
        $size = $image->getSize();
        $originW = $size->getWidth();
        $originH = $size->getHeight();
        if ($originW <= 0 || $originH <= 0 || $width <= 0 || $height <= 0) {
            return false;
        }

        return abs(log(($originW / $originH) / ($width / $height))) >= self::BLUR_RATIO_GAP;
    }

    private function blurComposite(ImageInterface $image, int $width, int $height): ImageInterface
    {
        $background = $this->coverCropCenter($image->copy(), $width, $height);
        $background->effects()->blur(16);

        $palette = new RGB();
        $overlay = $this->imagine()->create(new Box($width, $height), $palette->color([255, 255, 255], 55));
        $background->paste($overlay, new Point(0, 0));

        $foreground = $this->containCenter($image->copy(), $width, $height);
        $fgSize = $foreground->getSize();
        $background->paste($foreground, new Point(
            (int) floor(($width - $fgSize->getWidth()) / 2),
            (int) floor(($height - $fgSize->getHeight()) / 2)
        ));

        return $background;
    }

    private function containCenter(ImageInterface $image, int $width, int $height): ImageInterface
    {
        $size = $image->getSize();
        $originW = $size->getWidth();
        $originH = $size->getHeight();
        if ($originW <= 0 || $originH <= 0) {
            return $image;
        }

        $ratio = min($width / $originW, $height / $originH);

        return $image->resize(new Box(
            max(1, (int) floor($originW * $ratio)),
            max(1, (int) floor($originH * $ratio))
        ));
    }

    private function coverCropCenter(ImageInterface $image, int $width, int $height): ImageInterface
    {
        $size = $image->getSize();
        $ratio = max($width / $size->getWidth(), $height / $size->getHeight());
        $scaled = $size->scale($ratio);

        $image->resize($scaled);

        return $image->crop(
            new Point((int) floor(($scaled->getWidth() - $width) / 2), (int) floor(($scaled->getHeight() - $height) / 2)),
            new Box($width, $height)
        );
    }
}
