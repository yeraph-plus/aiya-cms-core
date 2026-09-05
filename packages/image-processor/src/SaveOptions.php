<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Format + quality to Imagine save-options mapping. The legacy component
 * repeated this table in three places; it lives here once now.
 */
final class SaveOptions
{
    /**
     * @return array<string, int> Imagine options for the format; empty for
     *                            formats without a quality knob (gif).
     */
    public static function for(string $format, int $quality): array
    {
        $format = strtolower(trim($format));
        $quality = min(100, max(0, $quality));

        return match ($format) {
            'jpg', 'jpeg' => ['jpeg_quality' => $quality],
            'bmp' => ['bmp_quality' => $quality],
            'png' => ['png_compression_level' => min(9, max(0, 9 - (int) round(($quality / 100) * 9)))],
            'webp' => ['webp_quality' => $quality],
            'avif' => ['avif_quality' => $quality],
            default => [],
        };
    }

    /**
     * Merges caller options over per-format defaults so a save never runs
     * without explicit quality settings.
     *
     * @param array<string, int|bool> $saveOptions
     * @return array<string, int|bool>
     */
    public static function withDefaults(string $extension, array $saveOptions): array
    {
        $ext = strtolower($extension);
        $defaults = match (true) {
            in_array($ext, ['jpg', 'jpeg'], true) => ['jpeg_quality' => 96],
            $ext === 'bmp' => ['bmp_quality' => 96],
            $ext === 'png' => ['png_compression_level' => 9],
            $ext === 'webp' => ['webp_quality' => 96],
            $ext === 'avif' => ['avif_quality' => 96],
            $ext === 'gif' => ['flatten' => false],
            default => [],
        };

        return array_merge($defaults, $saveOptions);
    }
}
