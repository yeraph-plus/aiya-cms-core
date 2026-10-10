<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Media\PicBedStore;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\MediaStore;
use Aiya\Core\Domain\Media\MimeType;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Settings\Schema\Page;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Pic-bed screen: uploads images
 * straight into wp-content/aiya_upload_pics/YYYY/MM/ without touching the media
 * library — no attachment IDs, no WP thumbnail generation, nothing lands in
 * wp-content/uploads. Files are addressed by path; the headless front end
 * consumes the content-relative path; there are no shortcode or HTML
 * outputs.
 *
 * Each upload is compressed exactly once through the image-processor
 * pipeline (scale/watermark/format), injected as a closure by the media
 * adapter, and the processed file is the only artifact written to disk.
 * Front-end community uploads share this pipeline through the REST
 * uploads controller and land in the pool's per-user namespace instead.
 *
 * The screen renders through the shared Admin\Ui parts; the upload flow
 * (multipart round trip, result fill, inline failure notice) stays page
 * domain in the inline script.
 */
final class PicBedPage implements Module
{
    private const MENU_SLUG = 'aiya-core-pic-bed';
    private const AJAX_ACTION = 'aiya_core_pic_bed_upload';
    private const NONCE_ACTION = 'aiya_core_pic_bed_upload';
    private const MAX_SIZE_MB = 10;

    public function __construct(
        private readonly MediaStore $store,
        private readonly MediaPaths $paths
    ) {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleUpload']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'pic-bed',
            'title' => __('Pic bed', 'aiya-core'),
            'menu_title' => __('Pic bed', 'aiya-core'),
            'capability' => 'upload_files',
            'icon' => 'dashicons-format-image',
            'position' => 82,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to manage the pic bed.', 'aiya-core'));
        }
        $accept = implode(',', array_keys(MimeType::EXTENSIONS));
        Ui::pageHead(
            __('Pic bed', 'aiya-core'),
            __('Upload images to wp-content/aiya_upload_pics without using the media library or the uploads directory: no attachment IDs, no WP thumbnail generation. Each image is processed once through the image processor and the processed file is what lands on disk. Admin uploads land under the dated root; the headless route files them under u/{user id}.', 'aiya-core')
        );

        Ui::staticCard(__('Upload', 'aiya-core'), function (): void {
            echo '<form id="aiya-core-picbed-form">';
            $accept = implode(',', array_keys(MimeType::EXTENSIONS));
            echo '<input type="file" id="aiya-core-picbed-file" name="image" accept="' . esc_attr($accept) . '" required> ';
            echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '">';
            Ui::button(__('Upload image', 'aiya-core'), ['type' => 'submit', 'variant' => 'button-primary', 'id' => 'aiya-core-picbed-submit']);
            echo '</form>';
            echo '<p class="description">';
            printf(
                /* translators: %d: maximum upload size in megabytes. */
                esc_html__('JPEG, PNG, BMP, GIF, WebP and AVIF are supported, up to %d MB.', 'aiya-core'),
                (int) self::MAX_SIZE_MB
            );
            echo '</p>';
            // The failure notice shell is page domain: the message arrives with
            // the upload round trip and is filled by the inline script.
            echo '<div id="aiya-core-picbed-error" class="notice notice-error inline" hidden><p></p></div>';

            echo '<div id="aiya-core-picbed-result" hidden>';
            echo '<table class="widefat striped"><tbody><tr>';
            echo '<td style="width:300px;"><img id="aiya-core-picbed-preview" src="" alt="" loading="lazy" decoding="async" style="max-width:280px;width:auto;height:auto;display:block;"></td>';
            echo '<td>';
            echo '<p><strong>' . esc_html__('Dimensions', 'aiya-core') . ':</strong> <span id="aiya-core-picbed-dims"></span> — <span id="aiya-core-picbed-mime"></span><br>'
                . '<span class="description"><strong>' . esc_html__('Relative path', 'aiya-core') . ':</strong> <code id="aiya-core-picbed-path"></code></span></p>';
            echo '<p><strong>' . esc_html__('URL', 'aiya-core') . ':</strong><br>';
            echo '<input type="text" class="regular-text" id="aiya-core-picbed-url" readonly> ';
            echo '<span id="aiya-core-picbed-url-copy">';
            Ui::copyText('', __('Copy', 'aiya-core'));
            echo '</span></p>';
            echo '</td></tr></tbody></table>';
            echo '</div>';
        });

