<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Identity\AvatarModule;

/**
 * The HTTP face of the file-avatar pipeline: the two admin-ajax
 * endpoints (upload and remove) the profile field talks to. Transport
 * knowledge — superglobals, capability and nonce gates, the JSON
 * envelopes — lives here; the pipeline itself is pure-value input into
 * AvatarModule (the same methods the headless REST route reuses), so
 * the domain never reads the request directly.
 */
final class AvatarAjaxModule implements Module
{
    private const NONCE = 'aiya_core_avatar_';

    public function __construct(private AvatarModule $avatars)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_aiya_core_avatar_upload', [$this, 'handleUpload']);
        add_action('wp_ajax_aiya_core_avatar_remove', [$this, 'handleRemove']);
    }

    /** AJAX upload: validates and processes the file immediately, returns the fresh preview URL. */
    public function handleUpload(): void
    {
        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($userId <= 0 || !current_user_can('edit_user', $userId)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this user.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE . $userId, 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
            wp_send_json_error(['message' => __('No file was uploaded.', 'aiya-core')]);
        }

        try {
            $this->avatars->storeUploadedAvatar($userId, $file);
        } catch (\RuntimeException $error) {
            wp_send_json_error(['message' => $error->getMessage()]);
        }

        wp_send_json_success([
            'url' => $this->avatars->versionedUrl($userId),
            'nonce' => wp_create_nonce(self::NONCE . $userId),
        ]);
    }

    /** AJAX removal: clears the pooled files and the protocol meta. */
    public function handleRemove(): void
    {
        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($userId <= 0 || !current_user_can('edit_user', $userId)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this user.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE . $userId, 'nonce');

        $this->avatars->removeAvatar($userId);

        wp_send_json_success(['nonce' => wp_create_nonce(self::NONCE . $userId)]);
    }
}
