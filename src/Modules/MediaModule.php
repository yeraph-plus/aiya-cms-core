<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\CoverService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\ThumbnailService;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\ImageProcessor\Assets;
use Aiya\Infra\ImageProcessor\ImagineFactory;
use Aiya\Infra\ImageProcessor\SaveOptions;
use Aiya\Infra\ImageProcessor\UploadApplier;
use Aiya\Infra\ImageProcessor\WatermarkSpec;
use Closure;
use Imagine\Image\ImagineInterface;

/**
 * Adapter for the aiya/image-processor package (legacy image-manager): owns
 * every WordPress touchpoint — the image processor settings page, the media
 * library takeover filter, and the lazy composition of the domain services.
 * The package itself never sees WordPress.
 *
 * The legacy fake-plugin settings structure (one shared page with per-plugin
 * sections) is deliberately not inherited: each feature owns a dedicated
 * page, so this page is the image processor and nothing else.
 *
 * Semantics preserved from the legacy component and fixed where the review
 * found defects:
 * - the editor-support check for the target format now applies to ALL
 *   uploads (the legacy code only checked the pic-bed path, so media
 *   library uploads could be converted into a format core cannot read);
 * - the watermark opacity setting uses the Imagine convention directly
 *   (0 = transparent, 100 = opaque), removing the legacy inverted mapping;
 * - a configured font that does not exist falls back to the bundled one
 *   (the legacy check was inverted and ignored valid uploads).
 */
final class MediaModule implements Module
{
    public const PAGE_SLUG = 'image';
    public const OPTION_NAME = 'aiya_core_image';
    public const CARD_CRON_HOOK = 'aiya_core_thumbnails_generate';

    private MediaPaths|null $paths = null;
    private ThumbnailService|null $thumbnails = null;
    private CoverService|null $covers = null;
    private CardThumbnailService|null $cards = null;

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        add_filter('wp_handle_upload', [$this, 'handleUpload'], 20, 2);

