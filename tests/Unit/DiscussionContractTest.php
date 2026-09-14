<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Discussion;
use Aiya\Core\Api\Contract\DiscussionBoard;
use Aiya\Core\Api\Contract\DiscussionDetail;
use Aiya\Core\Api\Contract\DiscussionReply;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\PostRef;
use PHPUnit\Framework\TestCase;

final class DiscussionContractTest extends TestCase
{
    private function thread(): Discussion
    {
        return new Discussion(
            42,
            '/community/42/',
            '资源下载失败',
            new DiscussionBoard(2, 'question', '问答'),
            'open',
            new Author(7, 'zhan-zhang', '站长', null),
            new PostRef(12, 'resource', '某资源', '/resources/12/'),
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
        self::assertSame('/community/42/', $shape['url']);
        self::assertSame(['id' => 2, 'slug' => 'question', 'name' => '问答', 'description' => '', 'threads' => 0], $shape['board']);
        self::assertSame(['下载', 'AVIF'], $shape['tags']);
        self::assertSame([['url' => 'https://cdn.example.test/shot.webp', 'alt' => '', 'width' => 800, 'height' => 600]], $shape['images']);
        self::assertSame('open', $shape['status']);
        self::assertSame(['id' => 7, 'slug' => 'zhan-zhang', 'name' => '站长', 'avatar' => null], $shape['author']);
        self::assertSame(
            ['id' => 12, 'type' => 'resource', 'title' => '某资源', 'url' => '/resources/12/'],
            $shape['postRef']
        );
        self::assertSame(3, $shape['replies']);
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

    public function testStandaloneThreadHasNullPostRef(): void
    {
        $thread = new Discussion(
            1, '/community/1/', '问个问题', null, 'closed',
            new Author(1, 'a', 'a', null), null, 0, [], [], '', '2026-09-09T00:00:00+08:00',
            false, false, false,
        );

        $shape = $thread->toArray();

        self::assertNull($shape['postRef']);
        self::assertNull($shape['board']);
        self::assertSame([], $shape['tags']);
        self::assertSame([], $shape['images']);
        self::assertSame('', $shape['lastReplyAt']);
        self::assertFalse($shape['canEdit']);
        self::assertFalse($shape['canReply'], 'closed threads refuse replies');
    }
}
