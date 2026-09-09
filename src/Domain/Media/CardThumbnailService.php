<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

use Aiya\Infra\ImageProcessor\FirstImageMatcher;
use Aiya\Infra\ImageProcessor\SaveOptions;
use Aiya\Infra\ImageProcessor\ThumbnailGenerator;
use Aiya\Core\Api\Contract\Image;
use Closure;
use Imagine\Image\ImagineInterface;

/**
 * The card-thumbnail pipeline (2026-09-11 design): every public post gets
 * one 640x360 card stored under the `_thumb` meta key so reads never
 * re-derive from the content. Sources, in order: the generated cover
 * (editor or cron), the featured image, the first content image, the
 * Frontend settings' default-cover placeholder. Reads and writes share
 * this one logic; the cron worker provides the async half (see
 * MediaModule), so listing requests serve the live source URL until the
 * composite exists instead of generating inline.
 *
 * The composite reuses the package's thumbnail generator — the legacy
 * recipe: plain cover-crop for near-ratio sources, and for far-ratio ones
 * a blurred cover-crop background with a white wash plus the contain-fit
 * foreground centered on top.
 */
final class CardThumbnailService
{
    /** Card canvas size; fixed here on purpose — no settings surface. */
    public const WIDTH = 640;
    public const HEIGHT = 360;

    public const THUMB_KEY = '_thumb';

    private const BATCH_SIZE = 10;

    /**
     * @param Closure(): array{format: string, quality: int} $savePolicy
     */
    public function __construct(
        private readonly ImagineInterface|Closure $imagine,
        private readonly MediaPaths $paths,
        private readonly Closure $savePolicy
    ) {
    }

    /**
     * Presenter-facing resolution — never generates: a persisted composite,
     * else the live source URL (the cron worker will replace it), else the
     * configured default placeholder.
     */
    public function resolveFor(\WP_Post $post): ?Image
    {
        $thumb = get_post_meta((int) $post->ID, self::THUMB_KEY, true);
        if (is_string($thumb) && $thumb !== '') {
            $url = $this->persistedUrl($thumb);
            if ($url !== null) {
                return new Image($url, (string) get_the_title($post), null, null);
            }
        }

        $source = $this->sourceUrl($post);
        if ($source !== null) {
            return new Image($source, (string) get_the_title($post), null, null);
        }

        return $this->defaultImage();
    }

    /**
     * Thumbnail source for a post: the featured image first, then the
     * first image embedded in the content; null when neither exists.
     */
    public function sourceUrl(\WP_Post $post): ?string
    {
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $url = wp_get_attachment_url($thumbId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $content = (string) get_post_field('post_content', $post->ID);
        $first = (new FirstImageMatcher())->first($content);

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * Cron worker: composites the card for one post from its source and
     * persists `_thumb`. False means "nothing to do" (no post, no local
     * source or generation failed); the read side keeps serving the live
     * source or placeholder meanwhile.
     */
    public function generateFor(int $postId): bool
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        $source = $this->sourceUrl($post);
        if ($source === null) {
            return false;
        }
        $local = $this->paths->urlToLocal($source);
        if ($local === null) {
            // External sources cannot composite locally; the live source
            // URL stays the thumbnail.
            return false;
        }

        $policy = ($this->savePolicy)();
        $format = in_array(strtolower((string) $policy['format']), ['webp', 'avif'], true)
            ? strtolower((string) $policy['format'])
            : 'jpg';

        $dest = $this->paths->coverDir() . '/' . wp_date('YmdHis') . '_' . wp_rand(1000, 9999) . '.' . $format;
        $generated = (new ThumbnailGenerator($this->imagine))->generate($local, $dest, self::WIDTH, self::HEIGHT, SaveOptions::for($format, (int) $policy['quality']));
        if (!is_string($generated)) {
            return false;
        }

        $relative = $this->paths->relativePath($generated);
        update_post_meta($postId, self::THUMB_KEY, wp_slash($relative !== null ? $relative : (string) $this->paths->localToUrl($generated)));

        return true;
    }

    /**
     * Public posts of the card-bearing types that have no `_thumb` yet —
     * the cron batch. Newest first, so fresh content is covered first.
     *
     * @return list<int>
     */
    public function pendingIds(int $limit = self::BATCH_SIZE): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $types = ['post', 'page', 'resource'];
        $placeholders = implode(', ', array_fill(0, count($types), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL -- fixed posts/postmeta tables and a
        // whitelist type list; the IN-list interpolation cannot pass through prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            // @phpstan-ignore argument.type (fixed posts-table interpolation)
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders) AND m.meta_id IS NULL
             ORDER BY p.post_date DESC LIMIT %d",
            self::THUMB_KEY,
            ...array_merge($types, [$limit])
        ), ARRAY_A);
        // phpcs:enable
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) ($row['ID'] ?? 0);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /** Resolves a persisted `_thumb` value; hand-edited values never pass through. */
    private function persistedUrl(string $thumb): ?string
    {
        $local = $this->paths->urlToLocal($thumb);

        return $local !== null ? $this->paths->localToUrl($local) : null;
    }

    /** The Frontend settings' default-cover placeholder, or null. */
    private function defaultImage(): ?Image
    {
        $attachmentId = (int) aiya_core_opt('frontend', 'default_thumb', 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($attachmentId, 'large');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }

        $alt = (string) get_the_title($attachmentId);

        return new Image($src[0], $alt !== '' ? $alt : (string) get_bloginfo('name'), null, null);
    }
}
