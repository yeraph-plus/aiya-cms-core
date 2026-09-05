<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\ImageProcessor\CropGenerator;
use Aiya\Infra\ImageProcessor\ImagineFactory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Avatar handling for the headless backend: local avatars per user, a
 * Gravatar mirror for everything that falls through, and an optional
 * site-wide default avatar URL.
 *
 * The user meta key `basic_user_avatar` is a persistent data protocol
 * carried over from the legacy theme (workspace AGENTS.md). Three shapes
 * stay readable:
 * - file avatars (current): `['full' => 'avatars/{user}/128.jpg', 'v' => int]`
 *   with pre-generated 128px and 64px square crops under
 *   wp-content/avatars/{user_id}/ — outside the media library and uploads,
 *   assembled to static URLs with no PHP hit per render;
 * - `['id' => attachmentId, 'full' => url]` (media-library era);
 * - `['full' => url]` (legacy absolute URL).
 *
 * Uploads are processed straight from the PHP temp file through the
 * image-processor package (center crop + scale) so the original image is
 * never persisted. Any logged-in user can manage their own avatar — the
 * media library is not involved, so no upload_files capability is needed.
 */
final class AvatarModule implements Module
{
    private const META_KEY = 'basic_user_avatar';

    /** Pre-generated square sizes; requests at or below 64 serve the small one. */
    private const FILE_SIZES = [128, 64];
    private const LARGE_SIZE = 128;
    private const SMALL_SIZE = 64;

    private const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

    /** Accepted source types; the stored files are always JPEG. */
    private const SOURCE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const GRAVATAR_HOSTS = [
        'gravatar.com',
        'www.gravatar.com',
        'secure.gravatar.com',
        'cn.gravatar.com',
        's.gravatar.com',
        '0.gravatar.com',
        '1.gravatar.com',
        '2.gravatar.com',
    ];

