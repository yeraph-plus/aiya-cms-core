<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Throwable;

/**
 * Upload pipeline: optional max-width scaling, watermark application and
 * format conversion, saved in place next to the source file. When the
 * extension changes the source file is removed, mirroring the "convert on
 * upload" semantics.
 */
final class UploadApplier extends ImagineAware
{
    /**
     * @param string        $sourcePath  Local absolute path of the uploaded file.
     * @param WatermarkSpec $spec        Watermark model; degrades to no-op when off.
     * @param string        $targetFormat Lowercase target extension (jpg/webp/avif/...).
     * @param int           $maxWidth    Scale down proportionally above this width; 0 disables.
     * @param array<string, int|bool> $saveOptions Imagine save options for the target format.
     * @return string|false The processed file path, or false on failure.
     */
    public function process(
        string $sourcePath,
        WatermarkSpec $spec,
        string $targetFormat,
        int $maxWidth = 0,
        array $saveOptions = []
    ): string|false {
        // WordPress-free package: direct file calls and silence are
        // intentional here; a false return is handled below.
        if ($sourcePath === '' || !is_file($sourcePath) || @exif_imagetype($sourcePath) === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return false;
        }

        $sourceExt = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $targetFormat = strtolower(trim($targetFormat));
        if ($targetFormat === '') {
            $targetFormat = $sourceExt;
        }

        // Only a real extension change may delete the source file.
        $needConvert = strcasecmp($targetFormat, $sourceExt) !== 0;
        $destPath = str_replace('\\', '/', dirname($sourcePath)) . '/' . pathinfo($sourcePath, PATHINFO_FILENAME) . '.' . $targetFormat;

        try {
            $image = $this->imagine()->open($sourcePath);
            if ($maxWidth > 0) {
                $image = $this->scaleToMaxWidth($image, $maxWidth);
            }
            $image = $this->applyWatermark($image, $spec);
            $image->save($destPath, SaveOptions::withDefaults($targetFormat, $saveOptions));

            if ($needConvert && is_file($destPath)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress-free package: direct FS ops intentional.
                @unlink($sourcePath);
            }
        } catch (Throwable) {
            return false;
        }

        return is_file($destPath) ? $destPath : false;
    }

    public function applyWatermark(ImageInterface $image, WatermarkSpec $spec): ImageInterface
    {
        $type = $spec->type();
        if ($type === 'off') {
            return $image;
        }

        $mark = $type === 'image' ? $this->openImage($spec->imageFile) : $this->createTextMark($spec);
        if ($mark === null) {
            return $image;
        }

        $baseSize = $image->getSize();
        $markSize = $mark->getSize();
        $point = $this->position(
            $spec->position,
            $baseSize->getWidth(),
            $baseSize->getHeight(),
            $markSize->getWidth(),
            $markSize->getHeight(),
            $spec->offsetX,
            $spec->offsetY
        );
        if ($point === null) {
            return $image;
        }

        $image->paste($mark, $point);

        return $image;
    }

    private function openImage(string $file): ?ImageInterface
    {
        if ($file === '' || !is_file($file)) {
            return null;
        }

        try {
            return $this->imagine()->open($file);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Renders the text watermark on its own transparent canvas: a shadow
     * layer offset by 2px under the main glyph layer.
     */
    private function createTextMark(WatermarkSpec $spec): ?ImageInterface
    {
        try {
            $palette = new RGB();
            $alpha = min(100, max(0, $spec->opacity));
            $shadowAlpha = min(100, $alpha + 20);

            $font = $this->imagine()->font($spec->fontFile, $spec->fontSize, $palette->color('#ffffff', $alpha));
            $box = $font->box($spec->text);

            $padding = 2;
            $shiftX = -2;
            $shiftY = -2;
            $canvasW = $box->getWidth() + $padding * 2 + abs($shiftX);
            $canvasH = $box->getHeight() + $padding * 2 + abs($shiftY);
            $canvas = $this->imagine()->create(new Box($canvasW, $canvasH), $palette->color([0, 0, 0], 0));

            $baseX = $padding + max(0, -$shiftX);
            $baseY = $padding + max(0, -$shiftY);
            // imagine 1.5 types DrawerInterface::text() as AbstractFont while
            // ImagineInterface::font() returns FontInterface; every concrete
            // driver returns an AbstractFont, so the call is safe.
            $canvas->draw()->text($spec->text, $this->imagine()->font($spec->fontFile, $spec->fontSize, $palette->color('#333333', $shadowAlpha)), new Point($baseX, $baseY)); // @phpstan-ignore argument.type
            $canvas->draw()->text($spec->text, $font, new Point($baseX + $shiftX, $baseY + $shiftY)); // @phpstan-ignore argument.type

            return $canvas;
        } catch (Throwable) {
            return null;
        }
    }

    private function position(
        string $position,
        int $baseW,
        int $baseH,
        int $markW,
        int $markH,
        int $offsetX,
        int $offsetY
    ): ?Point {
        $sizeX = (int) floor(($baseW - $markW) / 2);
        $sizeY = (int) floor(($baseH - $markH) / 2);

        return match ($position) {
            'center-center' => new Point($sizeX, $sizeY),
            'center-left' => new Point((int) floor($sizeX / 2), $sizeY),
            'center-right' => new Point($sizeX + (int) floor($sizeX / 2), $sizeY),
            'center-top' => new Point($sizeX, (int) floor($sizeY / 2)),
            'center-bottom' => new Point($sizeX, $sizeY + (int) floor($sizeY / 2)),
            'top-left' => new Point($offsetX, $offsetY),
            'top-center' => new Point($sizeX, $offsetY),
            'top-right' => new Point($sizeX * 2 - $offsetX, $offsetY),
            'bottom-left' => new Point($offsetX, $sizeY * 2 - $offsetY),
            'bottom-center' => new Point($sizeX, $sizeY * 2 - $offsetY),
            'bottom-right' => new Point($sizeX * 2 - $offsetX, $sizeY * 2 - $offsetY),
            default => null,
        };
    }

    private function scaleToMaxWidth(ImageInterface $image, int $maxWidth): ImageInterface
    {
        $size = $image->getSize();
        $originW = $size->getWidth();
        $originH = $size->getHeight();
        if ($maxWidth <= 0 || $originW <= 0 || $originH <= 0 || $originW <= $maxWidth) {
            return $image;
        }

        return $image->resize(new Box($maxWidth, max(1, (int) floor(($originH * $maxWidth) / $originW))));
    }
}
