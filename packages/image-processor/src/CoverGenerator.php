<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\RGB as RGBColor;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Throwable;

/**
 * Cover generator. Two models:
 * - photo: background image (cover-cropped) + dark overlay + centered title
 * - pattern: solid background + random pattern piece + centered title
 *
 * The title is truncated to max_chars, split into 1-2 centered lines, drawn
 * over a backing strip tinted with the inverted average background color,
 * and drawn twice (offset shadow layer + main layer) for a slight relief.
 */
final class CoverGenerator extends ImagineAware
{
    private const TITLE_LINE_SPLIT_LENGTH = 7;
    private const SHADOW_OFFSET = 2;

    /** @param array<string, int|bool> $saveOptions */
    public function generate(CoverSpec $spec, string $destPath, array $saveOptions = []): ?string
    {
        $width = $spec->width;
        $height = $spec->height;
        if ($destPath === '' || $width <= 0 || $height <= 0) {
            return null;
        }

        // Direct filesystem ops are intentional: this package is
        // WordPress-free (no WP_Filesystem available in its context).
        if (!is_dir(dirname($destPath)) && !mkdir(dirname($destPath), 0755, true) && !is_dir(dirname($destPath))) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            return null;
        }

        try {
            $image = $spec->model === 'pattern'
                ? $this->patternBackground($spec, $width, $height)
                : $this->photoBackground($spec, $width, $height);
            $image = $this->drawCenterTitle($image, $spec, $width, $height);
            $image->save($destPath, SaveOptions::withDefaults(pathinfo($destPath, PATHINFO_EXTENSION), $saveOptions));
        } catch (Throwable) {
            return null;
        }

