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

    /**
     * The detail-page hero render of the featured image: a wide 1000x240
     * banner crop — the front end displays the hero at this exact ratio,
     * title/meta overlaid at the bottom.
     */
    public const FEATURED_WIDTH = 1000;
    public const FEATURED_HEIGHT = 240;

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

        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            // Featured-image card derivative: generated file-reuse style
            // on first miss, then a cheap is_file recheck on every read.
            $derived = $this->ensureDerived($thumbId, self::WIDTH, self::HEIGHT);
            if ($derived !== null) {
                return new Image($derived, (string) get_the_title($post), self::WIDTH, self::HEIGHT);
            }
            $live = wp_get_attachment_url($thumbId);
            if (is_string($live) && $live !== '' && $this->paths->urlToLocal($live) !== null) {
                return new Image($live, (string) get_the_title($post), null, null);
            }
        }

        return $this->defaultDerivative() ?? $this->defaultImage();
    }

    /**
     * The site-wide default post cover, derived through the SAME hero
     * crop as post featured images — the front end falls back to it for
     * posts without one, so every article hero shares one geometry.
     */
    public function featuredForAttachment(int $attachmentId): ?Image
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $derived = $this->ensureDerived($attachmentId, self::FEATURED_WIDTH, self::FEATURED_HEIGHT);
        if ($derived !== null) {
            $alt = (string) get_the_title($attachmentId);

            return new Image(
                $derived,
                $alt !== '' ? $alt : (string) get_bloginfo('name'),
                self::FEATURED_WIDTH,
                self::FEATURED_HEIGHT
            );
        }

        return null;
    }

    /**
     * The detail-page background render: the featured image composited
     * at the featured hero size (same three-layer recipe), falling back to the
     * attachment's full-size URL when the driver fails.
     */
    public function featuredFor(\WP_Post $post): ?Image
    {
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId <= 0) {
            return null;
        }

        $derived = $this->ensureDerived($thumbId, self::FEATURED_WIDTH, self::FEATURED_HEIGHT);
        if ($derived !== null) {
            return new Image($derived, (string) get_the_title($post), self::FEATURED_WIDTH, self::FEATURED_HEIGHT);
        }

        $src = wp_get_attachment_image_src($thumbId, 'full');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }

        return new Image($src[0], (string) get_the_title($post), null, null);
    }

    /**
     * Thumbnail source for a post: the featured image first, then the
     * first image embedded in the content. Local images only (media
     * library and the built-in pic-bed pool) — external image-host URLs
     * are out of scope and resolve to null on both the read and the cron
     * side.
     */
    public function sourceUrl(\WP_Post $post): ?string
    {
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $url = wp_get_attachment_url($thumbId);
            if (is_string($url) && $url !== '' && $this->paths->urlToLocal($url) !== null) {
                return $url;
            }
        }

        $content = (string) get_post_field('post_content', $post->ID);
        $first = (new FirstImageMatcher())->first($content);
        if (is_string($first) && $first !== '' && $this->paths->urlToLocal($first) !== null) {
            return $first;
        }

        return null;
    }

    /**
     * Featured-image derivatives for the read side: the card size for
     * list thumbnails and the 1000x240 banner render for detail backgrounds.
     * Deterministic per attachment+size — ThumbnailGenerator reuses an
     * existing dest file, so repeated calls are free (pure-file logic).
     * Never touches `_thumb`; the featured attachment stays the source
     * of record for these files.
     *
     * @return list<Image> 640x360 card first, 1000x240 banner render second.
     */
    public function featuredDerivatives(\WP_Post $post): array
    {
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId <= 0) {
            return [];
        }

        $card = $this->ensureDerived($thumbId, self::WIDTH, self::HEIGHT);
        $render = $this->ensureDerived($thumbId, self::FEATURED_WIDTH, self::FEATURED_HEIGHT);

        $out = [];
        if ($card !== null) {
            $out[] = new Image($card, (string) get_the_title($post), self::WIDTH, self::HEIGHT);
        }
        if ($render !== null) {
            $out[] = new Image($render, (string) get_the_title($post), self::FEATURED_WIDTH, self::FEATURED_HEIGHT);
        }

        return $out;
    }

    /** The 640x360 card derivative of the default placeholder, if configured. */
    public function defaultDerivative(): ?Image
    {
        $attachmentId = (int) aiya_core_opt('frontend', 'default_thumb', 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $url = $this->ensureDerived($attachmentId, self::WIDTH, self::HEIGHT);

        return $url !== null
            ? new Image($url, (string) get_the_title($attachmentId), self::WIDTH, self::HEIGHT)
            : null;
    }

    /**
     * Generates (file-reuse semantics) one derived size of an attachment
     * under thumbnail/{w}x{h}/ and returns its URL, or null when the
     * source is unusable or the driver fails.
     */
    private function ensureDerived(int $attachmentId, int $width, int $height): ?string
    {
        $source = get_attached_file($attachmentId);
        if (!is_string($source) || $source === '' || !is_file($source)) {
            return null;
        }

        $policy = ($this->savePolicy)();
        $format = in_array(strtolower((string) $policy['format']), ['webp', 'avif'], true)
            ? strtolower((string) $policy['format'])
            : 'jpg';
        $dest = $this->paths->thumbnailDir($width, $height) . '/' . $attachmentId . '-' . $width . 'x' . $height . '.' . $format;

        $local = (new ThumbnailGenerator($this->imagine))->generate(
            $source,
            $dest,
            $width,
            $height,
            SaveOptions::for($format, (int) $policy['quality'])
        );
        if (!is_string($local)) {
            return null;
        }

        return $this->paths->localToUrl($local);
    }

    /**
     * Cron worker: composites the card for one post from its source and
     * persists `_thumb`. False means "nothing to do" (no post, no local
     * source or generation failed); the read side keeps serving the live
     * source or placeholder meanwhile.
     */
    public function generateFor(int $postId): bool
    {        $post = get_post($postId);
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

        $dest = $this->paths->coverAutoDir() . '/' . wp_date('YmdHis') . '_' . wp_rand(1000, 9999) . '.' . $format;
        $generated = (new ThumbnailGenerator($this->imagine))->generate($local, $dest, self::WIDTH, self::HEIGHT, SaveOptions::for($format, (int) $policy['quality']));
        if (!is_string($generated)) {
            return false;
        }

        $relative = $this->paths->relativePath($generated);
        update_post_meta($postId, self::THUMB_KEY, wp_slash($relative !== null ? $relative : (string) $this->paths->localToUrl($generated)));

        return true;
    }

    /**
     * Regenerates the card for one post on demand (save hook, the admin
     * refresh action): composites fresh from the current source, swaps
     * `_thumb` to the new file and removes the replaced card — a failed
     * generation keeps the previous thumbnail untouched.
     */
    public function refreshFor(int $postId, bool $force = false): bool
    {
        $previous = get_post_meta($postId, self::THUMB_KEY, true);

        // A manually generated titled cover (thumbnail/cover/manual/) wins
        // over every automatic path — save hook, cron, even a forced batch
        // refresh. Redoing a cover means pressing "Generate cover" in the
        // editor again.
        if (is_string($previous) && str_contains($previous, '/thumbnail/cover/manual/')) {
            return false;
        }

        // Save-hook semantics: an existing automatic card stays until the
        // batch refresh explicitly forces regeneration (e.g. after a
        // featured-image change). First publish has no `_thumb` and
        // generates right here.
        if (!$force && $previous !== '') {
            return false;
        }

        $generated = $this->generateFor($postId);
        if (!$generated) {
            return false;
        }

        $fresh = get_post_meta($postId, self::THUMB_KEY, true);
        if (is_string($previous) && $previous !== '' && $previous !== $fresh) {
            $previousLocal = $this->paths->urlToLocal($previous);
            $coverRoot = $this->paths->coverDir();
            // Only managed card files under the cover tree are removed —
            // hand-edited or foreign values never lose their files.
            if ($previousLocal !== null && str_starts_with($previousLocal, $coverRoot . '/')) {
                wp_delete_file($previousLocal);
            }
        }

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
