<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\Typesetting\ChineseTypesetting;
use Aiya\Core\Domain\Content\ContentFormatter;
use PHPUnit\Framework\TestCase;

final class ContentFormatterTest extends TestCase
{
    /** @return array{content: string, title: string} */
    private function legacyPost(): array
    {
        return [
            'content' => '<div class="wrap"><p>中文English混排。。</p><p>　</p><span>内联</span><strong><strong>加粗</strong></strong></div>',
            'title' => '标题。。',
        ];
    }

    public function testCleanupHtmlNormalizesLegacyMarkup(): void
    {
        $post = $this->legacyPost();

        $clean = ContentFormatter::cleanupHtml($post['content']);

        self::assertSame('<p><p>中文English混排。。</p><p></p>内联<strong>加粗</strong></p>', $clean);
    }

    public function testCleanupHtmlRemovesFullwidthSpacesAndNbsp(): void
    {
        $clean = ContentFormatter::cleanupHtml('<p>　你好&nbsp;世界　</p>');

        self::assertSame('<p>你好世界</p>', $clean);
    }

    public function testMatchTagNamesKeepsOnlyNamesPresentInTheContent(): void
    {
        $matched = ContentFormatter::matchTagNames('这篇讲 WordPress 和 AIYA Core', ['WordPress', 'Linux', 'AIYA Core']);

        self::assertSame(['WordPress', 'AIYA Core'], $matched);
    }

    public function testChineseTypesettingFixesPunctuation(): void
    {
        $typesetting = new ChineseTypesetting();

        $fixed = $typesetting->correct('他说的对。。', ['fixPunctuation']);

        self::assertSame('他说的对。', $fixed);
    }

    public function testChineseTypesettingInsertsSpaceBetweenCjkAndLatin(): void
    {
        $typesetting = new ChineseTypesetting();

        $fixed = $typesetting->correct('学习WordPress开发', ['insertSpace']);

        self::assertSame('学习 WordPress 开发', $fixed);
    }

    public function testCorrectIgnoresUnknownMethods(): void
    {
        $typesetting = new ChineseTypesetting();

        $fixed = $typesetting->correct('他说的对。。', ['totally_unknown']);

        self::assertSame('他说的对。。', $fixed);
    }
}
