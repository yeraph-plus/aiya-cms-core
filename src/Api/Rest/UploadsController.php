<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\UploadPresenter;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\MimeType;
use Closure;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Front-end image upload for the community composer
 * (`POST aiya/core/v1/uploads/image`): any logged-in session may push
 * one image through the same processing pipeline the admin pic bed uses
 * — the unified Image settings decide scale, watermark and save format.
 * Files land in a per-user namespace under the pic-bed pool, so the
 * operator-curated root stays clean and S3 keys mirror per author later.
 *
 * The discussion flow inlines the returned URL as an <img> into the
 * kses-filtered HTML body; comment bodies embed the same image HTML
 * through the comment whitelist (2026-09-17 batch).
 *
 * Abuse surface is bounded by the login wall, a fixed-window rate limit
 * and a tighter size cap than the admin tool. No ownership index exists
 * yet (phase 2): uploads are append-only artifacts of the pool tree.
 */
final class UploadsController
{
    private const MAX_SIZE_MB = 5;
    private const HITS = 10;
    private const WINDOW = HOUR_IN_SECONDS;

    /**
     * @param Closure(string): (string|false) $processUpload Media pipeline.
     */
    public function __construct(
        private Closure $processUpload,
        private MediaPaths $paths,
        private RateLimiter $rateLimiter,
        private UploadPresenter $presenter
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/uploads/image', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->upload($request),
            'permission_callback' => fn (): bool|WP_Error => is_user_logged_in()
                ? true
                : new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]),
        ]);
    }

    private function upload(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        if (!$this->rateLimiter->hit('upload-image', self::HITS, self::WINDOW)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, please retry later.', 'aiya-core'), ['status' => 429]);
        }

        $file = $request->get_file_params()['image'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || $file['tmp_name'] === '') {
            return new WP_Error('aiya_upload_empty', __('No file was uploaded.', 'aiya-core'), ['status' => 400]);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('aiya_upload_invalid', __('Invalid upload.', 'aiya-core'), ['status' => 400]);
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('aiya_upload_failed', __('The upload failed with a file error.', 'aiya-core'), ['status' => 400]);
        }

        $maxBytes = self::MAX_SIZE_MB * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            return new WP_Error('aiya_upload_too_large', __('The file is too large.', 'aiya-core'), ['status' => 413]);
        }

        // Real MIME check via finfo; the extension is derived from the type,
        // never from the client-supplied filename.
        $mime = MimeType::detect($file['tmp_name']);
        $extension = $mime === null ? null : (MimeType::EXTENSIONS[$mime] ?? null);
        if ($extension === null) {
            return new WP_Error('aiya_upload_type', __('This file type is not supported.', 'aiya-core'), ['status' => 415]);
        }

        $userId = get_current_user_id();
        $target = trailingslashit($this->paths->userPicBedDir($userId)) . wp_date('d') . '-' . time() . '-' . wp_generate_password(8, false) . $extension;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return new WP_Error('aiya_upload_write', __('The file could not be written.', 'aiya-core'), ['status' => 500]);
        }

        $processed = ($this->processUpload)($target);
        if (!is_string($processed) || !is_file($processed)) {
            wp_delete_file($target);
            return new WP_Error('aiya_upload_process', __('Image processing failed.', 'aiya-core'), ['status' => 422]);
        }
        $target = $processed;

        $url = $this->paths->localToUrl($target);
        $path = $this->paths->relativePath($target);
        if ($url === null || $path === null) {
            wp_delete_file($target);
            return new WP_Error('aiya_upload_url', __('The image URL could not be resolved.', 'aiya-core'), ['status' => 500]);
        }

        $size = getimagesize($target);

        return new WP_REST_Response($this->presenter->result(
            $size,
            (string) ($file['name'] ?? ''),
            $url,
            $path
        ));
    }
}
