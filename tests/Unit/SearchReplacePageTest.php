<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\SearchReplacePage;
use PHPUnit\Framework\TestCase;

/**
 * The snippet rendering on the search & replace page: the first match is
 * highlighted inside a padded window, and every fragment escapes before
 * assembly so the output prints without a kses pass.
 */
final class SearchReplacePageTest extends TestCase
{
    public function testSnippetHighlightsTheFirstMatchWithEllipses(): void
    {
        $haystack = str_repeat('前', 70) . '目标串' . str_repeat('后', 70);

        $out = SearchReplacePage::snippet($haystack, '目标串', 10);

        self::assertStringStartsWith('…', $out);
        self::assertStringEndsWith('…', $out);
        self::assertSame(1, substr_count($out, '<mark>目标串</mark>'));
        self::assertStringNotContainsString($haystack, $out);
    }

    public function testSnippetInsideThePaddingShowsNoEllipses(): void
    {
        $out = SearchReplacePage::snippet('短文本中的目标串与周围', '目标串', 60);

        self::assertSame('短文本中的<mark>目标串</mark>与周围', $out);
    }

    public function testSnippetEscapesHtmlInTheFragments(): void
    {
        $out = SearchReplacePage::snippet('before <script>alert(1)</script> 目标 after', '目标');

        self::assertSame('before &lt;script&gt;alert(1)&lt;/script&gt; <mark>目标</mark> after', $out);
    }

    public function testSnippetHandlesMissAndEmptyNeedle(): void
    {
        self::assertSame('', SearchReplacePage::snippet('nothing here', '缺失'));
        self::assertSame('', SearchReplacePage::snippet('anything', ''));
    }
}
