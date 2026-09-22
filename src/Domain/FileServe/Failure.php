<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use WP_Error;

/**
 * A list an adapter could not produce, as a value: the caller decides what an
 * unreadable source means (leave the list out, tell the editor, fail a
 * request) and which wire code it becomes.
 *
 * The message is the upstream's own text, for the log hook and the admin
 * preview; the wire carries a localized sentence per code instead, so nothing
 * platform-specific leaks into a reader's response.
 */
final class Failure
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

    /** The stable wire code family this failure belongs to. */
    public function wireCode(): string
    {
        return match ($this->code) {
            self::UNAUTHORIZED => 'aiya_source_unauthorized',
            self::DENIED => 'aiya_source_denied',
            self::NOT_FOUND => 'aiya_source_not_found',
            self::INVALID => 'aiya_source_invalid',
            default => 'aiya_source_unreachable',
        };
    }

    /** The same failure as a REST error, with a reader-facing message. */
    public function toWpError(): WP_Error
    {
        $message = match ($this->code) {
            self::UNAUTHORIZED => __('The file service rejected our credentials.', 'aiya-core'),
            self::DENIED => __('You do not have permission to fetch this file.', 'aiya-core'),
            self::NOT_FOUND => __('The file does not exist or has been removed.', 'aiya-core'),
            self::INVALID => __('The file service answered with something unrecognizable.', 'aiya-core'),
            default => __('The file service is temporarily unavailable.', 'aiya-core'),
        };

        return new WP_Error($this->wireCode(), $message, ['status' => $this->status]);
    }
}