    private const MIRROR_HOSTS = [
        'qiniu' => 'dn-qiniu-avatar.qbox.me',
        'weavatar' => 'weavatar.com',
    ];

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 11, 0);
        add_filter('get_avatar_data', [$this, 'localAvatarData'], 10, 2);
        add_filter('get_avatar_url', [$this, 'mirrorGravatar'], 999, 3);
        add_filter('avatar_defaults', [$this, 'registerDefaultChoice']);
        add_filter('pre_option_avatar_default', [$this, 'forceDefaultAvatar']);
        add_action('show_user_profile', [$this, 'renderProfileField']);
        add_action('edit_user_profile', [$this, 'renderProfileField']);
        add_action('personal_options_update', [$this, 'saveProfileField']);
        add_action('edit_user_profile_update', [$this, 'saveProfileField']);
        add_action('user_edit_form_tag', [$this, 'formEnctype']);
        add_action('delete_user', [$this, 'deleteUserAvatars']);
    }

    /**
     * Appends this module's fields to the Headless optimization page.
     * Priority 11 keeps it behind HeadlessModule's page registration (10).
     */
    public function settings(): void
    {
        $this->settings->addFields('headless', [
            [
                'id' => 'avatar_cdn_mirror',
                'type' => 'select',
                'label' => __('Gravatar mirror', 'aiya-core'),
                'description' => __('Rewrites gravatar.com hosts to a reachable mirror. Local avatars always win. Loli and v2ex are defunct and not offered.', 'aiya-core'),
                'default' => 'qiniu',
                'options' => [
                    'off' => __('Off', 'aiya-core'),
                    'qiniu' => __('Qiniu (dn-qiniu-avatar.qbox.me)', 'aiya-core'),
                    'weavatar' => __('WeAvatar (weavatar.com)', 'aiya-core'),
                ],
            ],
            [
                'id' => 'avatar_default_url',
                'type' => 'url',
                'label' => __('Default avatar URL', 'aiya-core'),
                'description' => __('Used whenever a user has neither a local avatar nor a gravatar. Overrides the Discussion settings choice while non-empty.', 'aiya-core'),
                'default' => '',
            ],
        ]);
    }

    /**
     * Serves the local avatar for a user; wins over gravatar and mirrors.
     * File avatars pick the pre-generated size (requests at or below 64px
     * serve the small file, everything else the large one) and carry a
     * version query for cache busting.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function localAvatarData(array $args, mixed $idOrEmail): array
    {
        if (!empty($args['force_default'])) {
            return $args;
        }

        $userId = $this->resolveUserId($idOrEmail);
        if ($userId === 0) {
            return $args;
        }

        $url = $this->localAvatarUrl($userId, (int) ($args['size'] ?? 96));
        if ($url === null) {
            return $args;
        }

        $args['url'] = $url;
        $args['found_avatar'] = true;

        return $args;
    }

    /**
     * Rewrites gravatar hosts to the configured mirror. Site-hosted local
     * avatars pass through untouched.
     *
     * @param array<string, mixed> $args
     */
    public function mirrorGravatar(mixed $url, mixed $idOrEmail, array $args): mixed
    {
        $mirror = (string) aiya_core_opt('headless', 'avatar_cdn_mirror', 'qiniu');
        if (!isset(self::MIRROR_HOSTS[$mirror]) || !is_string($url) || $url === '') {
            return $url;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !in_array($host, self::GRAVATAR_HOSTS, true)) {
            return $url;
        }

        return (string) preg_replace(
            '#^(https?)://' . preg_quote($host, '#') . '#i',
            '$1://' . self::MIRROR_HOSTS[$mirror],
            $url
        );
    }

    /** Appends the configured default avatar to the Discussion settings picker.
     *
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    public function registerDefaultChoice(array $defaults): array
    {
        $url = $this->defaultAvatarUrl();
        if ($url !== null) {
            $defaults[$url] = __('Custom default', 'aiya-core');
        }

        return $defaults;
    }

    /** Forces the configured default avatar while the field is non-empty. */
    public function forceDefaultAvatar(mixed $value): mixed
    {
        return $this->defaultAvatarUrl() ?? $value;
    }

    private function defaultAvatarUrl(): ?string
    {
        $url = trim((string) aiya_core_opt('headless', 'avatar_default_url', ''));

        return $url !== '' ? $url : null;
    }

    private function resolveUserId(mixed $idOrEmail): int
    {
        if (is_numeric($idOrEmail)) {
            return absint($idOrEmail);
        }
        if ($idOrEmail instanceof \WP_User) {
            return (int) $idOrEmail->ID;
        }
        if ($idOrEmail instanceof \WP_Comment) {
            return absint($idOrEmail->user_id);
        }
        if ($idOrEmail instanceof \WP_Post) {
            return absint($idOrEmail->post_author);
        }
        if (is_string($idOrEmail) && str_contains($idOrEmail, '@')) {
            $found = get_user_by('email', $idOrEmail);

            return $found instanceof \WP_User ? (int) $found->ID : 0;
        }

        return 0;
    }

    /**
     * Resolves the local avatar URL in all three protocol shapes. Legacy
     * entries carry no size variants or version, so they return as stored.
     */
    private function localAvatarUrl(int $userId, int $size): ?string
    {
        $meta = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($meta)) {
            return null;
        }
        if (isset($meta['id']) && absint($meta['id']) > 0) {
            $url = wp_get_attachment_url(absint($meta['id']));

            return is_string($url) && $url !== '' ? $url : null;
        }

        $full = isset($meta['full']) && is_string($meta['full']) ? $meta['full'] : '';
        if ($full === '') {
            return null;
        }

        // Legacy absolute URL: no pre-generated variants exist.
        if (str_contains($full, '://')) {
            return $full;
        }

        // File avatar: swap the size file under the user's avatar directory.
        $chosen = $size <= self::SMALL_SIZE ? self::SMALL_SIZE : self::LARGE_SIZE;
        $url = content_url('/' . ltrim(dirname($full), '/') . '/' . $chosen . '.jpg');
        $version = isset($meta['v']) ? (int) $meta['v'] : 0;

        return $version > 0 ? $url . '?v=' . $version : $url;
    }

    /** True while the user has a file avatar (current shape) in place. */
    private function fileAvatarVersion(int $userId): int
    {
        $meta = get_user_meta($userId, self::META_KEY, true);

        return is_array($meta) && isset($meta['v']) && (int) $meta['v'] > 0 ? (int) $meta['v'] : 0;
    }

    /**
     * Profile section: a plain file input instead of the media library so
     * users without upload_files (subscribers and up) can manage their own
     * avatar.
     */
    public function renderProfileField(\WP_User $user): void
    {
        $version = $this->fileAvatarVersion($user->ID);
        $previewUrl = null;
        if ($version > 0) {
            $previewUrl = content_url('/avatars/' . $user->ID . '/' . self::LARGE_SIZE . '.jpg?v=' . $version);
        } else {
            $meta = get_user_meta($user->ID, self::META_KEY, true);
            if (is_array($meta) && isset($meta['full']) && is_string($meta['full']) && $meta['full'] !== '') {
                $previewUrl = str_contains($meta['full'], '://')
                    ? $meta['full']
                    : (string) content_url('/' . ltrim($meta['full'], '/'));
            }
        }
        ?>
        <tr class="aiya-core-field aiya-core-field--avatar">
            <th scope="row"><label for="aiya-core-avatar-file"><?php esc_html_e('Local avatar', 'aiya-core'); ?></label></th>
            <td>
                <?php if (is_string($previewUrl) && $previewUrl !== '') : ?>
                    <p><img src="<?php echo esc_url($previewUrl); ?>" alt="" loading="lazy" decoding="async" style="width:64px;height:64px;border-radius:50%;object-fit:cover;vertical-align:middle;"></p>
                <?php endif; ?>
                <input type="file" id="aiya-core-avatar-file" name="aiya_core_avatar_upload" accept="image/jpeg,image/png,image/webp,image/gif">
                <p class="description"><?php esc_html_e('JPEG, PNG, WebP or GIF up to 4 MB. The image is center-cropped and stored as 128px and 64px copies; the uploaded original is not kept.', 'aiya-core'); ?></p>
                <label><input type="checkbox" name="aiya_core_avatar_remove" value="1"> <?php esc_html_e('Remove local avatar', 'aiya-core'); ?></label>
            </td>
        </tr>
        <?php
    }

    /**
     * Profile save: remove checkbox first, then a fresh upload (which wins).
     * Runs for any user editing their own profile — no upload_files needed.
     */
    public function saveProfileField(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        check_admin_referer('update-user_' . $userId);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the profile nonce is verified above.
        if (isset($_POST['aiya_core_avatar_remove'])) {
            $this->removeAvatar($userId);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the profile nonce is verified above.
        $file = $_FILES['aiya_core_avatar_upload'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
            return;
        }

        try {
            $this->validateUpload($file);
            $this->storeAvatar($userId, $file['tmp_name']);
        } catch (RuntimeException $error) {
            wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
        }
    }

    /** Profile forms are not multipart by default; the upload needs it. */
    public function formEnctype(): void
    {
        echo ' enctype="multipart/form-data"';
    }

    /**
     * Stores the file avatars for a user from a local source path. Public so
     * a future REST route for the headless front end can reuse the pipeline.
     *
     * @throws RuntimeException When the source cannot be processed.
     */
    public function storeAvatar(int $userId, string $sourcePath): void
    {
        if ($userId <= 0 || !is_file($sourcePath)) {
            throw new RuntimeException(__('No avatar image was provided.', 'aiya-core'));
        }

        $dir = $this->avatarsDir($userId);
        $quality = min(100, max(1, (int) aiya_core_opt('image', 'image_quality', 96)));
        $crops = new CropGenerator(static fn (): \Imagine\Image\ImagineInterface => ImagineFactory::create());

        foreach (self::FILE_SIZES as $size) {
            $result = $crops->generate($sourcePath, $dir . '/' . $size . '.jpg', $size, $size, ['jpeg_quality' => $quality]);
            if (!is_string($result)) {
                throw new RuntimeException(__('The avatar could not be processed.', 'aiya-core'));
            }
        }

        update_user_meta($userId, self::META_KEY, [
            'full' => 'avatars/' . $userId . '/' . self::LARGE_SIZE . '.jpg',
            'v' => time(),
        ]);
    }

    /** Removes the avatar files and the protocol meta for a user. */
    public function removeAvatar(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $dir = WP_CONTENT_DIR . '/avatars/' . $userId;
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }
                if ($item->isDir()) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of our own pool.
                    @rmdir($item->getPathname());
                } else {
                    wp_delete_file($item->getPathname());
                }
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of our own pool.
            @rmdir($dir);
        }

        delete_user_meta($userId, self::META_KEY);
    }

    /** Account deletion cleans the pooled files along with the user. */
    public function deleteUserAvatars(int $userId): void
    {
        $this->removeAvatar($userId);
    }

    /**
     * Validates one $_FILES entry for the avatar pipeline.
     *
     * @param array<string, mixed> $file
     * @throws RuntimeException When the source cannot be used.
     */
    private function validateUpload(array $file): void
    {
        $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException(__('Invalid avatar upload.', 'aiya-core'));
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(__('The upload failed with a file error.', 'aiya-core'));
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(__('The avatar image is too large (4 MB maximum).', 'aiya-core'));
        }

        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmp);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        if ($mime === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($tmp);
            $mime = is_string($detected) && $detected !== '' ? $detected : null;
        }

        if ($mime === null || !in_array($mime, self::SOURCE_MIMES, true)) {
            throw new RuntimeException(__('Only JPEG, PNG, WebP and GIF images can be used as an avatar.', 'aiya-core'));
        }
    }

    private function avatarsDir(int $userId): string
    {
        $dir = WP_CONTENT_DIR . '/avatars/' . $userId;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }
}
