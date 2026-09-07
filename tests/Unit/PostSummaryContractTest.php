<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\PostMetrics;
use Aiya\Core\Api\Contract\PostSummary;
use Aiya\Core\Api\Contract\Term;
use PHPUnit\Framework\TestCase;

final class PostSummaryContractTest extends TestCase
{
    public function testSerializesTheListProjectionInCamelCase(): void
    {
        $summary = new PostSummary(
            42,
            'hello-world',
            '/posts/42/',
            'post',
            '你好，世界',
            'Excerpt text',
            '2026-09-06T08:00:00+08:00',
            '2026-09-06T09:00:00+08:00',
            4,
            new Image('https://wp.example/cover.jpg', 'Cover', 1280, 720),
            new Author(7, '夜行玩家', new Image('https://wp.example/a.jpg', '夜行玩家', null, null)),
            [new Term(1, 'category', 'notes', '玩家创作', 'desc', null, 5)],
            [new Term(2, 'tag', 'life', '日常', '', null, 2)],
            new PostMetrics(1200, 38, 6)
        );

        self::assertSame([
            'id' => 42,
            'slug' => 'hello-world',
            'url' => '/posts/42/',
            'type' => 'post',
            'title' => '你好，世界',
            'excerpt' => 'Excerpt text',
            'publishedAt' => '2026-09-06T08:00:00+08:00',
            'updatedAt' => '2026-09-06T09:00:00+08:00',
            'readingMinutes' => 4,
            'thumbnail' => ['url' => 'https://wp.example/cover.jpg', 'alt' => 'Cover', 'width' => 1280, 'height' => 720],
            'author' => [
                'id' => 7,
                'name' => '夜行玩家',
                'avatar' => ['url' => 'https://wp.example/a.jpg', 'alt' => '夜行玩家', 'width' => null, 'height' => null],
            ],
            'categories' => [['id' => 1, 'taxonomy' => 'category', 'slug' => 'notes', 'name' => '玩家创作', 'description' => 'desc', 'parentId' => null, 'count' => 5]],
            'tags' => [['id' => 2, 'taxonomy' => 'tag', 'slug' => 'life', 'name' => '日常', 'description' => '', 'parentId' => null, 'count' => 2]],
            'metrics' => ['views' => 1200, 'likes' => 38, 'comments' => 6],
        ], $summary->toArray());
    }
}
