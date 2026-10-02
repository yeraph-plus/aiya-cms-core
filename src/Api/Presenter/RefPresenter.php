<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Shared\PublicTypes;
use Closure;
use WP_Comment;

/**
 * Renders the `[ref]` shortcode: one semantic reference marker per tag,
 * per the zero-routing rule (ARCHITECTURE, "Zero-routing rule and
 * reference markers") — the output carries `data-aiya-ref` handles and
 * never an href; the front end's resolver owns every route.
 *
 * The six kinds are independent parameters and a FIXED precedence decides
 * which one a tag renders: post, user, term, search, comment, thread —
 * the first non-empty attribute wins, later attributes are ignored, so a
 * tag always resolves to exactly one marker regardless of authoring
 * order. Missing targets (deleted user/post/term/comment/thread) render
 * as an empty string: a reference is never emitted for something that no
 * longer exists.
 *
 * Not recursive by construction: outputs are assembled from escaped
 * scalars and the card projection, and never pass through
 * `do_shortcode` — a `[ref]` inside an attribute value stays literal
 * text. Enclosed content is ignored (self-closing tags only).
 */
final class RefPresenter
{
    /** Fixed precedence; the first non-empty attribute renders. */
    private const KIND_ORDER = ['post', 'user', 'term', 'search', 'comment', 'thread'];

    /** Category-role vocabularies a term reference may resolve in. */
    private const TERM_VOCABULARIES = ['category', 'page_category', 'resource_category'];

    public function __construct(
        private Closure $postCard,
        private DiscussionService $threads,
    ) {
    }

    /** @param array<string, mixed> $atts shortcode_atts-filtered attributes */
    public function render(array $atts): string
    {
        foreach (self::KIND_ORDER as $kind) {
            $value = trim((string) ($atts[$kind] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($kind === 'post') {
                return $this->post((int) $value);
            }
            if ($kind === 'user') {
                return $this->user((int) $value);
            }
            if ($kind === 'term') {
                return $this->term((int) $value);
            }
            if ($kind === 'search') {
                return $this->search($value);
            }
            if ($kind === 'comment') {
                return $this->comment((int) $value);
            }

            return $this->thread((int) $value);
        }

        return '';
    }

    /** The related-post card: delegated through the injected renderer. */
    private function post(int $id): string
    {
        return ($this->postCard)($id);
    }

    private function user(int $id): string
    {
        $user = get_user_by('id', $id);
        if (!$user) {
            return '';
        }

        $name = trim((string) $user->display_name);
        $label = $name !== '' ? $name : (string) $user->user_nicename;
        $avatar = get_avatar_url($id, ['size' => 96]);

        return '<a data-aiya-ref="user" data-aiya-nicename="' . esc_attr((string) $user->user_nicename) . '">'
            . (is_string($avatar) && $avatar !== ''
                ? '<img class="aiya-ref-avatar" src="' . esc_url($avatar) . '" alt="" loading="lazy">'
                : '')
            . esc_html($label)
            . '</a>';
    }

    private function term(int $id): string
    {
        foreach (self::TERM_VOCABULARIES as $taxonomy) {
            $term = get_term($id, $taxonomy);
            if (!$term instanceof \WP_Term) {
                continue;
            }

            return '<a data-aiya-ref="term" data-aiya-taxonomy="' . esc_attr($taxonomy) . '"'
                . ' data-aiya-slug="' . esc_attr($term->slug) . '">'
                . esc_html($term->name)
                . '</a>';
        }

        return '';
    }

    private function search(string $keywords): string
    {
        return '<a data-aiya-ref="search" data-aiya-q="' . esc_attr($keywords) . '">'
            . esc_html($keywords)
            . '</a>';
    }

    private function comment(int $id): string
    {
        $comment = get_comment($id);
        if (!$comment instanceof WP_Comment) {
            return '';
        }

        $label = trim(wp_trim_words(wp_strip_all_tags((string) $comment->comment_content), 16));
        if ($label === '') {
            $label = '#' . $id;
        }

        // Route handles for the front end: the anchor deep-links into the
        // parent post's page (type + slug), anchored at the comment.
        $attrs = 'data-aiya-post="' . esc_attr((string) $comment->comment_post_ID) . '"'
            . ' data-aiya-comment="' . esc_attr((string) $id) . '"';
        $post = get_post((int) $comment->comment_post_ID);
        if ($post !== null) {
            $type = PublicTypes::forPostType($post->post_type)?->name;
            if ($type !== null) {
                $attrs .= ' data-aiya-type="' . esc_attr($type) . '"'
                    . ' data-aiya-slug="' . esc_attr((string) $post->post_name) . '"';
            }
        }

        return '<a data-aiya-ref="comment" ' . $attrs . '>' . esc_html($label) . '</a>';
    }

    private function thread(int $id): string
    {
        $thread = $this->threads->byId($id);
        if ($thread === null) {
            return '';
        }

        $title = trim((string) $thread->title);
        $label = $title !== ''
            ? $title
            : wp_trim_words(wp_strip_all_tags((string) $thread->content), 16);
        if ($label === '') {
            $label = '#' . $id;
        }

        return '<a data-aiya-ref="thread" data-aiya-id="' . esc_attr((string) $id) . '"'
            . ' data-aiya-board="' . esc_attr((string) ($thread->board_slug ?? '')) . '">'
            . esc_html($label)
            . '</a>';
    }
}
