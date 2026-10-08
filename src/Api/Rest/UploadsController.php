<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\UploadPresenter;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\MediaStore;
use Aiya\Core\Domain\Media\PicBedStore;
use Aiya\Core\Domain\Media\UploadException;
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

    public function __construct(
        private MediaStore $store,
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
        if (!$this->rateLimiter->hitFor('upload-image', (int) get_current_user_id(), self::HITS, self::WINDOW)) {
            return RestGuard::rateLimited();
        }

        $file = $request->get_file_params()['image'] ?? null;
        if (!is_array($file)) {
            return new WP_Error('aiya_upload_empty', __('No file was uploaded.', 'aiya-core'), ['status' => 400]);
        }

        // The shared pipeline (also behind the admin pic bed); its
        // rejections carry the user-facing message and HTTP status.
        try {
            $stored = (new PicBedStore($this->store, self::MAX_SIZE_MB * 1024 * 1024))
                ->store($file, $this->paths->userPicBedDir(get_current_user_id()), (string) ($file['name'] ?? ''));
        } catch (UploadException $error) {
            return new WP_Error('aiya_upload_rejected', $error->getMessage(), ['status' => $error->httpStatus]);
        }

        return new WP_REST_Response($this->presenter->result(
            [$stored['width'], $stored['height'], 'mime' => $stored['mime']],
            $stored['title'],
            $stored['url'],
            $stored['path']
        ));
    }
}
