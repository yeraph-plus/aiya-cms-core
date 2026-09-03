<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Admin\FieldRenderer;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Settings\Schema\Field;

/**
 * Avatar handling for the headless backend: local avatars per user, a
 * Gravatar mirror for everything that falls through, and an optional
 * site-wide default avatar URL.
 *
 * The user meta key `basic_user_avatar` is a persistent data protocol
 * carried over from the legacy theme (workspace AGENTS.md). New entries
 * store `['id' => attachmentId, 'full' => url]`; the legacy URL-only shape
 * (`['full' => url]`) stays readable and is never wiped by an ordinary
 * profile save.
 *
 * Settings are appended to the shared Headless optimization page instead of
 * a page of their own; SMTP is intentionally absent — outgoing mail is
 * delegated to an external provider (SMTP2GO and friends).
 */
final class AvatarModule implements Module
{
    private const META_KEY = 'basic_user_avatar';

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
        add_action('admin_enqueue_scripts', [$this, 'enqueueProfileAssets']);
    }

    /**
     * Appends this module's fields to the shared Headless optimization page.
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

        $url = $this->localAvatarUrl($userId);
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

    /** Reads the protocol meta in both the new and the legacy shape. */
    private function localAvatarUrl(int $userId): ?string
    {
        $meta = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($meta)) {
            return null;
        }
        if (isset($meta['id']) && absint($meta['id']) > 0) {
            $url = wp_get_attachment_url(absint($meta['id']));

            return is_string($url) && $url !== '' ? $url : null;
        }
        if (isset($meta['full']) && is_string($meta['full']) && $meta['full'] !== '') {
            return $meta['full'];
        }

        return null;
    }

    /**
     * Local avatar picker on the profile screens, rendered through the
     * shared media control so the settings-framework JS applies as is.
     */
    public function renderProfileField(\WP_User $user): void
    {
        $meta = get_user_meta($user->ID, self::META_KEY, true);
        $meta = is_array($meta) ? $meta : [];

        $attachmentId = isset($meta['id']) ? absint($meta['id']) : 0;
        $legacy = false;
        if ($attachmentId === 0 && isset($meta['full']) && is_string($meta['full']) && $meta['full'] !== '') {
            $attachmentId = absint(attachment_url_to_postid($meta['full']));
            $legacy = $attachmentId === 0;
        }

        $description = $legacy
            ? __('A legacy avatar entry without an attachment link is in place; pick a new image to replace it (it cannot be cleared from here).', 'aiya-core')
            : __('Pick an image from the media library; it is served for this user across wp-admin and the headless API.', 'aiya-core');

        $field = Field::fromArray([
            'id' => 'local_avatar',
            'type' => 'media',
            'label' => __('Local avatar', 'aiya-core'),
            'description' => $description,
        ]);

        echo '<tr class="aiya-core-field aiya-core-field--media">';
        echo '<th scope="row"><label for="aiya-core-local-avatar">' . esc_html__('Local avatar', 'aiya-core') . '</label></th><td>';
        (new FieldRenderer())->control($field, $attachmentId, 'aiya_core_avatar_id', 'aiya-core-local-avatar');
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '</td></tr>';

        if ($legacy) {
            echo '<input type="hidden" name="aiya_core_avatar_legacy" value="1">';
        }
    }

    /**
     * Persists the picker value in the new protocol shape. Legacy entries
     * whose URL cannot be resolved to an attachment are never wiped by an
     * untouched form submission; picking a replacement rewrites the entry.
     */
    public function saveProfileField(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        check_admin_referer('update-user_' . $userId);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the profile nonce is verified above.
        if (!isset($_POST['aiya_core_avatar_id'])) {
            return;
        }

        $attachmentId = absint(wp_unslash((string) $_POST['aiya_core_avatar_id']));
        if ($attachmentId > 0) {
            $url = wp_get_attachment_url($attachmentId);
            if (is_string($url) && $url !== '') {
                update_user_meta($userId, self::META_KEY, ['id' => $attachmentId, 'full' => $url]);
            }
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the profile nonce is verified above.
        if (isset($_POST['aiya_core_avatar_legacy'])) {
            return;
        }

        delete_user_meta($userId, self::META_KEY);
    }

    /** Enqueues the shared media control assets on the profile screens. */
    public function enqueueProfileAssets(string $hook): void
    {
        if (!in_array($hook, ['profile.php', 'user-edit.php'], true)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], AIYA_CORE_VERSION);
        wp_enqueue_script('aiya-core-admin', AIYA_CORE_URL . 'assets/js/admin.js', ['jquery', 'underscore', 'backbone', 'wp-util', 'wp-a11y'], AIYA_CORE_VERSION, true);
    }
}
