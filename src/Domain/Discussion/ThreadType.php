<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

/**
 * Thread type vocabulary (2026-09-09 contract): three content-nature labels
 * for the list tabs. The legacy `issue` value was dropped on purpose — the
 * ticket semantics live in the postRef binding, not in the type.
 */
final class ThreadType
{
    public const DISCUSSION = 'discussion';
    public const QUESTION = 'question';
    public const FEEDBACK = 'feedback';

    /** @var list<string> */
    public const ALL = [self::DISCUSSION, self::QUESTION, self::FEEDBACK];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