        // Card composites generate off-thread: a small batch per run, so a
        // fresh listing never times out on inline image work. Until the
        // composite exists, the API serves the live source URL.
        // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five-minute batches on purpose: fresh listings must be covered quickly
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['aiya_core_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every five minutes', 'aiya-core'),
            ];

            return $schedules;
        });
        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CARD_CRON_HOOK)) {
                // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- small batches on purpose: fresh listings must be covered quickly
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'aiya_core_five_minutes', self::CARD_CRON_HOOK);
            }
        }, 5);
        add_action(self::CARD_CRON_HOOK, function (): void {
            $cards = $this->cards();
            foreach ($cards->pendingIds() as $postId) {
                $cards->generateFor($postId);
            }
        });
        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CARD_CRON_HOOK;

            return $hooks;
        });
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Image processor', 'aiya-core'),
            'menu_title' => __('Image', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_image',
                    'type' => 'heading',
                    'label' => __('Image processing', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'image_take_over_uploads',
                    'type' => 'switch',
                    'label' => __('Media library takeover', 'aiya-core'),
                    'checkbox_label' => __('Run uploads through the image processor: scale, watermark, format conversion', 'aiya-core'),
                    'description' => __('WebP/AVIF saving requires the libwebp/libavif PHP libraries; unsupported targets fall back to the source format.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'image_save_format',
                    'type' => 'radio',
                    'label' => __('Save format', 'aiya-core'),
                    'default' => 'jpg',
                    'options' => [
                        'off' => __('Keep source format', 'aiya-core'),
                        'jpg' => __('JPG', 'aiya-core'),
                        'webp' => __('WebP', 'aiya-core'),
                        'avif' => __('AVIF', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'image_max_width',
                    'type' => 'number',
                    'label' => __('Max image width', 'aiya-core'),
                    'description' => __('Uploads wider than this are scaled down; 0 disables the limit.', 'aiya-core'),
                    'default' => 0,
                    'min' => 0,
                    'step' => 1,
                ],
                [
                    'id' => 'image_quality',
                    'type' => 'number',
                    'label' => __('Image quality', 'aiya-core'),
                    'description' => __('Save quality from 0 to 100; PNG maps it to the compression level.', 'aiya-core'),
                    'default' => 96,
                    'min' => 0,
                    'max' => 100,
                    'step' => 1,
                ],
                [
                    'id' => 'image_font_file',
                    'type' => 'text',
                    'label' => __('Font file', 'aiya-core'),
                    'description' => __('Content URL, content-relative path or absolute path for cover titles and text watermarks. Leave empty to use the bundled font.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'heading_watermark',
                    'type' => 'heading',
                    'label' => __('Watermark', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'image_watermark_mode',
                    'type' => 'radio',
                    'label' => __('Watermark mode', 'aiya-core'),
                    'default' => 'off',
                    'options' => [
                        'off' => __('Disabled', 'aiya-core'),
                        'image' => __('Image', 'aiya-core'),
                        'text' => __('Text', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'image_watermark_position',
                    'type' => 'select',
                    'label' => __('Watermark position', 'aiya-core'),
                    'default' => 'bottom-right',
                    'options' => [
                        'center-center' => __('Center', 'aiya-core'),
                        'center-left' => __('Center left', 'aiya-core'),
                        'center-right' => __('Center right', 'aiya-core'),
                        'center-top' => __('Center top', 'aiya-core'),
                        'center-bottom' => __('Center bottom', 'aiya-core'),
                        'top-right' => __('Top right', 'aiya-core'),
                        'top-left' => __('Top left', 'aiya-core'),
                        'top-center' => __('Top center', 'aiya-core'),
                        'bottom-right' => __('Bottom right', 'aiya-core'),
                        'bottom-left' => __('Bottom left', 'aiya-core'),
                        'bottom-center' => __('Bottom center', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'image_watermark_image',
                    'type' => 'media',
                    'label' => __('Watermark image', 'aiya-core'),
                    'description' => __('Media library attachment used in image mode; its own alpha is kept as-is.', 'aiya-core'),
                    'default' => 0,
                ],
                [
                    'id' => 'image_watermark_text',
                    'type' => 'text',
                    'label' => __('Watermark text', 'aiya-core'),
                    'default' => 'AIYA CMS',
                ],
                [
                    'id' => 'image_watermark_font_size',
                    'type' => 'number',
                    'label' => __('Watermark font size', 'aiya-core'),
                    'default' => 24,
                    'min' => 1,
                    'step' => 1,
                ],
                [
                    'id' => 'image_watermark_opacity',
                    'type' => 'number',
                    'label' => __('Watermark opacity (text)', 'aiya-core'),
                    'description' => __('0 = fully transparent, 100 = fully opaque; applies to the text watermark only.', 'aiya-core'),
                    'default' => 80,
                    'min' => 0,
                    'max' => 100,
                    'step' => 1,
                ],
            ],
        ]);
    }

    public function paths(): MediaPaths
    {
        return $this->paths ??= new MediaPaths();
    }

    public function thumbnails(): ThumbnailService
    {
        return $this->thumbnails ??= new ThumbnailService(
            $this->imagine(),
            $this->paths(),
            function (): array {
                return [
                    'format' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_save_format', 'jpg'),
                    'quality' => (int) aiya_core_opt(self::PAGE_SLUG, 'image_quality', 96),
                ];
            }
        );
    }

    public function covers(): CoverService
    {
        return $this->covers ??= new CoverService(
            $this->imagine(),
            $this->paths(),
            function (): array {
                return [
                    'format' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_save_format', 'jpg'),
                    'quality' => (int) aiya_core_opt(self::PAGE_SLUG, 'image_quality', 96),
                ];
            },
            function (): string {
                return $this->fontFile();
            }
        );
    }

    /** The async card-thumbnail pipeline shared by the API read side and the cron worker. */
    public function cards(): CardThumbnailService
    {
        return $this->cards ??= new CardThumbnailService(
            $this->imagine(),
            $this->paths(),
            function (): array {
                return [
                    'format' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_save_format', 'jpg'),
                    'quality' => (int) aiya_core_opt(self::PAGE_SLUG, 'image_quality', 96),
                ];
            }
        );
    }

    /** The processing pipeline shared by the media library takeover and the
     * pic-bed page, exposed as a closure so admin controllers never touch
     * the package directly.
     */
    public function uploadProcessor(): Closure
    {
        return fn (string $file): string|false => $this->processUploadedImage($file);
    }

    /**
     * Media library takeover: process every uploaded image in place.
     *
     * @param array<string, mixed> $upload
     * @return array<string, mixed>
     */
    public function handleUpload(array $upload, string $context): array
    {
        if (!(bool) aiya_core_opt(self::PAGE_SLUG, 'image_take_over_uploads', true)) {
            return $upload;
        }

        $file = $upload['file'] ?? '';
        if (!is_string($file) || $file === '' || !is_file($file)) {
            return $upload;
        }
        $mime = is_string($upload['type'] ?? null) ? $upload['type'] : '';
        if (!str_starts_with($mime, 'image/')) {
            return $upload;
        }

        $normalizedSource = wp_normalize_path($file);
        $processed = $this->processUploadedImage($normalizedSource);
        if (!is_string($processed) || $processed === '' || !is_file($processed)) {
            return ['error' => __('Image processing failed; the upload was cancelled.', 'aiya-core')];
        }

        $processed = wp_normalize_path($processed);
        $upload['file'] = $processed;

        if (!empty($upload['url']) && is_string($upload['url']) && $processed !== $normalizedSource) {
            $basename = wp_basename($processed);
            $rebuilt = preg_replace('/[^\/\?#]+(?=[\?#]|$)/', $basename, $upload['url']);
            if (is_string($rebuilt)) {
                $upload['url'] = $rebuilt;
            }
        }

        $filetype = wp_check_filetype(wp_basename($processed));
        if (!empty($filetype['type'])) {
            $upload['type'] = (string) $filetype['type'];
        }

        return $upload;
    }

    /**
     * Runs the package pipeline over a local file with the current
     * settings: max width, watermark, format conversion with an
     * editor-support check.
     *
     * @return string|false The processed local path, or false on failure.
     */
    public function processUploadedImage(string $source): string|false
    {
        if ($source === '' || !is_file($source)) {
            return false;
        }

        $format = $this->targetFormat($source);
        $applier = new UploadApplier($this->imagine());

        return $applier->process(
            $source,
            $this->watermarkSpec(),
            $format,
            (int) aiya_core_opt(self::PAGE_SLUG, 'image_max_width', 0),
            SaveOptions::for($format, (int) aiya_core_opt(self::PAGE_SLUG, 'image_quality', 96))
        );
    }

    private function imagine(): Closure
    {
        return static fn (): ImagineInterface => ImagineFactory::create();
    }

    private function watermarkSpec(): WatermarkSpec
    {
        return WatermarkSpec::fromArray([
            'mode_type' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_mode', 'off'),
            'position' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_position', 'bottom-right'),
            'font_file' => $this->fontFile(),
            'text' => (string) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_text', ''),
            'image_file' => $this->watermarkImageFile(),
            'font_size' => (int) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_font_size', 24),
            'opacity' => (int) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_opacity', 80),
        ]);
    }

    /**
     * Resolves the configured font: content URL, content-relative path or
     * absolute path. Falls back to the bundled font when missing — the
     * opposite of the legacy check, which ignored valid uploads.
     */
    private function fontFile(): string
    {
        $configured = trim((string) aiya_core_opt(self::PAGE_SLUG, 'image_font_file', ''));
        if ($configured !== '') {
            $local = $this->paths()->urlToLocal($configured);
            if ($local !== null) {
                return $local;
            }
        }

        return Assets::fontFile();
    }

    private function watermarkImageFile(): string
    {
        $attachmentId = (int) aiya_core_opt(self::PAGE_SLUG, 'image_watermark_image', 0);
        if ($attachmentId <= 0) {
            return '';
        }

        $file = get_attached_file($attachmentId);

        return is_string($file) && $file !== '' && is_file($file) ? $file : '';
    }

    /**
     * Save format for uploads: the configured target when core's image
     * editors can actually produce it, otherwise the source format. This
     * check used to run only for pic-bed uploads, leaving media library
     * conversions able to produce unreadable files.
     */
    private function targetFormat(string $source): string
    {
        $sourceExt = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $setting = strtolower(trim((string) aiya_core_opt(self::PAGE_SLUG, 'image_save_format', 'jpg')));
        $target = in_array($setting, ['jpg', 'webp', 'avif'], true) ? $setting : $sourceExt;

        $mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
        ];
        $mime = $mimes[$target] ?? '';
        if ($mime === '' || !wp_image_editor_supports(['mime_type' => $mime])) {
            return $sourceExt !== '' ? $sourceExt : 'jpg';
        }

        return $target;
    }
}
