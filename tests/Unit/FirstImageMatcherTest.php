<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\FirstImageMatcher;
use PHPUnit\Framework\TestCase;

final class FirstImageMatcherTest extends TestCase
{
    private FirstImageMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FirstImageMatcher();
    }

    public function testFindsFirstImageWithDoubleQuotes(): void
    {
        $html = '<p>text</p><img src="/2026/09/first.webp" alt="a"><img src="/2026/09/second.webp" alt="b">';

        self::assertSame('/2026/09/first.webp', $this->matcher->first($html));
        self::assertSame(['/2026/09/first.webp', '/2026/09/second.webp'], $this->matcher->all($html));
    }

    public function testFindsFirstImageWithSingleQuotesAndAttributes(): void
    {
        $html = "<figure><img class='size-full' src='/2026/01/pic.jpg' width='10'></figure>";

        self::assertSame('/2026/01/pic.jpg', $this->matcher->first($html));
    }

    public function testReturnsNullWithoutImages(): void
    {
        self::assertNull($this->matcher->first('<p>only text</p>'));
        self::assertNull($this->matcher->first(''));
        self::assertSame([], $this->matcher->all('<p>only text</p>'));
    }

    public function testFirstImageBeforeParagraphText(): void
    {
        $html = '<img src="https://example.com/a.png">and text';

        self::assertSame('https://example.com/a.png', $this->matcher->first($html));
    }
}
