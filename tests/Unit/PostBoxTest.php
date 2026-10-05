<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Metadata\PostBox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostBoxTest extends TestCase
{
    public function testIdIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A post box id is required.');
        PostBox::fromArray(['fields' => [['id' => 'f', 'type' => 'text']]]);
    }

    public function testFieldsAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The post box "links" needs at least one field.');
        PostBox::fromArray(['id' => 'links', 'fields' => []]);
    }

    public function testFieldEntriesDelegateTheirValidationToTheFieldSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A field id must contain only letters, numbers, dots, dashes and underscores.');
        PostBox::fromArray(['id' => 'links', 'fields' => [['id' => 'BAD ID', 'type' => 'text']]]);
    }

    public function testNonArrayEntriesAreDroppedFromTheFieldList(): void
    {
        $box = PostBox::fromArray([
            'id' => 'links',
            'fields' => [
                ['id' => 'mirror', 'type' => 'url', 'label' => '镜像'],
                'junk',
                42,
            ],
        ]);

        self::assertCount(1, $box->fields());
        self::assertSame('mirror', $box->fields()[0]->id());
        self::assertSame('url', $box->fields()[0]->type());
    }

    public function testDefaultsPinThePostScreenWithNormalContext(): void
    {
        $box = PostBox::fromArray(['id' => 'typography', 'fields' => [['id' => 'font', 'type' => 'text']]]);

        self::assertSame('typography', $box->title());
        self::assertSame(['post'], $box->screens());
        self::assertSame('normal', $box->context());
        self::assertSame('default', $box->priority());
        self::assertNull($box->template());
        self::assertSame('', $box->description());
        self::assertSame('aiya_core_typography', $box->metaKey());
    }

    public function testScreensAreStringCastAndPruned(): void
    {
        $box = PostBox::fromArray([
            'id' => 'links',
            'screens' => ['resource', 5, ''],
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame(['resource', '5'], $box->screens());
    }

    public function testEmptyScreenListFallsBackToThePostScreen(): void
    {
        $box = PostBox::fromArray([
            'id' => 'links',
            'screens' => [''],
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame(['post'], $box->screens());
    }

    public function testInvalidContextAndPriorityFallBackToNormalAndDefault(): void
    {
        $box = PostBox::fromArray([
            'id' => 'links',
            'context' => 'floating',
            'priority' => 'urgent',
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame('normal', $box->context());
        self::assertSame('default', $box->priority());
    }

    public function testExplicitTitleTemplateAndDescriptionCarryThrough(): void
    {
        $box = PostBox::fromArray([
            'id' => 'links',
            'title' => '下载链接',
            'template' => 'template-parts/download.php',
            'description' => '外部下载地址',
            'context' => 'side',
            'priority' => 'high',
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame('下载链接', $box->title());
        self::assertSame('template-parts/download.php', $box->template());
        self::assertSame('外部下载地址', $box->description());
        self::assertSame('side', $box->context());
        self::assertSame('high', $box->priority());
    }

    public function testIdIsSanitizedIntoTheGroupMetaKey(): void
    {
        $box = PostBox::fromArray([
            'id' => 'Download Links!',
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame('downloadlinks', $box->id());
        self::assertSame('aiya_core_downloadlinks', $box->metaKey());
    }
}
