<?php

declare(strict_types=1);

namespace Aiya\Infra\OpenList;

/**
 * A request that did not produce data, as a value: the platform's answer plus
 * the category it falls into. The caller decides what it means (log it, show
 * nothing, fail a request) and which of its own error codes it maps to — the
 * package never speaks the site's vocabulary.
 *
 * `status` is the HTTP answer the caller should use when it does surface the
 * failure: 404 for "the platform has nothing at that path", 502 for
 * everything the upstream did wrong.
 */
final class Error
{
    public const UNREACHABLE = 'unreachable';
    public const UNAUTHORIZED = 'unauthorized';
    public const DENIED = 'denied';
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status = 502,
    ) {
    }
}
