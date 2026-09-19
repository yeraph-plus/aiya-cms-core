<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\UploadedImage;
use Aiya\Core\Api\Contract\UploadResult;

/**
 * Projects one processed community upload into the wire shape: the
 * stored-file dimensions/mime (post image-processor), the content URL
 * the editor inlines and the content-relative path.
 */
final class UploadPresenter
{
    /**
     * @param array<int|string, mixed>|false|null $size getimagesize() of the stored file
     * @return array<string, mixed>
     */
    public function result(array|false|null $size, string $filename, string $url, string $path): array
    {
        return (new UploadResult(
            new UploadedImage(
                is_array($size) && isset($size[0]) ? (int) $size[0] : 0,
                is_array($size) && isset($size[1]) ? (int) $size[1] : 0,
                is_array($size) && isset($size['mime']) ? (string) $size['mime'] : 'image/jpeg',
                sanitize_file_name($filename)
            ),
            $url,
            $path
        ))->toArray();
    }
}
