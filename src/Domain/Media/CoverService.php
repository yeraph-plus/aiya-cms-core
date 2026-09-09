<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

use Aiya\Infra\ImageProcessor\Assets;
use Aiya\Infra\ImageProcessor\CoverGenerator;
use Aiya\Infra\ImageProcessor\CoverSpec;
use Aiya\Infra\ImageProcessor\FirstImageMatcher;
use Aiya\Infra\ImageProcessor\SaveOptions;
use Closure;
use Imagine\Image\ImagineInterface;
use WP_Error;

/**
 * Generated-cover pipeline. Produces a photo-mode cover from the featured
 * image or the first local content image (falling back to pattern mode),
 * draws the title, stores the result under wp-content/thumbnail/cover/ and
 * persists the `_thumb` card key (content-relative path, full URL as
 * fallback) — one of that key's writers, next to the cron card pipeline.
 * The editor cover shares the card canvas size (640x360): the card image
 * is never reused inside the article body, so one fixed size serves all.
 */
final class CoverService
{
    private const FONT_SIZE = 54;
    private const MAX_CHARS = 15;
    private const LINE_SPACING = 12;
    private const OVERLAY_OPACITY = 30;

    /**
     * @param Closure(): array{format: string, quality: int} $savePolicy
     * @param Closure(): string $fontFile Resolves the title font local path.
     */
    public function __construct(
        private readonly ImagineInterface|Closure $imagine,
        private readonly MediaPaths $paths,
        private readonly Closure $savePolicy,
        private readonly Closure $fontFile
    ) {
    }

    /**
     * Generates a cover for a post and persists the protocol key.
     *
     * @param string $model          photo|pattern
     * @param string $title          Cover title (already user-confirmed).
     * @param string $backgroundColor Hex or empty for a random muted tone.
     * @param string $titleColor     Hex or empty for white.
     * @return array{path: string, url: string}|WP_Error
     */
    public function generateForPost(
        int $postId,
        string $model,
        string $title,
        string $backgroundColor,
        string $titleColor
    ): array|WP_Error {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return new WP_Error('aiya_core_cover_missing_post', __('The post does not exist.', 'aiya-core'));
        }

        $background = null;
        if ($model !== 'pattern') {
            $background = $this->resolveBackground($post);
            // No usable local background: photo mode degrades to pattern.
            if ($background === null) {
                $model = 'pattern';
            }
        }

        $policy = ($this->savePolicy)();
        $format = $this->coverFormat((string) $policy['format']);

        $spec = CoverSpec::fromArray([
            'model' => $model,
            'width' => CardThumbnailService::WIDTH,
            'height' => CardThumbnailService::HEIGHT,
            'background_image' => $background ?? '',
            'background_color' => $backgroundColor,
            'font_file' => ($this->fontFile)(),
            'font_size' => self::FONT_SIZE,
            'title' => $title,
            'title_color' => $titleColor,
            'overlay_opacity' => self::OVERLAY_OPACITY,
            'max_chars' => self::MAX_CHARS,
            'line_spacing' => self::LINE_SPACING,
            'pattern_material_dir' => Assets::patternDir(),
        ]);

        $dest = $this->paths->coverDir() . '/' . wp_date('YmdHis') . '_' . wp_rand(1000, 9999) . '.' . $format;
        $local = (new CoverGenerator($this->imagine))->generate($spec, $dest, SaveOptions::for($format, (int) $policy['quality']));
        if (!is_string($local)) {
            return new WP_Error('aiya_core_cover_generate_failed', __('Cover generation failed.', 'aiya-core'));
        }

        $url = $this->paths->localToUrl($local);
        if ($url === null) {
            return new WP_Error('aiya_core_cover_url_failed', __('The cover URL could not be resolved.', 'aiya-core'));
        }

        // Card key: content-relative path preferred, full URL as the
        // documented fallback shape.
        $relative = $this->paths->relativePath($local);
        update_post_meta($postId, CardThumbnailService::THUMB_KEY, wp_slash($relative !== null ? $relative : $url));

        return ['path' => $local, 'url' => $url];
    }

    /**
     * Background candidate for photo mode: featured image first, then the
     * first image embedded in the content. Only local (content-hosted)
     * images qualify; everything else falls through to pattern mode.
     */
    public function resolveBackground(\WP_Post $post): ?string
    {
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $url = wp_get_attachment_url($thumbId);
            if (is_string($url) && $url !== '') {
                $local = $this->paths->urlToLocal($url);
                if ($local !== null) {
                    return $local;
                }
            }
        }

        $content = (string) get_post_field('post_content', $post->ID);
        $first = (new FirstImageMatcher())->first($content);
        if ($first !== null) {
            return $this->paths->urlToLocal($first);
        }

        return null;
    }

    /** Covers are limited to jpg/webp/avif regardless of the upload format. */
    private function coverFormat(string $setting): string
    {
        $format = strtolower(trim($setting));

        return in_array($format, ['webp', 'avif'], true) ? $format : 'jpg';
    }
}
