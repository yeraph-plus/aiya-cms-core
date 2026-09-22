<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PublicTypes;

/**
 * The related-post card: one post rendered as a small HTML block for
 * embedding in other content (the `[post_id id="7"]` shortcode, and the
 * card discussion threads hang at the bottom of a bound thread).
 *
 * The card is built from the standard summary projection — the single
 * content read path, so it cannot drift from what the API says about the
 * same post. It is deliberately VIEWER-INDEPENDENT, and that independence
 * is earned by an explicit publish-only check: the markup rides
 * `contentHtml`, a public, shared-cacheable payload, so it renders only
 * for `publish` rows — a private target renders nothing even for its
 * author, because a card that appeared and vanished with the viewer would
 * poison the cache (the same constraint that retired the legacy
 * `sponsor_ship` part). Password-protected targets stay publish, so they
 * render like any other post; the gate level rides as the configured
 * badge value, exactly as `PostSummary.badges` carries it — the front end
 * marks it, and `data-badges` never changes the body. Beyond that: no
 * excerpt (the one viewer-dependent field of a summary). The stance
 * mirrors the detail route: cover, category, title and counters are
 * visible to everyone; the body is the part that withholds.
 *
 * Counters are emitted as raw numbers (formatting is the front end's job)
 * in a visible fallback plus `data-*` attributes for a richer treatment.
 */
final class PostCardPresenter
{
    public function __construct(
        private readonly ContentQuery $query,
        private readonly PostPresenter $posts,
    ) {
    }

    /**
     * The card markup for one post id, or an empty string when nothing
     * renderable resolves (missing target, non-publish status, non-public
     * type) — a card is never emitted for a post a reader could not open.
     */
    public function render(int $postId): string
    {
        if ($postId <= 0) {
            return '';
        }

        // The id alone does not name a type: first public type wins, the
        // same resolution the detail-by-id routes use.
        foreach (PublicTypes::all() as $type) {
            $post = $this->query->byId($postId, $type);
            if ($post === null) {
                continue;
            }

            // Publish-only, explicitly: byId hands private posts back to
            // viewers who may read them, but the card must not appear or
            // vanish with the viewer — private (like draft, future or
            // pending) renders nothing, even for its author.
            if ((string) $post->post_status !== 'publish') {
                return '';
            }

            return $this->card($this->posts->summary($post, $type));
        }

        return '';
    }

    private function card(PostSummary $summary): string
    {
        $title = $summary->title;
        $url = $summary->url;
        $cover = $summary->thumbnail;

        $html = '<div data-post-card="' . esc_attr((string) $summary->id) . '"'
            . ' data-card-type="' . esc_attr($summary->type) . '"';
        if ($summary->badges !== []) {
            $html .= ' data-badges="' . esc_attr(implode(' ', $summary->badges)) . '"';
        }
        $html .= '>';

        // The cover is a link, not a zoom target: the lightbox pass runs
        // before shortcodes and never sees this markup.
        $html .= '<a href="' . esc_url($url) . '" title="' . esc_attr($title) . '">';
        if ($cover !== null) {
            $html .= '<img src="' . esc_url($cover->url) . '"'
                . ' alt="' . esc_attr($cover->alt !== '' ? $cover->alt : $title) . '">';
        }
        $html .= '</a>';

        $html .= '<div data-post-card-body>';

        $category = $summary->categories[0] ?? null;
        if ($category !== null) {
            $html .= '<span data-post-card-part="category">' . esc_html($category->name) . '</span>';
        }

        $html .= '<a href="' . esc_url($url) . '"><span data-post-card-part="title">'
            . esc_html($title) . '</span></a>';

        $metrics = $summary->metrics;
        $counters = [];
        foreach (['views' => $metrics->views, 'likes' => $metrics->likes, 'comments' => $metrics->comments] as $name => $value) {
            if ($value > 0) {
                $counters[] = (string) $value;
            }
        }

        $html .= '<span data-post-card-part="metrics"'
            . ' data-views="' . esc_attr((string) $metrics->views) . '"'
            . ' data-likes="' . esc_attr((string) $metrics->likes) . '"'
            . ' data-comments="' . esc_attr((string) $metrics->comments) . '"'
            . ($metrics->ratingScore !== null ? ' data-rating="' . esc_attr((string) $metrics->ratingScore) . '"' : '')
            . ($metrics->ratingCount !== null ? ' data-rating-count="' . esc_attr((string) $metrics->ratingCount) . '"' : '')
            . '>' . esc_html(implode(' · ', $counters)) . '</span>';

        $html .= '</div></div>';

        return $html;
    }
}
