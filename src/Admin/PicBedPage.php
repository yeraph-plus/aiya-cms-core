<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Media\MediaPaths;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Standalone pic-bed screen (legacy internal-pic-bed): uploads images
 * straight into wp-content/upload-pics/YYYY/MM/ without touching the media
 * library — no attachment IDs, no WP thumbnail generation, nothing lands in
 * wp-content/uploads. Files are addressed by path; the headless front end
 * consumes the content-relative path, the legacy shortcode/HTML outputs are
 * retired.
 *
 * Each upload is compressed exactly once through the image-processor
 * pipeline (scale/watermark/format), injected as a closure by the media
 * adapter, and the processed file is the only artifact written to disk.
 */
final class PicBedPage implements Module
{
    private const AJAX_ACTION = 'aiya_core_pic_bed_upload';
    private const NONCE_ACTION = 'aiya_core_pic_bed_upload';
    private const MAX_SIZE_MB = 10;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/bmp' => '.bmp',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
        'image/avif' => '.avif',
    ];

    /**
     * @param Closure(string): (string|false) $processUpload Media pipeline.
     */
    public function __construct(
        private readonly Closure $processUpload,
        private readonly MediaPaths $paths
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleUpload']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Pic bed', 'aiya-core'),
            __('Pic bed', 'aiya-core'),
            'upload_files',
            'aiya-core-pic-bed',
            [$this, 'render'],
            'dashicons-format-image',
            82
        );
    }

    public function render(): void
    {
        $accept = implode(',', array_keys(self::MIME_EXTENSIONS));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pic bed', 'aiya-core'); ?></h1>
            <p class="description">
                <?php esc_html_e('Upload images to wp-content/upload-pics without using the media library or the uploads directory: no attachment IDs, no WP thumbnail generation. Each image is processed once through the image processor and the processed file is what lands on disk.', 'aiya-core'); ?>
            </p>

            <h2><?php esc_html_e('Upload', 'aiya-core'); ?></h2>
            <form id="aiya-core-picbed-form">
                <input type="file" id="aiya-core-picbed-file" name="image" accept="<?php echo esc_attr($accept); ?>" required>
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
                <button type="submit" class="button button-primary"
                    id="aiya-core-picbed-submit"><?php esc_html_e('Upload image', 'aiya-core'); ?></button>
            </form>
            <p class="description">
                <?php
                /* translators: %d: maximum upload size in megabytes. */
                echo esc_html(sprintf(__('JPEG, PNG, BMP, GIF, WebP and AVIF are supported, up to %d MB.', 'aiya-core'), self::MAX_SIZE_MB));
                ?>
            </p>

            <div id="aiya-core-picbed-result" style="display:none;">
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td style="width:110px;">
                                <img id="aiya-core-picbed-preview" src="" alt="" loading="lazy" decoding="async"
                                    style="max-width:280px;width:auto;height:auto;display:block;">
                            </td>
                            <td>
                                <p><strong><?php esc_html_e('Dimensions', 'aiya-core'); ?>:</strong> <span
                                        id="aiya-core-picbed-dims"></span> — <span id="aiya-core-picbed-mime"></span></p>
                                <p><strong><?php esc_html_e('URL', 'aiya-core'); ?>:</strong><br><input type="text"
                                        class="regular-text" id="aiya-core-picbed-url" readonly></p>
                                <p><strong><?php esc_html_e('Relative path', 'aiya-core'); ?>:</strong><br><input type="text"
                                        class="regular-text" id="aiya-core-picbed-path" readonly></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2><?php esc_html_e('Uploaded images', 'aiya-core'); ?></h2>
            <div id="aiya-core-picbed-list">
                <?php $this->renderList(); ?>
            </div>
        </div>

        <script>
            jQuery(function ($) {
                $('#aiya-core-picbed-form').on('submit', function (e) {
                    e.preventDefault();
                    var file = document.getElementById('aiya-core-picbed-file');
                    if (!file.files.length) {
                        return;
                    }
                    var $button = $('#aiya-core-picbed-submit');
                    $button.prop('disabled', true).text(<?php echo wp_json_encode(__('Uploading…', 'aiya-core')); ?>);

                    var data = new FormData();
                    data.append('action', <?php echo wp_json_encode(self::AJAX_ACTION); ?>);
                    data.append('nonce', $('input[name="nonce"]', this).val());
                    data.append('image', file.files[0]);

                    // FormData must bypass jQuery's query-string serialization
                    // and the default content type, or the multipart body is
                    // dropped and the nonce never reaches the server.
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: data,
                        processData: false,
                        contentType: false,
                        dataType: 'json'
                    }).done(function (res) {
                        if (!res || !res.success) {
                            window.alert(res && res.data && res.data.message ? res.data.message : <?php echo wp_json_encode(__('Upload failed.', 'aiya-core')); ?>);
                            return;
                        }
                        $('#aiya-core-picbed-result').show();
                        $('#aiya-core-picbed-preview').attr('src', res.data.url);
                        $('#aiya-core-picbed-dims').text(res.data.image.width + ' × ' + res.data.image.height);
                        $('#aiya-core-picbed-mime').text(res.data.image.mime);
                        $('#aiya-core-picbed-url').val(res.data.url);
                        $('#aiya-core-picbed-path').val(res.data.path);
                        $('#aiya-core-picbed-file').val('');
                    }).always(function () {
                        $button.prop('disabled', false).text(<?php echo wp_json_encode(__('Upload image', 'aiya-core')); ?>);
                    });
                });
            });
        </script>
        <?php
    }

    public function handleUpload(): void
    {
        try {
            wp_send_json_success($this->processUpload());
        } catch (RuntimeException $error) {
            wp_send_json_error(['message' => $error->getMessage()]);
        }
    }

    /** @return array<string, mixed> Response payload for one upload. */
    private function processUpload(): array
    {
        if (!current_user_can('upload_files')) {
            throw new RuntimeException(__('Insufficient permissions.', 'aiya-core'));
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || empty($file['name']) || !is_string($file['tmp_name'])) {
            throw new RuntimeException(__('No file was uploaded.', 'aiya-core'));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException(__('Invalid upload.', 'aiya-core'));
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(__('The upload failed with a file error.', 'aiya-core'));
        }

        $maxBytes = self::MAX_SIZE_MB * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            throw new RuntimeException(__('The file is too large.', 'aiya-core'));
        }

        // Real MIME check via finfo; the extension is derived from the type,
        // never from the client-supplied filename.
        $mime = $this->realMime($file['tmp_name']);
        if ($mime === null || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException(__('This file type is not supported.', 'aiya-core'));
        }

        $target = trailingslashit($this->paths->picBedDir()) . wp_date('d') . '-' . time() . '-' . wp_generate_password(8, false) . self::MIME_EXTENSIONS[$mime];
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException(__('The file could not be written.', 'aiya-core'));
        }

        $processed = ($this->processUpload)($target);
        if (!is_string($processed) || !is_file($processed)) {
            wp_delete_file($target);
            throw new RuntimeException(__('Image processing failed.', 'aiya-core'));
        }
        $target = $processed;

        $url = $this->paths->localToUrl($target);
        $path = $this->paths->relativePath($target);
        if ($url === null || $path === null) {
            throw new RuntimeException(__('The image URL could not be resolved.', 'aiya-core'));
        }

        $size = getimagesize($target);
        $title = sanitize_file_name((string) $file['name']);

        return [
            'image' => [
                'width' => is_array($size) ? (int) $size[0] : 0,
                'height' => is_array($size) ? (int) $size[1] : 0,
                'mime' => is_array($size) ? $size['mime'] : $mime,
                'title' => $title,
            ],
            'url' => $url,
            'path' => $path,
        ];
    }

    private function realMime(string $path): ?string
    {
        // PHP 8.1+ returns a Finfo object (not a resource); both are truthy.
        // finfo_close() is deprecated and a no-op — the handle frees itself.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return null;
    }

    /** Renders every pooled image grouped by month, newest first. */
    private function renderList(): void
    {
        $root = $this->paths->picBedRoot();
        if (!is_dir($root)) {
            echo '<p>' . esc_html__('No uploads yet.', 'aiya-core') . '</p>';

            return;
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            // RecursiveDirectoryIterator's default current is SplFileInfo
            // (CURRENT_AS_FILEINFO), not a DirectoryIterator instance.
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        if ($files === []) {
            echo '<p>' . esc_html__('No uploads yet.', 'aiya-core') . '</p>';

            return;
        }
        rsort($files, SORT_STRING);

        // A plain table keeps the screen light for large pools: browser-side
        // lazy loading defers the previews until they are scrolled into view.
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:80px;">' . esc_html__('Preview', 'aiya-core') . '</th>';
        echo '<th>' . esc_html__('URL', 'aiya-core') . '</th>';
        echo '<th>' . esc_html__('Relative path', 'aiya-core') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($files as $file) {
            $url = $this->paths->localToUrl($file);
            $path = $this->paths->relativePath($file);
            if ($url === null || $path === null) {
                continue;
            }
            echo '<tr>';
            echo '<td><img src="' . esc_url($url) . '" alt="" loading="lazy" decoding="async" style="max-width:64px;max-height:48px;width:auto;height:auto;"></td>';
            echo '<td><code style="word-break:break-all;">' . esc_html($url) . '</code></td>';
            echo '<td><code style="word-break:break-all;">' . esc_html($path) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
