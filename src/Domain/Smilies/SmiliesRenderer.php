<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Smilies;

/**
 * Read-time token replacement for the `::code::` smilies syntax. Storage
 * keeps the literal token, so re-organizing the packs retroactively
 * changes every piece of existing content and authors never edit image
 * markup. The walk mirrors core's convert_smilies: wp_html_split()
 * separates markup from text, text nodes are the only replacement
 * surface, and code/pre/style/script/textarea bodies are skipped so
 * highlighted code and examples stay literal.
 *
 * Only registered codes match — a literal alternation of the scanned
 * file names, longest first — so ordinary double colons, times and URLs
 * are untouched, and adjacent tokens (`::a::::b::`) all convert. A code
 * can never be purely numeric or contain colons, which is what keeps
 * time-like text out of the whitelist. Shortcode resolution (`[tag]`)
 * happens on its own bracket syntax and is unaffected.
 */
final class SmiliesRenderer
{
    /** The class the front end sizes emoji-style via its own CSS. */
    public const IMG_CLASS = 'aiya-smilie';

    /** Elements whose bodies must never gain images (parity with convert_smilies). */
    private const SKIPPED_ELEMENTS = 'code|pre|style|script|textarea';

    /**
     * Per-request pattern memo keyed by the code-set fingerprint: the
     * alternation over a few hundred file names is the renderer's one
     * compile cost, and several renderer instances share a request.
     *
     * @var array<string, string>
     */
    private static array $patterns = [];

    public function __construct(private readonly SmiliesRegistry $registry)
    {
    }

    /** Replaces registered `::code::` tokens in text nodes with inline img markup. */
    public function render(string $html): string
    {
        $map = $this->registry->map();
        if ($map === [] || !str_contains($html, '::')) {
            return $html;
        }

        $pattern = $this->pattern(array_keys($map));
        $out = '';
        $skipped = '';
        foreach (wp_html_split($html) as $element) {
            if ($element === '') {
                continue;
            }
            if ($skipped === '' && preg_match('/^<(' . self::SKIPPED_ELEMENTS . ')(?=[\s\/>])/i', $element, $matches) === 1) {
                $skipped = strtolower((string) $matches[1]);
            } elseif ($skipped !== '' && strcasecmp($element, '</' . $skipped . '>') === 0) {
                $skipped = '';
            } elseif ($skipped === '' && $element[0] !== '<') {
                $element = preg_replace_callback(
                    $pattern,
                    fn (array $matches): string => $this->image($map, (string) $matches[1]),
                    $element
                ) ?? $element;
            }
            $out .= $element;
        }

        return $out;
    }

    /** Removes every registered token from plain text (excerpts, summaries). */
    public function strip(string $text): string
    {
        $map = $this->registry->map();
        if ($map === [] || !str_contains($text, '::')) {
            return $text;
        }

        return preg_replace($this->pattern(array_keys($map)), '', $text) ?? $text;
    }

    /**
     * Deliberately guard-free: the alternation matches exact registered
     * codes only, and any lookaround pair would block adjacent tokens
     * (`::a::::b::` — the shared colon run defeats every boundary
     * assertion). Degenerate colon clusters like `:::x::` degrade to an
     * img with a stray literal colon, never to a wrong image.
     *
     * @param list<string> $codes
     */
    private function pattern(array $codes): string
    {
        usort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $key = md5(implode("\x00", $codes));

        return self::$patterns[$key] ??= '/::(' . implode('|', array_map(
            static fn (string $code): string => preg_quote($code, '/'),
            $codes
        )) . ')::/u';
    }

    /**
     * @param array<string, string> $map
     */
    private function image(array $map, string $code): string
    {
        return sprintf(
            '<img src="%s" alt="%s" class="%s" />',
            esc_url($map[$code]),
            esc_attr($code),
            self::IMG_CLASS
        );
    }
}
