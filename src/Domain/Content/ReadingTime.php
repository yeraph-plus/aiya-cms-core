<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

/**
 * Front-end reading-time estimate shared by every content projection:
 * CJK characters at 400/min plus latin words at 200/min, rounded up,
 * never below one minute. Pure function, locked by unit tests (D7).
 */
final class ReadingTime
{
    public const CJK_PER_MINUTE = 400;
    public const WORDS_PER_MINUTE = 200;

    public static function estimate(string $html): int
    {
        $text = trim((string) preg_replace('#<[^>]+>#', ' ', $html));

        if ($text === '') {
            return 1;
        }

        $cjk = (int) preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text);
        $latin = str_word_count((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));

        return max(1, (int) ceil($cjk / self::CJK_PER_MINUTE + $latin / self::WORDS_PER_MINUTE));
    }
}
