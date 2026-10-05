<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\DiscussionContent;
use PHPUnit\Framework\TestCase;
use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Discussion;
use Aiya\Core\Api\Contract\DiscussionBoard;
use Aiya\Core\Api\Contract\DiscussionDetail;
use Aiya\Core\Api\Contract\DiscussionReply;
use Aiya\Core\Api\Contract\Image;

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

    private function thread(): Discussion
    {
        return new Discussion(
            42,
            '资源下载失败',
            new DiscussionBoard(2, 'question', '问答'),
            'open',
            new Author(7, 'zhan-zhang', '站长', null),
            3,
            ['下载', 'AVIF'],
            [new Image('https://cdn.example.test/shot.webp', '', 800, 600)],
            '2026-09-09T08:00:00+08:00',
            '2026-09-09T07:00:00+08:00',
            true,
            true,
            true,
        );
    }

    public function testThreadSerializesToTheContractShape(): void
    {
        $shape = $this->thread()->toArray();

        self::assertSame(42, $shape['id']);
        self::assertSame(['id' => 2, 'slug' => 'question', 'name' => '问答', 'description' => '', 'threads' => 0], $shape['board']);
        self::assertSame(['下载', 'AVIF'], $shape['tags']);
        self::assertSame([['url' => 'https://cdn.example.test/shot.webp', 'alt' => '', 'width' => 800, 'height' => 600]], $shape['images']);
        self::assertSame('open', $shape['status']);
        self::assertSame(['id' => 7, 'slug' => 'zhan-zhang', 'name' => '站长', 'avatar' => null], $shape['author']);
        self::assertSame(3, $shape['replies']);
        self::assertSame(0, $shape['likes'], 'the like count defaults without a constructor arg');
        self::assertFalse($shape['viewerLiked']);
        self::assertTrue($shape['canReply']);
    }

    public function testDetailMergesBodyAndRepliesOntoTheThreadShape(): void
    {
        $detail = new DiscussionDetail(
            $this->thread(),
            '<p>下载报错 403。</p>',
            [new DiscussionReply(9, new Author(8, 'lu-ren', '路人', null), '<p>试试换浏览器。</p>', [], '2026-09-09T07:30:00+08:00', false)],
        );

        $shape = $detail->toArray();

        self::assertSame(['format' => 'html', 'html' => '<p>下载报错 403。</p>'], $shape['content']);
        self::assertCount(1, $shape['replies']);
        self::assertSame(
            ['id' => 9, 'author' => ['id' => 8, 'slug' => 'lu-ren', 'name' => '路人', 'avatar' => null], 'content' => '<p>试试换浏览器。</p>', 'images' => [], 'publishedAt' => '2026-09-09T07:30:00+08:00', 'canDelete' => false],
            $shape['replies'][0],
        );
        self::assertSame('open', $shape['status'], 'thread fields carry through the detail');
    }

    public function testStandaloneThreadKeepsTheEmptyShape(): void
    {
        $thread = new Discussion(
            1, '问个问题', null, 'closed',
            new Author(1, 'a', 'a', null), 0, [], [], '', '2026-09-09T00:00:00+08:00',
            false, false, false, '', 4, true,
        );

        $shape = $thread->toArray();

        self::assertArrayNotHasKey('postRef', $shape, 'the bound post is a rendered card now, not a field');
        self::assertNull($shape['board']);
        self::assertSame([], $shape['tags']);
        self::assertSame([], $shape['images']);
        self::assertSame('', $shape['lastReplyAt']);
        self::assertFalse($shape['canEdit']);
        self::assertFalse($shape['canReply'], 'closed threads refuse replies');
        self::assertSame(4, $thread->likes);
        self::assertTrue($thread->viewerLiked);
    }
}
