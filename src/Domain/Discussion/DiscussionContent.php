<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use DOMDocument;
use DOMElement;

/**
 * Read-time content parsing for the discussion flow: the sanitized HTML
 * body is walked once to pull out every embedded image (for the front
 * end's 1/3/9-grid layouts) and every #hashtag in the visible text (for
 * tag rendering and filtering). Nothing is stored — the same parse runs
 * on every read, and the tag filter on the service rides a content LIKE
 * instead of a tag column.
 *
 * Tag syntax is the closed Weibo-style `#话题#` only: the inner text
 * carries no `#` and no whitespace, which keeps CJK topics expressible
 * while an unclosed `#` stays literal text (no open-form ambiguity).
 */
final class DiscussionContent
{
    /** A thread or reply may embed at most this many images. */
    public const MAX_IMAGES = 9;

    private const CLOSED_TAG = '/#([^#\s][^#]{0,48})#/';

    /**
     * Every `<img>` in document order: url plus the declared dimensions
     * (0 when the attributes are absent — the front end sizes the grid).
     *
     * @return list<array{url:string,width:int,height:int}>
     */
    public static function images(string $html): array
    {
        if ($html === '' || !str_contains($html, '<img')) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        if (!$loaded) {
            return [];
        }

        $images = [];
        $seen = [];
        foreach ($dom->getElementsByTagName('img') as $node) {
            /** @var DOMElement $node */
            $src = trim((string) $node->getAttribute('src'));
            if ($src === '' || isset($seen[$src])) {
                continue;
            }
            $seen[$src] = true;
            $images[] = [
                'url' => $src,
                'width' => (int) $node->getAttribute('width'),
                'height' => (int) $node->getAttribute('height'),
            ];
        }

        return $images;
    }

    /** Convenience write-path check: the thread stays grid-shaped. */
    public static function imageCount(string $html): int
    {
        return count(self::images($html));
    }

    /**
     * Hashtags in the visible text (attributes are dropped with their
     * tags first, so URL fragments never count), deduplicated in first
     * appearance order.
     *
     * @return list<string>
     */
    public static function tags(string $html): array
    {
        if ($html === '' || !str_contains($html, '#')) {
            return [];
        }

        $text = trim((string) wp_strip_all_tags($html));
        if ($text === '' || !str_contains($text, '#')) {
            return [];
        }

        $tags = [];
        if (preg_match_all(self::CLOSED_TAG, $text, $closed)) {
            foreach ($closed[1] as $tag) {
                $tag = trim((string) $tag, " \t\n\r　");
                if ($tag !== '' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * The SQL fragment pair matching a closed tag in the content column:
     * the same LIKE the extraction regex reduces to. The column name is
     * a caller-side literal (always the service's own column), never
     * user input.
     *
     * @return array{string, list<string>} A "…" SQL snippet plus params.
     */
    public static function tagFilter(string $tag, string $column = 'content'): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $tag = trim($tag);
        $like = '%' . $wpdb->esc_like('#' . $tag) . '#%';

        return [
            "($column LIKE %s)",
            [$like],
        ];
    }
}