        return is_file($destPath) ? $destPath : null;
    }

    private function photoBackground(CoverSpec $spec, int $width, int $height): ImageInterface
    {
        $palette = new RGB();
        $base = null;
        if ($spec->backgroundImage !== '' && is_file($spec->backgroundImage)) {
            $base = $this->coverCropCenter($this->imagine()->open($spec->backgroundImage), $width, $height);
        }
        if ($base === null) {
            $base = $this->imagine()->create(new Box($width, $height), $palette->color('#000000', 100));
        }

        $overlay = $this->imagine()->create(new Box($width, $height), $palette->color([0, 0, 0], $spec->overlayOpacity));
        $base->paste($overlay, new Point(0, 0));

        return $base;
    }

    private function patternBackground(CoverSpec $spec, int $width, int $height): ImageInterface
    {
        $palette = new RGB();
        // 100 alpha = fully opaque (Imagine convention): the base must be a
        // solid layer so the saved cover has no transparent holes.
        $base = $this->imagine()->create(new Box($width, $height), $palette->color(Colors::normalizeHex($spec->backgroundColor), 100));

        $piece = $this->patternPiece($spec, $width, $height);
        if ($piece !== null) {
            $pieceSize = $piece->getSize();
            $base->paste($piece, new Point(
                (int) floor(($width - $pieceSize->getWidth()) / 2),
                (int) floor(($height - $pieceSize->getHeight()) / 2)
            ));
        }

        return $base;
    }

    private function patternPiece(CoverSpec $spec, int $width, int $height): ?ImageInterface
    {
        $material = trim($spec->patternMaterialDir);
        if ($material === '') {
            return null;
        }

        $file = $this->pickPatternFile($material);
        if ($file === null) {
            return null;
        }

        try {
            $image = $this->imagine()->open($file);
        } catch (Throwable) {
            return null;
        }

        // Scale the piece to full canvas height, keeping the aspect ratio.
        $size = $image->getSize();
        if ($size->getHeight() <= 0) {
            return $image;
        }
        $ratio = $height / $size->getHeight();

        return $image->resize(new Box(
            max(1, (int) floor($size->getWidth() * $ratio)),
            max(1, (int) floor($size->getHeight() * $ratio))
        ));
    }

    private function pickPatternFile(string $material): ?string
    {
        if (is_file($material)) {
            return $material;
        }
        if (!is_dir($material)) {
            return null;
        }

        $files = [];
        foreach (['*.jpg', '*.jpeg', '*.png', '*.webp', '*.avif', '*.bmp'] as $pattern) {
            $found = glob(rtrim($material, '/\\') . '/' . $pattern);
            if (is_array($found)) {
                $files = array_merge($files, $found);
            }
        }

        return $files === [] ? null : $files[random_int(0, count($files) - 1)];
    }

    /**
     * Tints the backing strip with the inverted average canvas color so the
     * strip stays visible on both light and dark backgrounds.
     */
    private function labelMaskColor(ImageInterface $image, int $width, int $height): string
    {
        $avg = $this->sampleAverageRgb($image, $width, $height);
        if (Colors::luminance($avg) >= 128) {
            $avg = [
                'r' => 255 - $avg['r'],
                'g' => 255 - $avg['g'],
                'b' => 255 - $avg['b'],
            ];
        }

        return sprintf('#%02x%02x%02x', $avg['r'], $avg['g'], $avg['b']);
    }

    /**
     * Approximates the average canvas color on a 20x20 sample grid.
     *
     * @return array{r: int, g: int, b: int}
     */
    private function sampleAverageRgb(ImageInterface $image, int $width, int $height): array
    {
        $steps = 20;
        $sumR = 0;
        $sumG = 0;
        $sumB = 0;
        $count = 0;
        for ($i = 0; $i < $steps; $i++) {
            for ($j = 0; $j < $steps; $j++) {
                $x = (int) floor(($width - 1) * $i / max(1, $steps - 1));
                $y = (int) floor(($height - 1) * $j / max(1, $steps - 1));
                $color = $image->getColorAt(new Point($x, $y));
                // Channel getters live on the RGB color implementation; the
                // GD/Imagick drivers return RGB for RGB-palette images.
                if (!$color instanceof RGBColor) {
                    continue;
                }
                $sumR += $color->getRed();
                $sumG += $color->getGreen();
                $sumB += $color->getBlue();
                ++$count;
            }
        }

        if ($count <= 0) {
            return ['r' => 0, 'g' => 0, 'b' => 0];
        }

        return [
            'r' => (int) floor($sumR / $count),
            'g' => (int) floor($sumG / $count),
            'b' => (int) floor($sumB / $count),
        ];
    }

    private function drawCenterTitle(ImageInterface $image, CoverSpec $spec, int $width, int $height): ImageInterface
    {
        $title = $this->normalizeTitle($spec->title, $spec->maxChars);
        if ($title === '' || $spec->fontFile === '' || !is_file($spec->fontFile)) {
            return $image;
        }

        $palette = new RGB();
        $titleHex = trim($spec->titleColor) !== '' ? $spec->titleColor : '#ffffff';
        $fontColor = $palette->color(Colors::normalizeHex($titleHex), 100);
        $font = $this->imagine()->font($spec->fontFile, $spec->fontSize, $fontColor);

        $shadowHex = Colors::luminance(Colors::hexToRgb($titleHex)) >= 128 ? '#000000' : '#ffffff';
        $shadowColor = $palette->color($shadowHex, 45);
        $shadowFont = $this->imagine()->font($spec->fontFile, $spec->fontSize, $shadowColor);

        $lines = $this->splitTitleLines($title);
        $lineBoxes = [];
        $totalHeight = 0;
        foreach ($lines as $line) {
            $box = $font->box($line);
            $lineBoxes[] = $box;
            $totalHeight += $box->getHeight();
        }
        $totalHeight += max(0, count($lines) - 1) * $spec->lineSpacing;

        // The backing-strip tint samples the untouched canvas once — strips
        // must not tint themselves off previously drawn strips. All strips
        // draw first, then both text layers, so a strip never covers a
        // neighboring line's glyphs.
        $labelColor = $palette->color(Colors::normalizeHex($this->labelMaskColor($image, $width, $height)), $spec->labelAlpha);
        $positions = [];
        $currentY = (int) floor(($height - $totalHeight) / 2);
        foreach ($lines as $index => $line) {
            $box = $lineBoxes[$index];
            $x = (int) floor(($width - $box->getWidth()) / 2);
            $positions[] = ['line' => $line, 'x' => $x, 'y' => $currentY, 'box' => $box];

            $image->draw()->rectangle(
                new Point(max(0, $x - $spec->labelPaddingX), max(0, $currentY - $spec->labelPaddingY)),
                new Point(min($width - 1, $x + $box->getWidth() + $spec->labelPaddingX), min($height - 1, $currentY + $box->getHeight() + $spec->labelPaddingY)),
                $labelColor,
                true
            );

            $currentY += $box->getHeight() + $spec->lineSpacing;
        }

        foreach ($positions as $item) {
            // imagine 1.5 types DrawerInterface::text() as AbstractFont while
            // ImagineInterface::font() returns FontInterface; every concrete
            // driver returns an AbstractFont, so the call is safe.
            $image->draw()->text($item['line'], $shadowFont, new Point($item['x'] + self::SHADOW_OFFSET, $item['y'] + self::SHADOW_OFFSET)); // @phpstan-ignore argument.type
            $image->draw()->text($item['line'], $font, new Point($item['x'], $item['y'])); // @phpstan-ignore argument.type
        }

        return $image;
    }

    private function normalizeTitle(string $title, int $maxChars): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $title)));
        if ($title === '') {
            return '';
        }

        return mb_substr($title, 0, max(1, $maxChars), 'UTF-8');
    }

    /** @return list<string> Titles longer than 7 characters split into two even lines. */
    private function splitTitleLines(string $title): array
    {
        $length = mb_strlen($title, 'UTF-8');
        if ($length <= self::TITLE_LINE_SPLIT_LENGTH) {
            return [$title];
        }

        $cut = (int) ceil($length / 2);
        $first = mb_substr($title, 0, $cut, 'UTF-8');
        $second = mb_substr($title, $cut, $length - $cut, 'UTF-8');

        return $second === '' ? [$first] : [$first, $second];
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