        Ui::heading(__('Uploaded images', 'aiya-core'));
        echo '<div id="aiya-core-picbed-list">';
        $this->renderList();
        echo '</div>';
        Ui::pageFoot();
        ?>
        <script>
            jQuery(function ($) {
                var error = $('#aiya-core-picbed-error');
                $('#aiya-core-picbed-form').on('submit', function (e) {
                    e.preventDefault();
                    var file = document.getElementById('aiya-core-picbed-file');
                    if (!file.files.length) {
                        return;
                    }
                    var $button = $('#aiya-core-picbed-submit');
                    $button.prop('disabled', true).text(<?php echo wp_json_encode(__('Uploading…', 'aiya-core')); ?>);
                    error.prop('hidden', true);

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
                            error.find('p').text(res && res.data && res.data.message ? res.data.message : <?php echo wp_json_encode(__('Upload failed.', 'aiya-core')); ?>);
                            error.prop('hidden', false);
                            return;
                        }
                        $('#aiya-core-picbed-result').prop('hidden', false);
                        $('#aiya-core-picbed-preview').attr('src', res.data.url);
                        $('#aiya-core-picbed-dims').text(res.data.image.width + ' × ' + res.data.image.height);
                        $('#aiya-core-picbed-mime').text(res.data.image.mime);
                        $('#aiya-core-picbed-url').val(res.data.url);
                        $('#aiya-core-picbed-path').text(res.data.path);
                        // The copy part reads its payload attribute per click,
                        // so filling it after the round trip is enough — the
                        // payload is the ready-to-paste img tag, the same
                        // shape the list's action column copies.
                        $('#aiya-core-picbed-url-copy .aiya-core-copy').attr('data-aiya-copy', '<img src="' + res.data.url + '" alt="">');
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
        if (!is_array($file)) {
            throw new RuntimeException(__('No file was uploaded.', 'aiya-core'));
        }

        // The shared pipeline (also behind the REST composer upload);
        // its rejections carry the user-facing message already.
        $stored = (new PicBedStore($this->store, self::MAX_SIZE_MB * 1024 * 1024))
            ->store($file, $this->paths->picBedDir(), (string) ($file['name'] ?? ''));

        return [
            'image' => [
                'width' => $stored['width'],
                'height' => $stored['height'],
                'mime' => $stored['mime'],
                'title' => $stored['title'],
            ],
            'url' => $stored['url'],
            'path' => $stored['path'],
        ];
    }

    /** Renders the pool listing: root view by default, or one user's namespace. */
    private function renderList(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter
        $userId = absint((string) ($_GET['pic_user'] ?? ''));
        $files = $this->pooledFiles($userId);

        Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($userId): void {
            Ui::input('pic_user', 'text', $userId > 0 ? (string) $userId : '', [
                'label' => __('User ID', 'aiya-core'),
                'placeholder' => __('empty lists the root pool', 'aiya-core'),
                'size' => 8,
            ]);
        }, ['page' => self::MENU_SLUG]);
        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user !== false) {
                $label = $user->display_name !== '' ? $user->display_name : $user->user_login;
                echo '<p class="description">'
                    . sprintf(
                        /* translators: 1: user display name or login, 2: user ID, 3: link to the user page. */
                        esc_html__('Listing files uploaded by %1$s (#%2$d); %3$s.', 'aiya-core'),
                        esc_html($label),
                        (int) $userId,
                        '<a href="' . esc_url(admin_url('user-edit.php?user_id=' . $userId)) . '">' . esc_html__('open the user page', 'aiya-core') . '</a>'
                    )
                    . '</p>';
            } else {
                echo '<p class="description">' . esc_html(__('That user id does not exist; the list stays empty.', 'aiya-core')) . '</p>';
            }
        }

        $rows = [];
        foreach ($files as $file) {
            $url = $this->paths->localToUrl($file);
            if ($url === null) {
                continue;
            }
            $rows[] = ['url' => $url];
        }
        Ui::listTable(
            [
                'preview' => ['label' => __('Preview', 'aiya-core'), 'width' => '110px'],
                'url' => ['label' => __('URL', 'aiya-core')],
                'actions' => ['label' => __('Actions', 'aiya-core'), 'width' => '120px'],
            ],
            $rows,
            static function (array $row, string $column): void {
                if ($column === 'preview') {
                    echo '<img src="' . esc_url($row['url']) . '" alt="" loading="lazy" decoding="async" style="max-width:96px;max-height:72px;width:auto;height:auto;">';
                    return;
                }
                if ($column === 'actions') {
                    Ui::copyText('<img src="' . $row['url'] . '" alt="">', __('Copy img tag', 'aiya-core'));
                    return;
                }
                echo '<code style="word-break:break-all;">' . esc_html($row['url']) . '</code>';
            },
            __('No uploads yet.', 'aiya-core')
        );
    }

    /**
     * Absolute pool paths, newest first. Without a user id the dated root
     * pool is listed with the per-user namespaces pruned; an id narrows
     * the walk to that user's own tree.
     *
     * @return list<string>
     */
    private function pooledFiles(int $userId): array
    {
        $root = $this->paths->picBedRoot();
        if (!is_dir($root)) {
            return [];
        }

        $base = $userId > 0 ? $root . '/u/' . $userId : $root;
        if (!is_dir($base)) {
            return [];
        }

        $inner = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
        if ($userId === 0) {
            // Root view: the per-user namespaces (root/u/{id}) stay out.
            $inner = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
                return !($current->isDir() && $current->getFilename() === 'u');
            });
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator($inner);
        foreach ($iterator as $file) {
            // RecursiveDirectoryIterator's default current is SplFileInfo
            // (CURRENT_AS_FILEINFO), not a DirectoryIterator instance.
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        rsort($files, SORT_STRING);

        return $files;
    }
}
