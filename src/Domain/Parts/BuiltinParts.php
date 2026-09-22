<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

use Aiya\Core\Contracts\Module;
use Closure;

/**
 * The core template-part vocabulary (0.70.0 batch): the legacy inserter's
 * list/col_list/collapse/alert/clip_board shortcodes rebuilt on the part
 * contract, plus the new button link and, since 0.87.0, the related-post
 * card (`[post_id id="7"]`).
 *
 * Rendering is HTML-first: the auxiliary format parts emit plain native
 * markup the front end styles directly (`ul`/`ol`+`li`, `dl`+`dt`/`dd`
 * with a `data-ratio`, native `details`/`summary` for collapse), the
 * clipboard part keeps the legacy `span[data-clipboard-slot]` marker, the
 * one component without an HTML-native shape emits a purpose-named marker
 * tag the front end binds (`<alert>`), and the button is a plain anchor
 * carrying its variant as a class. Legacy tailwind classes do not carry
 * over.
 *
 * The card is the one part that must READ something (a post, through the
 * content domain's query + the Api-layer summary projection), so its
 * renderer arrives as an injected closure from the composition root: this
 * domain declares the shortcode, the closure does the reading, and the
 * parts domain keeps no dependency on the API layer. Without an injected
 * renderer the part stays a declaration (no shortcode registered), which is
 * the part contract's own "editor-only" state.
 *
 * The legacy `sponsor_ship` (supporters-gated body) was dropped without a
 * port: contentHtml is a public, shared-cached payload, so a gated body
 * cannot ride inside it, and the placeholder-only card that survives that
 * constraint carries no value on its own (2026-09-15 decision). A viewer-
 * gated equivalent returns with the gated-content API batch or not at all.
 */
final class BuiltinParts implements Module
{
    private const ALERT_LEVELS = ['default', 'warning', 'info', 'success', 'error'];
    private const RATIOS = ['1', '2', '3'];

    /** The related-post card's shortcode contract. */
    public const POST_CARD_TAG = 'post_id';
    public const POST_CARD_ATTRIBUTE = 'id';

    /**
     * @param Closure(int): string|null $postCard post id → card markup ('' when nothing resolves);
     *                                               null leaves the card an editor declaration only
     */
    public function __construct(private readonly ?Closure $postCard = null)
    {
    }

    public function register(): void
    {
        add_filter('aiya_core_register_parts', [$this, 'registerParts']);
    }

    /**
     * @param array<string, PartType> $parts
     * @return array<string, PartType>
     */
    public function registerParts(array $parts): array
    {
        foreach ($this->catalog() as $part) {
            if (!isset($parts[$part->tag])) {
                $parts[$part->tag] = $part;
            }
        }

        return $parts;
    }

