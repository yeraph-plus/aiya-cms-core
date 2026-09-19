<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Breadcrumb;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\PostDetail;
use Aiya\Core\Api\Contract\PostMetrics;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Api\Contract\Seo;
use Aiya\Core\Api\Contract\Term;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Content\PublicType;
use Aiya\Core\Domain\Content\ReadingTime;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use WP_Post;
use WP_Term;

/**
 * Maps posts to the content contract. This is the only place the content
 * domain touches WP_Post/WP_Term; `the_content` runs here because the
 * filtered HTML is contract data, and the smilies renderer converts
 * `::code::` tokens on that filtered HTML read-time (storage keeps the
 * literal token). Legacy protocol keys (`view_count`,
 * `like_count`) are read only inside this compatibility layer and never
 * leak as names; the card thumbnail (`_thumb`) resolves through the media
 * domain's card pipeline.
 */
final class PostPresenter
{
    public function __construct(
        private readonly CardThumbnailService $cards,
        private readonly SmiliesRenderer $smilies,
        private readonly PostVisibility $visibility,
    ) {
    }

    public function summary(WP_Post $post, PublicType $type): PostSummary
    {
        $gated = $this->visibility->gated($post);

        return new PostSummary(
            (int) $post->ID,
            (string) $post->post_name,
            $type->url((string) $post->post_name),
            $type->name,
            (string) get_the_title($post),
            // A withheld body must not leak its first words either — core
            // blanks protected-post excerpts for the same reason. The
            // front end owns the placeholder copy.
            $gated ? '' : $this->excerpt($post),
            $this->isoDate($post, 'date'),
            $this->isoDate($post, 'modified'),
            // Raw post content, not the filtered render: ReadingTime
            // strips tags itself, and a 100-row list must not run the
            // full the_content chain once per row (detail() renders for
            // real and legitimately pays that cost).
            ReadingTime::estimate((string) $post->post_content),
            $this->thumbnail($post),
            $this->author((int) $post->post_author),
            $this->typedTerms($post, $type, 'category'),
            $this->typedTerms($post, $type, 'tag'),
            $this->metrics((int) $post->ID),
            $this->badges($post)
        );
    }

    /**
     * @param array{previous: WP_Post|null, next: WP_Post|null} $neighbors
     */
    public function detail(WP_Post $post, array $neighbors, PublicType $type): PostDetail
    {
        return $this->buildDetail($post, $neighbors, $type, $this->isLocked($post));
    }

    /**
     * The unlock endpoint's success response: the same detail projection
     * with the password lock lifted — the caller has just proven the
     * password in-request. The visibility gate is evaluated normally.
     *
     * @param array{previous: WP_Post|null, next: WP_Post|null} $neighbors
     */
    public function detailUnlocked(WP_Post $post, array $neighbors, PublicType $type): PostDetail
    {
        return $this->buildDetail($post, $neighbors, $type, false);
    }

    /**
     * The password lock answers "does this viewer still owe the password"
     * (post_password_required) — true means the body stays behind the
     * gate. The visibility gate answers the same question for login/member
     * posts; a withheld body carries no content either way.
     *
     * @param array{previous: WP_Post|null, next: WP_Post|null} $neighbors
     */
    private function buildDetail(WP_Post $post, array $neighbors, PublicType $type, bool $locked): PostDetail
    {
        $summary = $this->summary($post, $type);
        $gated = $this->visibility->gated($post);
        $content = ($locked || $gated)
            ? ''
            : $this->rendered($post);
        // The meta description rides WP's own excerpt field (manual excerpt
        // when the editor wrote one, auto-generated summary otherwise) —
        // the per-post SEO box was retired in 0.72.0. noindex stays a
        // front-end/archive concern; posts are indexable by default.
        // The internal gate level uses '' for public; the wire contract
        // names the value 'public' (front-end enum).
        $visibility = $this->visibility->level($post);

        return new PostDetail(
            $summary,
            $content,
            $locked,
            $visibility === PostVisibility::PUBLIC ? 'public' : $visibility,
            $gated,
            comments_open($post),
            (string) $post->post_excerpt !== '',
            new Seo($summary->title, $summary->excerpt, false),
            [new Breadcrumb($summary->title, null)],
            $this->featured($post, $type),
            isset($neighbors['previous']) && $neighbors['previous'] instanceof WP_Post
                ? $this->summary($neighbors['previous'], $type)
                : null,
            isset($neighbors['next']) && $neighbors['next'] instanceof WP_Post
                ? $this->summary($neighbors['next'], $type)
                : null
        );
    }

