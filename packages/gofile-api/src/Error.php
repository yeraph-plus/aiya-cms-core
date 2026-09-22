<?php

declare(strict_types=1);

namespace Aiya\Infra\Gofile;

/**
 * A GoFile request that did not produce data, as a value: the platform's own
 * status string, the category it falls into, and the HTTP answer that came
 * with it. The caller decides what it means and which of its own codes it maps
 * to — the package never speaks the site's vocabulary.
 *
 * `PREMIUM` is its own category on purpose: the content read endpoints answer
 * `error-notPremium` (401) to any account below Premium, which is a
 * configuration fact rather than a failure of the wiring, and a caller
 * surfaces it differently.
 */
final class Error
{
    public const UNREACHABLE = 'unreachable';
    public const UNAUTHORIZED = 'unauthorized';
    public const PREMIUM = 'premium';
    public const DENIED = 'denied';
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const INVALID = 'invalid';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status = 502,
    ) {
    }
}
