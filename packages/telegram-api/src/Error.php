<?php

declare(strict_types=1);

namespace Aiya\Infra\Telegram;

/**
 * A request that did not produce data, as a value: the platform's verdict
 * plus the category it falls into. The caller decides what it means (log
 * it, retry later, treat an edit as done) — the package never speaks the
 * site's vocabulary.
 *
 * `status` is the platform error_code when the API answered ok:false, else
 * the HTTP status of a non-JSON answer; `retryAfter` carries the seconds
 * of a 429's parameters.retry_after, zero everywhere else.
 */
final class Error
{
    public const UNREACHABLE = 'unreachable';
    public const REJECTED = 'rejected';
    public const NOT_MODIFIED = 'not_modified';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status = 0,
        public readonly int $retryAfter = 0,
    ) {
    }
}
