<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ReadingTime;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    public function testNeverReportsLessThanOneMinute(): void
    {
        self::assertSame(1, ReadingTime::estimate('<p></p>'));
        self::assertSame(1, ReadingTime::estimate(''));
        self::assertSame(1, ReadingTime::estimate('<p>Short.</p>'));
    }

    public function testCountsCjkCharactersAt400PerMinute(): void
    {
        // 800 CJK characters → 2 minutes.
        $cjk = str_repeat('字', 800);

        self::assertSame(2, ReadingTime::estimate('<p>' . $cjk . '</p>'));
    }

    public function testCountsLatinWordsAt200PerMinute(): void
    {
        $latin = trim(str_repeat('word ', 600));

        self::assertSame(3, ReadingTime::estimate($latin));
    }

    public function testMixesScriptsAndIgnoresMarkup(): void
    {
        // 400 CJK (1 min) + 200 words (1 min) → 2.
        $html = '<h2>标题</h2>' . str_repeat('字', 398) . '<p>' . trim(str_repeat('word ', 200)) . '</p>';

        self::assertSame(2, ReadingTime::estimate($html));
    }

    public function testRoundsPartialMinutesUp(): void
    {
        self::assertSame(1, ReadingTime::estimate(str_repeat('字', 201)));
        self::assertSame(2, ReadingTime::estimate(str_repeat('字', 401)));
        self::assertSame(2, ReadingTime::estimate(str_repeat('字', 799)));
    }
}
