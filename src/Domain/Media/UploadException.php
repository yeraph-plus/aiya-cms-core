<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Media;

use RuntimeException;

/**
 * A boundary-facing failure of the pic-bed upload store, carrying the
 * HTTP status the REST side should answer with. Admin surfaces render
 * the message directly; REST maps it to a WP_Error — one exception type
 * so the shared pipeline never needs to know which face is calling.
 */
final class UploadException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}
