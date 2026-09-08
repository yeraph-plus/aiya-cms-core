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
use Aiya\Core\Domain\Content\PublicType;
use Aiya\Core\Domain\Content\ReadingTime;
use Aiya\Core\Domain\Media\MediaPaths;
use WP_Post;
use WP_Term;

/**
 * Maps posts to the content contract. This is the only place the content
 * domain touches WP_Post/WP_Term; `the_content` runs here because the
 * filtered HTML is contract data. Legacy protocol keys (`view_count`,
 * `like_count`, `_aya_thumb`) are read only inside this compatibility
 * layer and never leak as names.
 */
final class PostPresenter
{
    public function __construct(private MediaPaths $paths)
    {
    }

    public function summary(WP_Post $post, PublicType $type): PostSummary
    {
        return new PostSummary(
            (int) $post->ID,
            (string) $post->post_name,
            $type->url((int) $post->ID),
            $type->name,
            (string) get_the_title($post),
            $this->excerpt($post),
            $this->isoDate($post, 'date'),
            $this->isoDate($post, 'modified'),
            ReadingTime::estimate($this->rendered($post)),
            $this->thumbnail($post),
            $this->author((int) $post->post_author),
            $this->typedTerms($post, $type, 'category'),
            $this->typedTerms($post, $type, 'tag'),
            $this->metrics((int) $post->ID)
        );
    }

    /**
     * @param array{previous: WP_Post|null, next: WP_Post|null} $neighbors
     */
    public function detail(WP_Post $post, array $neighbors, PublicType $type): PostDetail
    {
        $summary = $this->summary($post, $type);
        $content = $this->rendered($post);
        $seo = get_post_meta((int) $post->ID, 'aya_box_post_seo', true);
        $seoDescription = is_array($seo) && is_string($seo['seo_desc'] ?? null) && trim((string) $seo['seo_desc']) !== ''
            ? (string) $seo['seo_desc']
            : $summary->excerpt;

        return new PostDetail(
            $summary,
            $content,
            [],
            new Seo($summary->title, $seoDescription, false),
            [new Breadcrumb($summary->title, null)],
            isset($neighbors['previous']) && $neighbors['previous'] instanceof WP_Post
                ? $this->summary($neighbors['previous'], $type)
                : null,
            isset($neighbors['next']) && $neighbors['next'] instanceof WP_Post
                ? $this->summary($neighbors['next'], $type)
                : null
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function presentTerms(PublicType $type, string $contractTaxonomy): array
    {
        $wpTaxonomy = $type->wpCategoryTaxonomy($contractTaxonomy);
        if ($wpTaxonomy === null) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $wpTaxonomy,
            'hide_empty' => false,
        ]);
        if (!is_array($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $out[] = $this->term($term, $contractTaxonomy)->toArray();
            }
        }

        return $out;
    }

    private function excerpt(WP_Post $post): string
    {
        $raw = (string) get_the_excerpt($post);
        $text = trim(wp_strip_all_tags($raw));
        // The auto-generated excerpt ends with the "[…]" marker; the front
        // end owns continuation affordances, so it comes off.
        $text = (string) preg_replace('/\[(\x{2026}|\.\.\.|&hellip;)\]\s*$/u', '', $text);

        return trim($text);
    }

    private function rendered(WP_Post $post): string
    {
        return (string) apply_filters('the_content', $post->post_content);
    }

    private function isoDate(WP_Post $post, string $field): string
    {
        // $field is always the literal 'date' or 'modified' at call sites.
        $timestamp = (int) get_post_timestamp($post, $field === 'modified' ? 'modified' : 'date');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }

    /**
     * Featured image wins; without one the generated-cover protocol key
     * (`_aya_thumb`, stored as content-relative path or full URL) is the
     * fallback; nothing public means null.
     */
    private function thumbnail(WP_Post $post): ?Image
    {
        $thumbId = (int) get_post_thumbnail_id((int) $post->ID);
        if ($thumbId > 0) {
            $src = wp_get_attachment_image_src($thumbId, 'large');
            if (is_array($src) && is_string($src[0]) && $src[0] !== '') {
                $width = (int) $src[1];
                $height = (int) $src[2];
                $alt = (string) get_post_meta($thumbId, '_wp_attachment_image_alt', true);
                return new Image($src[0], $alt, $width > 0 ? $width : null, $height > 0 ? $height : null);
            }
        }

        $cover = get_post_meta((int) $post->ID, '_aya_thumb', true);
        if (is_string($cover) && $cover !== '') {
            $local = $this->paths->urlToLocal($cover);
            $url = $local !== null ? $this->paths->localToUrl($local) : $cover;
            if (is_string($url) && $url !== '') {
                return new Image($url, (string) get_the_title($post), null, null);
            }
        }

        return null;
    }

    private function author(int $userId): Author
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new Author(0, '', null);
        }

        $avatarUrl = get_avatar_url($userId, ['size' => 128]);

        return new Author(
            $userId,
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
        return new Term(
            (int) $term->term_id,
            $contractTaxonomy,
            (string) $term->slug,
            (string) $term->name,
            (string) $term->description,
            $term->parent > 0 ? (int) $term->parent : null,
            (int) $term->count
        );
    }

    private function metrics(int $postId): PostMetrics
    {
        return new PostMetrics(
            max(0, (int) get_post_meta($postId, 'view_count', true)),
            max(0, (int) get_post_meta($postId, 'like_count', true)),
            max(0, (int) get_comments_number($postId))
        );
    }
}
