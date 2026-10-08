<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

/**
 * The one pic-bed upload pipeline, shared by the admin page and the
 * REST composer endpoint: validate the posted file (size cap, real MIME
 * via finfo — the extension derives from the type, never from the
 * client-supplied name), land it in the pool under a collision-checked
 * name, run the media pipeline over it (watermark on — pool uploads
 * carry it), and resolve the URL/path facts both faces answer with.
 * Only the destination directory and the size cap differ between the
 * two faces; errors surface as UploadException so each caller maps them
 * into its own transport shape.
 */
final class PicBedStore
{
    public function __construct(
        private readonly MediaStore $store,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * Validates and stores one upload from the `$_FILES`-shaped array.
     *
     * @param array<string, mixed> $file One entry of get_file_params()/$_FILES.
     * @return array{url: string, path: string, width: int, height: int, mime: string|null, title: string}
     * @throws UploadException on every rejection, with a user-facing message.
     */
    public function store(array $file, string $destDir, string $clientName): array
    {
        $tmpName = $file['tmp_name'] ?? null;
        if (!is_string($tmpName) || $tmpName === ''
            || !is_uploaded_file($tmpName)
            || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new UploadException(__('No file was uploaded.', 'aiya-core'));
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $this->maxBytes) {
            throw new UploadException(__('The file is too large.', 'aiya-core'), 413);
        }

        // Real MIME check via finfo; the extension is derived from the type,
        // never from the client-supplied filename.
        $mime = MimeType::detect($tmpName);
        $extension = $mime === null ? null : (MimeType::EXTENSIONS[$mime] ?? null);
        if ($extension === null) {
            throw new UploadException(__('This file type is not supported.', 'aiya-core'), 415);
        }

        $directory = trailingslashit($destDir);
        // The stamped name is near-unique by construction; wp_unique_filename
        // still walks the directory, the way WP's own uploader lands files.
        $name = wp_unique_filename($destDir, wp_date('d') . '-' . time() . '-' . wp_generate_password(8, false) . $extension);
        $target = $directory . $name;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new UploadException(__('The file could not be written.', 'aiya-core'), 500);
        }

        $processed = $this->store->process($target);
        if ($processed === null) {
            $this->store->delete($target);
            throw new UploadException(__('Image processing failed.', 'aiya-core'), 422);
        }
        $target = $processed;

        $facts = $this->store->facts($target);
        if ($facts === null) {
            $this->store->delete($target);
            throw new UploadException(__('The image URL could not be resolved.', 'aiya-core'), 500);
        }

        $size = getimagesize($target);

        return [
            'url' => $facts['url'],
            'path' => $facts['path'],
            'width' => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
            'mime' => is_array($size) ? (string) $size['mime'] : $mime,
            'title' => sanitize_file_name($clientName),
        ];
    }
}
