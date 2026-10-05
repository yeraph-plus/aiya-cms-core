<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Metadata\TermBox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TermBoxTest extends TestCase
{
    public function testIdIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A term box id is required.');
        TermBox::fromArray([]);
    }

    public function testTaxonomiesAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The term box "tone" needs at least one taxonomy.');
        TermBox::fromArray(['id' => 'tone', 'taxonomies' => [], 'fields' => [['id' => 'f', 'type' => 'text']]]);
    }

    public function testTaxonomiesAreSanitizedAndPruned(): void
    {
        $box = TermBox::fromArray([
            'id' => 'tone',
            'taxonomies' => ['Resource_Category', 'UI!', ''],
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame(['resource_category', 'ui'], $box->taxonomies());
    }

    public function testFieldsAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The term box "tone" needs at least one field.');
        TermBox::fromArray(['id' => 'tone', 'taxonomies' => ['category']]);
    }

    public function testNonArrayFieldEntriesAreDropped(): void
    {
        $box = TermBox::fromArray([
            'id' => 'tone',
            'taxonomies' => ['category'],
            'fields' => [
                ['id' => 'accent', 'type' => 'color'],
                'junk',
            ],
        ]);

        self::assertCount(1, $box->fields());
        self::assertSame('accent', $box->fields()[0]->id());
    }

    public function testDefaultsPinTitleAndDescription(): void
    {
        $box = TermBox::fromArray([
            'id' => 'tone',
            'taxonomies' => ['category'],
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);

        self::assertSame('tone', $box->title());
        self::assertSame('', $box->description());

        $named = TermBox::fromArray([
            'id' => 'tone',
            'title' => '色调',
            'description' => '术语附加色',
            'taxonomies' => ['category'],
            'fields' => [['id' => 'f', 'type' => 'text']],
        ]);
        self::assertSame('色调', $named->title());
        self::assertSame('术语附加色', $named->description());
    }
}