    /** Whether this post's body is still locked for the current viewer. */
    public function isLocked(WP_Post $post): bool
    {
        return (string) $post->post_password !== '' && post_password_required($post);
    }

    /**
     * Every term of the type's vocabularies, flat: one vocabulary per
     * contract group ("category") but possibly several per group ("tag" —
     * the resource type carries five). `$contractTaxonomy` filters to one
     * group; "all" returns every vocabulary of the type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function presentTerms(PublicType $type, string $contractTaxonomy): array
    {
        $out = [];
        foreach ($type->taxonomies as [$wpTaxonomy, $contract]) {
            if ($contractTaxonomy !== 'all' && $contract !== $contractTaxonomy) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $wpTaxonomy,
                'hide_empty' => false,
            ]);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $out[] = $this->term($term, $contract)->toArray();
                }
            }
        }

        return $out;
    }

    /**
     * Display-state keys of one post: sticky (leading its list), password
     * (locked body — the detail answers a restricted shape until the
     * visitor unlocks it), private (this viewer may read it because of
     * who they are). Pure facts; copy and styling belong to the front end.
     *
     * @return list<string>
     */
    private function badges(WP_Post $post): array
    {
        $badges = [];
        if (is_sticky((int) $post->ID)) {
            $badges[] = 'sticky';
        }
        if ((string) $post->post_password !== '') {
            $badges[] = 'password';
        }
        if ((string) $post->post_status === 'private') {
            $badges[] = 'private';
        }
        // The gate level rides as its own badge (login/member) whenever a
        // gate is configured — qualified viewers see it as a marker, and
        // HttpCache leans on the same values to keep gated responses out
        // of shared caches.
        $level = $this->visibility->level($post);
        if ($level !== PostVisibility::PUBLIC) {
            $badges[] = $level;
        }

        return $badges;
    }

    private function excerpt(WP_Post $post): string
    {
        $raw = (string) get_the_excerpt($post);
        $text = trim(wp_strip_all_tags($raw));
        // Registered `::code::` tokens never survive into the plain-text
        // excerpt — cards show neither the token nor a broken image.
        $text = $this->smilies->strip($text);
        // The auto-generated excerpt ends with a continuation marker; the
        // front end owns continuation affordances, so it comes off. Core's
        // bracketed forms and the plugin's own bare '...' tail (see
        // ThemeSupportModule's excerpt_more filter) both qualify.
        $text = (string) preg_replace('/(?:\[(?:\x{2026}|\.\.\.|&hellip;)\]|\x{2026}|\.{3}|&hellip;)\s*$/u', '', $text);

        return trim($text);
    }

    private function rendered(WP_Post $post): string
    {
        return $this->smilies->render((string) apply_filters('the_content', $post->post_content));
    }

    private function isoDate(WP_Post $post, string $field): string
    {
        // $field is always the literal 'date' or 'modified' at call sites.
        $timestamp = (int) get_post_timestamp($post, $field === 'modified' ? 'modified' : 'date');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }

    /**
     * Card thumbnail via the media domain's pipeline: the persisted
     * `_thumb` composite, else the live source (featured image or first
     * content image — the cron worker replaces it with the composite),
     * else the configured default placeholder.
     */
    private function thumbnail(WP_Post $post): ?Image
    {
        return $this->cards->resolveFor($post);
    }

