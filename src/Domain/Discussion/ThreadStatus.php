<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

/**
 * Thread status (0.45.0 community form): two values only. A thread is
 * `open` — the normal state, no badge — until the author or an
 * administrator `closes` it; closed locks replies and shows a badge.
 * The former issue-style `answered`/`resolved` values and the
 * reply-driven auto transition were dropped with the boards rework.
 */
final class ThreadStatus
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const ALL = [self::OPEN, self::CLOSED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function locksReplies(string $status): bool
    {
        return $status === self::CLOSED;
    }
}
