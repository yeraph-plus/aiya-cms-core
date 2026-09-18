<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;

/**
 * Marks content images for the front end's lightbox (2026-09-16 batch):
 * a late `the_content` pass stamps every `<img>` of the filtered body
 * with the `aiya-lightbox` class, which the front end binds its viewer
 * to. Runs on the_content — after shortcodes and the core responsive
 * passes — and never touches images already carrying `aiya-smilie`, so
 * rendered smilies stay inline glyphs even if they ever ride inside the
 * filtered text instead of being appended after this pass.
 */
final class LightboxModule implements Module
{
    public const CLASS_NAME = 'aiya-lightbox';

    public function register(): void
    {
        add_filter('the_content', [$this, 'inject'], 20, 1);
    }

    public function inject(string $content): string
    {
        if ($content === '' || !str_contains($content, '<img')) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/<img\s[^>]*>/i',
            fn (array $matches): string => $this->bind($matches[0]),
            $content
        );
    }

    /** One img tag → the same tag carrying the lightbox class. */
    private function bind(string $tag): string
    {
        if (preg_match('/class\s*=\s*(["\'])([^"\']*)\1/i', $tag, $m, PREG_OFFSET_CAPTURE)) {
            $classes = $m[2][0];
            if ($classes === self::CLASS_NAME || str_contains($classes, self::CLASS_NAME)
                || str_contains($classes, 'aiya-smilie')) {
                return $tag;
            }
            // Insert before the attribute's closing quote.
            $insertAt = $m[0][1] + strlen($m[0][0]) - 1;

            return substr_replace($tag, ' ' . self::CLASS_NAME, $insertAt, 0);
        }

        return (string) preg_replace('/^<img\b/i', '<img class="' . self::CLASS_NAME . '"', $tag);
    }
}
