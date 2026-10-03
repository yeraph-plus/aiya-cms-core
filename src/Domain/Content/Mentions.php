<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Closure;
use WP_Comment;

/**
 * @mentions support for comments and community threads (marker form per
 * the zero-routing rule, ARCHITECTURE "Zero-routing rule and reference
 * markers" section). The
 * token is `@` + 2-64 word chars (latin/digit/underscore/hyphen/CJK —
 * no spaces, so v1 cannot mention multi-word display names). Resolution
 * order per token: exact `user_nicename` first, then an exact and UNIQUE
 * `display_name` (ambiguous names resolve to nothing — no link, no
 * notification). Both scans skip `code`/`pre` regions and the interior
 * of existing anchors: what the author fenced off never mentions anyone,
 * and an anchor never grows a nested one.
 *
 * `resolve()` answers the notification recipients (first-appearance
 * order, deduplicated, capped); `linkify()` turns resolved tokens into
 * reference anchors (`data-aiya-ref="user"` + nicename handle — zero
 * routing: the front end owns the /profile route). Unresolvable tokens
 * stay plain text.
 */
final class Mentions
{
    private const TOKEN = '/@([A-Za-z0-9_\-\x{3400}-\x{9FFF}]{2,64})/u';

    /** Tags whose interior the scan never enters: fenced code and links
     * that are already links (a mention inside an existing anchor is part
     * of that link's label, not a fresh mention). */
    private const FENCE_TAGS = ['a', 'code', 'pre'];

    /** Notifications per content piece are capped — a mention storm is
     * still a storm. */
    public const MAX_PER_CONTENT = 10;

    public function __construct()
    {
    }

    /**
     * @param list<int> $exclude viewer ids already notified by this event
     * @return list<int> user ids, first-appearance order, deduplicated
     */
    public function resolve(string $text, array $exclude = []): array
    {
        $recipients = [];
        foreach ($this->tokensIn($text) as $token) {
            $user = $this->resolveToken($token);
            if ($user === null || in_array($user, $recipients, true)) {
                continue;
            }
            $recipients[] = $user;
            if (count($recipients) >= self::MAX_PER_CONTENT) {
                break;
            }
        }

        return array_values(array_diff($recipients, $exclude));
    }

    /**
     * Reference-anchor injection for rendered HTML: resolved tokens become
     * `data-aiya-ref="user"` anchors carrying the display name; everything
     * else passes through untouched.
     *
     * @param list<int> $exclude viewer ids whose tokens stay plain text
     */
    public function linkify(string $html, array $exclude = []): string
    {
        $tokens = $this->tokensIn($html);
        if ($tokens === []) {
            return $html;
        }

        $resolved = [];
        foreach ($tokens as $token) {
            $userId = $this->resolveToken($token);
            if ($userId !== null && !in_array($userId, $exclude, true)) {
                $resolved[$token] = get_user_by('id', $userId);
            }
        }
        if ($resolved === []) {
            return $html;
        }

        // The substitution runs per text node, not over the raw HTML: a
        // bare regex pass would also rewrite attribute values (`alt="@x"`)
        // and fenced code sharing a token with plain text.
        return $this->mapTextNodes(
            $html,
            static function (string $text) use ($resolved): string {
                return (string) preg_replace_callback(
                    self::TOKEN,
                    // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.matchFound -- the callback receives one regex match array; no `match` expression is in play
                    static function (array $match) use ($resolved): string {
                        $user = $resolved[$match[1]] ?? null;
                        if (!$user instanceof \WP_User) {
                            return $match[0];
                        }

                        $name = trim((string) $user->display_name);
                        $label = $name !== '' ? $name : (string) $user->user_nicename;

                        return '<a data-aiya-ref="user" data-aiya-nicename="' . esc_attr((string) $user->user_nicename) . '">'
                            . esc_html('@' . $label)
                            . '</a>';
                    },
                    $text
                ) ?? $text;
            }
        );
    }

    /**
     * Walks `wp_html_split` chunks and runs `$visit` over every eligible
     * text node (not inside a fence), reassembling the HTML. Comments ride
     * along untouched and never change fence depth.
     *
     * @param Closure(string): string $visit
     */
    private function mapTextNodes(string $html, Closure $visit): string
    {
        $depth = 0;
        $out = '';
        foreach (wp_html_split($html) as $chunk) {
            if ($chunk === '' || $chunk[0] !== '<') {
                $out .= $depth === 0 ? $visit($chunk) : $chunk;
                continue;
            }

            $out .= $chunk;
            if (str_starts_with($chunk, '<!--')) {
                continue;
            }

            $depth = max(0, $depth + self::fenceDelta($chunk));
        }

        return $out;
    }

    /** @return list<string> mention tokens (without the @) in text nodes */
    private function tokensIn(string $html): array
    {
        $tokens = [];
        $this->mapTextNodes(
            $html,
            static function (string $text) use (&$tokens): string {
                if (preg_match_all(self::TOKEN, $text, $matches) !== false) {
                    foreach ($matches[1] as $token) {
                        $tokens[] = $token;
                    }
                }

                return $text;
            }
        );

        return array_values(array_unique($tokens));
    }

    /** +1 when the chunk opens a fence tag, -1 when it closes one, 0
     * otherwise. The boundary check (`[\s>]`) keeps look-alikes such as
     * `<abbr>` or `<param>` from counting as fence tags. */
    private static function fenceDelta(string $chunk): int
    {
        foreach (self::FENCE_TAGS as $tag) {
            if (preg_match('/^<' . $tag . '[\s>]/i', $chunk) === 1) {
                return 1;
            }
            if (preg_match('/^<\/' . $tag . '[\s>]/i', $chunk) === 1) {
                return -1;
            }
        }

        return 0;
    }

    /** nicename exact first, then an exact-and-unique display name (two
     * hits mean an ambiguous name — nobody is mentioned). */
    private function resolveToken(string $token): ?int
    {
        $byNicename = get_user_by('slug', $token);
        if ($byNicename instanceof \WP_User) {
            return (int) $byNicename->ID;
        }

        global $wpdb;
        $hits = $wpdb->get_results($wpdb->prepare(
            'SELECT ID FROM ' . $wpdb->users . ' WHERE display_name = %s LIMIT 2',
            $token
        ));
        if (is_array($hits) && count($hits) === 1) {
            return (int) $hits[0]->ID;
        }

        return null;
    }
}