    /** @return list<PartType> */
    private function catalog(): array
    {
        return [
            new PartType(
                'list',
                __('Quick list', 'aiya-core'),
                __('Turns lines of text into a list, one item per line.', 'aiya-core'),
                '[list{{attributes}}]{{content}}[/list]',
                [
                    ['id' => 'content', 'type' => 'textarea', 'label' => __('Content', 'aiya-core'), 'default' => ''],
                    ['id' => 'order', 'type' => 'checkbox', 'label' => __('Ordered list', 'aiya-core'), 'default' => false],
                ],
                fn (array $attrs, string $content): string => $this->renderList($attrs, $content),
            ),
            new PartType(
                'col_list',
                __('Quick list (columns)', 'aiya-core'),
                __('Alternates lines into a description list: first line the term, second the description, and so on.', 'aiya-core'),
                '[col_list{{attributes}}]{{content}}[/col_list]',
                [
                    ['id' => 'content', 'type' => 'textarea', 'label' => __('Content', 'aiya-core'), 'default' => ''],
                    [
                        'id' => 'dt_width',
                        'type' => 'select',
                        'label' => __('Term width', 'aiya-core'),
                        'options' => [
                            '1' => __('1/4 width terms', 'aiya-core'),
                            '2' => __('1/2 width terms', 'aiya-core'),
                            '3' => __('3/4 width terms', 'aiya-core'),
                        ],
                        'default' => '1',
                    ],
                ],
                fn (array $attrs, string $content): string => $this->renderColList($attrs, $content),
            ),
            new PartType(
                'collapse',
                __('Collapse panel', 'aiya-core'),
                __('A panel readers can expand or collapse.', 'aiya-core'),
                '[collapse{{attributes}}]{{content}}[/collapse]',
                [
                    ['id' => 'title', 'type' => 'text', 'label' => __('Title', 'aiya-core'), 'default' => ''],
                    ['id' => 'content', 'type' => 'textarea', 'label' => __('Content', 'aiya-core'), 'default' => ''],
                ],
                fn (array $attrs, string $content): string => $this->renderCollapse($attrs, $content),
            ),
            new PartType(
                'alert',
                __('Notice card', 'aiya-core'),
                __('A highlighted notice with a level and a title.', 'aiya-core'),
                '[alert{{attributes}}]{{content}}[/alert]',
                [
                    ['id' => 'name', 'type' => 'text', 'label' => __('Title', 'aiya-core'), 'default' => ''],
                    [
                        'id' => 'type',
                        'type' => 'select',
                        'label' => __('Level', 'aiya-core'),
                        'options' => [
                            'default' => __('Default', 'aiya-core'),
                            'warning' => __('Warning', 'aiya-core'),
                            'info' => __('Info', 'aiya-core'),
                            'success' => __('Success', 'aiya-core'),
                            'error' => __('Error', 'aiya-core'),
                        ],
                        'default' => 'default',
                    ],
                    ['id' => 'content', 'type' => 'textarea', 'label' => __('Content', 'aiya-core'), 'default' => ''],
                ],
                fn (array $attrs, string $content): string => $this->renderAlert($attrs, $content),
            ),
            new PartType(
                'button',
                __('Button link', 'aiya-core'),
                __('A styled link button.', 'aiya-core'),
                '[button{{attributes}}]{{content}}[/button]',
                [
                    ['id' => 'href', 'type' => 'text', 'label' => __('URL', 'aiya-core'), 'default' => ''],
                    [
                        'id' => 'target',
                        'type' => 'select',
                        'label' => __('Opens', 'aiya-core'),
                        'options' => [
                            '_self' => __('Same tab', 'aiya-core'),
                            '_blank' => __('New tab', 'aiya-core'),
                        ],
                        'default' => '_self',
                    ],
                    [
                        'id' => 'variant',
                        'type' => 'select',
                        'label' => __('Style', 'aiya-core'),
                        'options' => [
                            'primary' => __('Primary', 'aiya-core'),
                            'outline' => __('Outline', 'aiya-core'),
                        ],
                        'default' => 'primary',
                    ],
                    ['id' => 'content', 'type' => 'text', 'label' => __('Button label', 'aiya-core'), 'default' => ''],
                ],
                fn (array $attrs, string $content): string => $this->renderButton($attrs, $content),
            ),
            new PartType(
                'clip_board',
                __('Quick copy', 'aiya-core'),
                __('A text snippet the reader copies with one click.', 'aiya-core'),
                '[clip_board{{attributes}}]{{content}}[/clip_board]',
                [
                    ['id' => 'content', 'type' => 'textarea', 'label' => __('Content', 'aiya-core'), 'default' => ''],
                ],
                fn (array $attrs, string $content): string => $this->renderClipBoard($attrs, $content),
            ),
            new PartType(
                self::POST_CARD_TAG,
                __('Related post card', 'aiya-core'),
                __('Shows one post as a card with its cover, category, title and counters. Enter the post ID.', 'aiya-core'),
                '[' . self::POST_CARD_TAG . '{{attributes}}]',
                [
                    [
                        'id' => self::POST_CARD_ATTRIBUTE,
                        'type' => 'text',
                        'label' => __('Post ID', 'aiya-core'),
                        'default' => '',
                    ],
                ],
                $this->postCard === null
                    ? null
                    : fn (array $attrs, string $content): string => $this->renderPostCard($attrs, $content),
            ),
        ];
    }

    /**
     * Hands the id to the injected card renderer. The attribute is the
     * only id source — the inserter writes `[post_id id="7"]`, and the
     * hand-typed enclosing spelling `[post_id]7[/post_id]` deliberately
     * renders nothing instead of quietly inheriting its enclosed text.
     * Note also that WordPress cannot parse `[post_id="7"]` at all — the
     * quotes read as part of the shortcode name — and that a bare
     * `[post_id 7]` is dropped by `shortcode_atts()`, so neither spelling
     * reaches this method.
     *
     * @param array<string, string> $attrs
     */
    private function renderPostCard(array $attrs, string $content): string
    {
        $renderer = $this->postCard;
        if ($renderer === null) {
            return '';
        }

        $id = (int) ($attrs[self::POST_CARD_ATTRIBUTE] ?? 0);
        if ($id <= 0) {
            return '';
        }

        return $renderer($id);
    }

