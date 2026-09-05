<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Cover parameters (pure data). Two models:
 * - photo: background image + dark overlay + centered title
 * - pattern: solid background + random pattern piece + centered title
 */
final class CoverSpec
{
    /**
     * @param string $model              photo|pattern
     * @param int    $width              Canvas width.
     * @param int    $height             Canvas height.
     * @param string $backgroundImage    Local absolute path (photo mode).
     * @param string $backgroundColor    Hex color; empty picks a random muted tone.
     * @param string $fontFile           Local absolute path to the title font.
     * @param int    $fontSize           Title font size in points.
     * @param string $title              Title text.
     * @param string $titleColor         Hex color of the title.
     * @param int    $overlayOpacity     Photo-mode overlay strength, 0-100.
     * @param int    $maxChars           Title is truncated to this many characters.
     * @param int    $lineSpacing        Pixel gap between the 1-2 title lines.
     * @param string $patternMaterialDir Directory (or single file) with pattern pieces.
     * @param int    $labelAlpha         Title backing strip opacity, 0-100.
     * @param int    $labelPaddingX      Horizontal backing strip padding.
     * @param int    $labelPaddingY      Vertical backing strip padding.
     */
    public function __construct(
        public readonly string $model = 'photo',
        public readonly int $width = 800,
        public readonly int $height = 600,
        public readonly string $backgroundImage = '',
        public readonly string $backgroundColor = '',
        public readonly string $fontFile = '',
        public readonly int $fontSize = 70,
        public readonly string $title = '',
        public readonly string $titleColor = '',
        public readonly int $overlayOpacity = 30,
        public readonly int $maxChars = 15,
        public readonly int $lineSpacing = 12,
        public readonly string $patternMaterialDir = '',
        public readonly int $labelAlpha = 45,
        public readonly int $labelPaddingX = 32,
        public readonly int $labelPaddingY = 16,
    ) {
    }

    /** @param array<string, mixed> $args */
    public static function fromArray(array $args): self
    {
        $model = (string) ($args['model'] ?? 'photo');
        $backgroundColor = (string) ($args['background_color'] ?? '');
        if ($backgroundColor === '') {
            $backgroundColor = self::randomPrettyColor();
        }

        return new self(
            model: $model === 'pattern' ? 'pattern' : 'photo',
            width: max(1, (int) ($args['width'] ?? 800)),
            height: max(1, (int) ($args['height'] ?? 600)),
            backgroundImage: (string) ($args['background_image'] ?? ''),
            backgroundColor: $backgroundColor,
            fontFile: (string) ($args['font_file'] ?? ''),
            fontSize: max(1, (int) ($args['font_size'] ?? 70)),
            title: (string) ($args['title'] ?? ''),
            titleColor: (string) ($args['title_color'] ?? ''),
            overlayOpacity: min(100, max(0, (int) ($args['overlay_opacity'] ?? 30))),
            maxChars: max(1, (int) ($args['max_chars'] ?? 15)),
            lineSpacing: max(0, (int) ($args['line_spacing'] ?? 12)),
            patternMaterialDir: (string) ($args['pattern_material_dir'] ?? ($args['pattern_material_path'] ?? '')),
            labelAlpha: min(100, max(0, (int) ($args['label_alpha'] ?? 45))),
            labelPaddingX: max(0, (int) ($args['label_padding_x'] ?? 32)),
            labelPaddingY: max(0, (int) ($args['label_padding_y'] ?? 16)),
        );
    }

    private static function randomPrettyColor(): string
    {
        $palette = [
            '#1f2937', '#334155', '#3b4a6b', '#4b5563', '#1d4e89',
            '#2f3e46', '#3d405b', '#264653', '#2b2d42', '#374151',
        ];

        return $palette[random_int(0, count($palette) - 1)];
    }
}
