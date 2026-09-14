<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

/**
 * Server-side image type detection for the upload funnels (admin pic bed,
 * front-end community uploads): the stored extension is always derived
 * from the finfo-detected MIME, never from the client-supplied filename.
 */
final class MimeType
{
    /** Accepted upload MIME types and the extension each maps to. */
    public const EXTENSIONS = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/bmp' => '.bmp',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
        'image/avif' => '.avif',
    ];

    /**
     * Real MIME type of a local file via finfo, falling back to the
     * magic-db helper; null when neither can answer.
     */
    public static function detect(string $path): ?string
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
}