    /**
     * The detail hero image. POSTS get the always-valued chain: the
     * featured image first (1000x240 banner crop), then the site-level
     * default post cover, then the site fallback cover — all through the
     * same crop pipeline — and finally the card thumbnail chain; the
     * front end renders the posts hero without any fallback logic of its
     * own. PAGES and RESOURCES never ride the site defaults (the type
     * keeps no hero settings): they answer only their own featured image,
     * usually unset.
     */
    private function featured(WP_Post $post, PublicType $type): ?Image
    {
        $own = $this->cards->featuredFor($post);
        if ($own !== null || $type->name !== 'post') {
            return $own;
        }

        return $this->cards->featuredForAttachment((int) aiya_core_opt('frontend', 'default_post_cover', 0))
            ?? $this->cards->featuredForAttachment((int) aiya_core_opt('frontend', 'default_thumb', 0))
            ?? $this->cards->resolveFor($post);
    }

    /** Author projection by user id; unknown users degrade to an empty author. */
    public function author(int $userId): Author
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new Author(0, '', '', null);
        }

        $avatarUrl = get_avatar_url($userId, ['size' => 128]);

        return new Author(
            $userId,
            (string) $user->user_nicename,
            (string) $user->display_name,
            is_string($avatarUrl) && $avatarUrl !== ''
                ? new Image($avatarUrl, (string) $user->display_name, null, null)
                : null
        );
    }

    /**
     * The type's WP terms of one contract vocabulary, presented with the
     * contract taxonomy name (resource_category answers as "category").
     *
     * @return list<Term>
     */
    private function typedTerms(WP_Post $post, PublicType $type, string $contractTaxonomy): array
    {
        $out = [];
        foreach ($type->taxonomies as [$wpTaxonomy, $contract]) {
            if ($contract !== $contractTaxonomy) {
                continue;
            }
            $rows = wp_get_post_terms((int) $post->ID, $wpTaxonomy);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if ($row instanceof WP_Term) {
                    $out[] = $this->term($row, $contractTaxonomy);
                }
            }
        }

        return $out;
    }

    private function term(WP_Term $term, string $contractTaxonomy): Term
    {
        $icon = get_term_meta((int) $term->term_id, 'icon', true);
        $coverId = (int) get_term_meta((int) $term->term_id, 'thumbnail_id', true);

        return new Term(
            (int) $term->term_id,
            $contractTaxonomy,
            (string) $term->slug,
            (string) $term->name,
            (string) $term->description,
            $term->parent > 0 ? (int) $term->parent : null,
            (int) $term->count,
            (string) $term->taxonomy,
            is_string($icon) && $icon !== '' ? $icon : null,
            $this->attachmentImage($coverId)
        );
    }

    /** Resolves an attachment ID to the contract image; null when unset or broken. */
    private function attachmentImage(int $attachmentId): ?Image
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($attachmentId, 'full');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }

        $alt = (string) get_the_title($attachmentId);

        return new Image(
            $src[0],
            $alt !== '' ? $alt : (string) get_bloginfo('name'),
            (int) $src[1] > 0 ? (int) $src[1] : null,
            (int) $src[2] > 0 ? (int) $src[2] : null
        );
    }

    private function metrics(int $postId): PostMetrics
    {
        $ratingScore = get_post_meta($postId, 'rating_score', true);
        $ratingCount = get_post_meta($postId, 'rating_count', true);

        return new PostMetrics(
            max(0, (int) get_post_meta($postId, 'view_count', true)),
            max(0, (int) get_post_meta($postId, 'like_count', true)),
            max(0, (int) get_comments_number($postId)),
            $ratingScore === '' ? null : max(0, (int) $ratingScore),
            $ratingCount === '' ? null : max(0, (int) $ratingCount)
        );
    }
}
