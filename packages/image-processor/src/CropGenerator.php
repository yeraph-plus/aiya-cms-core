<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use Throwable;

/**
 * Plain center-crop generator: scales the source until it covers the target
 * box and crops from the center. Unlike ThumbnailGenerator there is no blur
 * compositing for extreme aspect ratios — avatars and similar fixed-size
 * assets want a straight crop, and the caller overwrites the destination
 * instead of reusing an existing file (cache busting is the caller's job).
 */
final class CropGenerator extends ImagineAware
{
    /**
     * @param array<string, int|bool> $saveOptions Imagine save options for
     *                                               the destination format.
     */
    public function generate(string $sourcePath, string $destPath, int $width, int $height, array $saveOptions = []): ?string
    {
        if ($sourcePath === '' || $destPath === '' || $width <= 0 || $height <= 0 || !is_file($sourcePath)) {
            return null;
        }

        if (!is_dir(dirname($destPath)) && !mkdir(dirname($destPath), 0755, true) && !is_dir(dirname($destPath))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WordPress-free package.
            return null;
        }

        try {
            $image = $this->coverCropCenter($this->prepareSource($this->imagine()->open($sourcePath), $width, $height), $width, $height);
            $image->save($destPath, SaveOptions::withDefaults(pathinfo($destPath, PATHINFO_EXTENSION), $saveOptions));
        } catch (Throwable) {
            return null;
        }

        return is_file($destPath) ? $destPath : null;
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