    /** @param array<string, string> $attrs */
    private function renderList(array $attrs, string $content): string
    {
        // The legacy inserter had this mapping reversed (checked "ordered"
        // produced ul); the rebuild binds the checkbox to the honest tag.
        $ordered = filter_var($attrs['order'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $tag = $ordered ? 'ol' : 'ul';
        $out = "<$tag>\n";
        foreach ($this->lines($content) as $item) {
            $out .= '<li>' . do_shortcode($item) . "</li>\n";
        }

        return $out . "</$tag>\n";
    }

    /** @param array<string, string> $attrs */
    private function renderColList(array $attrs, string $content): string
    {
        $ratio = in_array($attrs['dt_width'] ?? '1', self::RATIOS, true) ? $attrs['dt_width'] : '1';
        $out = '<dl data-ratio="' . esc_attr($ratio) . "\">\n";
        $term = true;
        foreach ($this->lines($content) as $line) {
            $out .= ($term ? '<dt>' : '<dd>') . do_shortcode($line) . ($term ? "</dt>\n" : "</dd>\n");
            $term = !$term;
        }

        return $out . "</dl>\n";
    }

    /** @param array<string, string> $attrs */
    private function renderCollapse(array $attrs, string $content): string
    {
        return "<details>\n<summary>" . $this->storedText($attrs['title'] ?? '') . '</summary>'
            . "\n" . do_shortcode($content)
            . "\n</details>\n";
    }

    /** @param array<string, string> $attrs */
    private function renderAlert(array $attrs, string $content): string
    {
        $level = in_array($attrs['type'] ?? 'default', self::ALERT_LEVELS, true) ? $attrs['type'] : 'default';

        return '<alert level="' . $level . '" title="' . $this->storedAttr($attrs['name'] ?? '') . '">'
            . do_shortcode($content)
            . "</alert>\n";
    }

    /** @param array<string, string> $attrs */
    private function renderButton(array $attrs, string $content): string
    {
        $href = esc_url((string) ($attrs['href'] ?? ''));
        if ($href === '') {
            return '';
        }

        $newTab = ($attrs['target'] ?? '_self') === '_blank';
        $variant = ($attrs['variant'] ?? 'primary') === 'outline' ? 'part-button-outline' : 'part-button-primary';
        $label = trim(do_shortcode($content));

        return '<a href="' . $href . '"'
            . ($newTab ? ' target="_blank" rel="noopener"' : '')
            . ' class="part-button ' . $variant . '">'
            . ($label !== '' ? $label : esc_html($href))
            . "</a>\n";
    }

    /**
     * The legacy marker contract survives verbatim: plain text inside
     * `span[data-clipboard-slot]` for the front end's copy button to bind.
     *
     * @param array<string, string> $attrs
     */
    private function renderClipBoard(array $attrs, string $content): string
    {
        $text = trim(wp_strip_all_tags(do_shortcode($content)));
        if ($text === '') {
            return '';
        }

        return '<span data-clipboard-slot>' . esc_html($text) . "</span>\n";
    }

    /**
     * The editor wraps pasted lines in wpautop paragraphs and <br> before
     * the shortcode pass; normalize those back to plain lines and drop
     * empties.
     *
     * @return list<string>
     */
    private function lines(string $content): array
    {
        // Combined wpautop shapes go first; bare paragraph tags mop up the
        // boundaries (<p> at the very start, </p> at the very end).
        $normalized = str_replace(
            ["\r\n", "<br />\n", "</p>\n", "\n<p>", "<br>\n", '<p>', '</p>'],
            "\n",
            $content
        );

        return array_values(array_filter(array_map('trim', explode("\n", $normalized)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Stored part attributes carry storage-time entity encoding (the editor
     * dialog escapes for shortcode-attribute safety), and shortcode parsing
     * does not decode entities — each renderer decodes once before
     * re-escaping for its output context, or `A & B` renders as a literal
     * `A &amp; B`.
     */
    private function storedAttr(string $value): string
    {
        return esc_attr(wp_specialchars_decode($value, ENT_QUOTES));
    }

    private function storedText(string $value): string
    {
        return esc_html(wp_specialchars_decode($value, ENT_QUOTES));
    }
}
