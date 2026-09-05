<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Watermark parameters (pure data). Callers assemble the spec from their own
 * configuration; generators only consume the model and Imagine.
 */
final class WatermarkSpec
{
    /**
     * @param string $modeType  off|image|text
     * @param string $position  One of the eleven nine-grid keys, see POSITION_KEYS.
     * @param string $fontFile  Local absolute path; required for text mode.
     * @param string $text      Watermark text for text mode.
     * @param string $imageFile Local absolute path; required for image mode.
     * @param int    $fontSize  Text watermark font size in points.
     * @param int    $opacity   0 = fully transparent, 100 = fully opaque
     *                          (Imagine alpha convention). Applies to text
     *                          mode; image watermarks keep their own alpha.
     * @param int    $offsetX   Edge margin for corner positions.
     * @param int    $offsetY   Edge margin for corner positions.
     */
    public function __construct(
        public readonly string $modeType = 'off',
        public readonly string $position = 'bottom-right',
        public readonly string $fontFile = '',
        public readonly string $text = '',
        public readonly string $imageFile = '',
        public readonly int $fontSize = 24,
        public readonly int $opacity = 80,
        public readonly int $offsetX = 15,
        public readonly int $offsetY = 15,
    ) {
    }

    public const POSITION_KEYS = [
        'center-center', 'center-left', 'center-right', 'center-top', 'center-bottom',
        'top-left', 'top-center', 'top-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    /**
     * Builds a spec from a loose array; unknown or out-of-range values fall
     * back to the defaults instead of throwing, so a half-configured site
     * still renders sensible watermarks.
     *
     * @param array<string, mixed> $args
     */
    public static function fromArray(array $args): self
    {
        $mode = strtolower(trim((string) ($args['mode_type'] ?? 'off')));
        if (!in_array($mode, ['off', 'image', 'text'], true)) {
            $mode = 'off';
        }

        $position = (string) ($args['position'] ?? 'bottom-right');
        if (!in_array($position, self::POSITION_KEYS, true)) {
            $position = 'bottom-right';
        }

        return new self(
            modeType: $mode,
            position: $position,
            fontFile: (string) ($args['font_file'] ?? ''),
            text: (string) ($args['text'] ?? ''),
            imageFile: (string) ($args['image_file'] ?? ''),
            fontSize: max(1, (int) ($args['font_size'] ?? 24)),
            opacity: min(100, max(0, (int) ($args['opacity'] ?? 80))),
            offsetX: (int) ($args['offset_x'] ?? 15),
            offsetY: (int) ($args['offset_y'] ?? 15),
        );
    }

    /**
     * Effective mode: a mode degrades to off when its required asset is
     * missing, so generators never have to half-apply a watermark.
     */
    public function type(): string
    {
        if ($this->modeType === 'image') {
            return $this->imageFile !== '' && is_file($this->imageFile) ? 'image' : 'off';
        }
        if ($this->modeType === 'text') {
            return $this->text !== '' && $this->fontFile !== '' && is_file($this->fontFile) ? 'text' : 'off';
        }

        return 'off';
    }
}
