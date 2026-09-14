<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\DiscussionContent;
use PHPUnit\Framework\TestCase;

final class DiscussionContentTest extends TestCase
{
    public function testExtractsImagesInDocumentOrderWithDimensions(): void
    {
        $html = '<p>看图</p><img src="https://cdn.test/a.webp" width="800" height="600" alt="">'
            . '<img src="https://cdn.test/b.png">';

        $images = DiscussionContent::images($html);

        self::assertCount(2, $images);
        self::assertSame(['url' => 'https://cdn.test/a.webp', 'width' => 800, 'height' => 600], $images[0]);
        self::assertSame(['url' => 'https://cdn.test/b.png', 'width' => 0, 'height' => 0], $images[1]);
        self::assertSame(2, DiscussionContent::imageCount($html));
    }

    public function testDuplicateImageSourcesCollapseToOneEntry(): void
    {
        $html = '<img src="https://cdn.test/a.webp"><img src="https://cdn.test/a.webp">';

        self::assertCount(1, DiscussionContent::images($html));
    }

    public function testClosedHashTagsSupportCjkAndSpaces(): void
    {
        $tags = DiscussionContent::tags('<p>今天的 #AIYA 动态# 和 #周末修复# 记录</p>');

        self::assertSame(['AIYA 动态', '周末修复'], $tags);
    }

    public function testAnUnclosedHashStaysLiteralText(): void
    {
        $tags = DiscussionContent::tags('<p>#release 修复了 #bug_42，顺便聊聊</p>');

        self::assertSame(['release 修复了'], $tags);
    }

    public function testTagsDeduplicateKeepingFirstOrder(): void
    {
        $tags = DiscussionContent::tags('<p>#alpha# 然后还有一个 #beta#，最后重复 #alpha#</p>');

        self::assertSame(['alpha', 'beta'], $tags);
    }

    public function testUrlFragmentsInsideAttributesNeverBecomeTags(): void
    {
        $tags = DiscussionContent::tags('<a href="https://aiya.test/page/#section">跳转</a>');

        self::assertSame([], $tags);
    }

    public function testPlainBodyHasNoExtractions(): void
    {
        self::assertSame([], DiscussionContent::images('<p>纯文本</p>'));
        self::assertSame([], DiscussionContent::tags('<p>纯文本</p>'));
        self::assertSame(0, DiscussionContent::imageCount(''));
    }
}
