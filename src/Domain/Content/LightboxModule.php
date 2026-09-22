<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;

/**
 * Marks AUTHOR content images for the front end's lightbox (2026-09-16
 * batch): the pass stamps `<img>` tags with the `aiya-lightbox` class,
 * which the front end binds its viewer to.
 *
 * Ordering is the contract: priority 9 runs before core's own content
 * passes (`wp_filter_content_tags` at 10) and before `do_shortcode` at 11,
 * so the pass only ever sees what the author wrote. Markup produced by a
 * shortcode or part — the related-post card's cover, for instance, which is
 * a LINK and must not swallow its click into a viewer — is simply not there
 * yet and needs no per-feature exemption list here. A part that wants its
 * own images zoomable stamps them itself.
 *
 * The one skip that remains is for images that are already inline glyphs
 * (`aiya-smilie`), i.e. author-pasted or historically stored ones.
 */
final class LightboxModule implements Module
{
    public const CLASS_NAME = 'aiya-lightbox';

    /** Rendered smilies stay inline glyphs, never zoom targets. */
    private const SKIPPED_CLASS = 'aiya-smilie';

    public function register(): void
    {
        add_filter('the_content', [$this, 'inject'], 9, 1);
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
            if (str_contains($classes, self::CLASS_NAME) || str_contains($classes, self::SKIPPED_CLASS)) {
                return $tag;
            }
            // Insert before the attribute's closing quote.
            $insertAt = $m[0][1] + strlen($m[0][0]) - 1;

            return substr_replace($tag, ' ' . self::CLASS_NAME, $insertAt, 0);
        }

        return (string) preg_replace('/^<img\b/i', '<img class="' . self::CLASS_NAME . '"', $tag);
    }
}
