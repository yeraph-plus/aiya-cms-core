<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Hex color helpers shared by the generators and usable by callers for
 * input validation. All methods are total: malformed input normalizes to
 * black rather than throwing.
 */
final class Colors
{
    /**
     * @return array{r: int, g: int, b: int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = trim($hex);
        if ($hex === '') {
            return ['r' => 0, 'g' => 0, 'b' => 0];
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return ['r' => 0, 'g' => 0, 'b' => 0];
        }

        return [
            'r' => (int) hexdec(substr($hex, 0, 2)),
            'g' => (int) hexdec(substr($hex, 2, 2)),
            'b' => (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Normalizes any 3/6 digit hex to the lowercase `#rrggbb` form. */
    public static function normalizeHex(string $hex): string
    {
        $rgb = self::hexToRgb($hex);

        return sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    /**
     * Rec. 601 luma in the 0-255 range.
     *
     * @param array{r: int, g: int, b: int} $rgb
     */
    public static function luminance(array $rgb): int
    {
        return (int) floor(0.299 * (int) $rgb['r'] + 0.587 * (int) $rgb['g'] + 0.114 * (int) $rgb['b']);
    }
}
