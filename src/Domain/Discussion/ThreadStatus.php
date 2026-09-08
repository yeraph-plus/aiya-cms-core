<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

/**
 * Thread status workflow (2026-09-09 contract): a thread opens as `open`,
 * flips to `answered` automatically when someone other than the author
 * replies, and the author or an administrator moves it to `resolved` or
 * `closed` (and back). Only `closed` locks replies — resolved threads
 * still accept follow-ups; a new reply there does not rewind the state.
 */
final class ThreadStatus
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const ALL = [self::OPEN, self::ANSWERED, self::RESOLVED, self::CLOSED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function locksReplies(string $status): bool
    {
        return $status === self::CLOSED;
    }

    /**
     * The status a thread moves to when a reply lands: only an untouched
     * `open` thread becomes `answered`; every other state is sticky.
     */
    public static function afterReply(string $current, int $replierId, int $threadAuthorId): string
    {
        if ($current === self::OPEN && $replierId !== $threadAuthorId) {
            return self::ANSWERED;
        }

        return $current;
    }
}
