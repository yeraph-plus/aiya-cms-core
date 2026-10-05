<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentTypeRegistry;
use Aiya\Core\Domain\Content\PostTypeDefinition;
use Aiya\Core\Domain\Content\TaxonomyDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentTypeRegistryTest extends TestCase
{
    public function testAddPostTypeAcceptsADefinitionObjectAndListsItBack(): void
    {
        $registry = new ContentTypeRegistry();
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource']);

        self::assertSame($definition, $registry->addPostType($definition));
        self::assertSame([$definition], $registry->postTypes());
    }

    public function testAddPostTypeNormalizesAnArrayDefinition(): void
    {
        $registry = new ContentTypeRegistry();

        $definition = $registry->addPostType(['slug' => 'Resource']);

        self::assertInstanceOf(PostTypeDefinition::class, $definition);
        self::assertSame('resource', $definition->slug());
        self::assertSame([$definition], $registry->postTypes());
    }

    public function testDuplicatePostTypeSlugIsRejectedWhateverTheInputShape(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->addPostType(['slug' => 'resource']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Post type "resource" is already registered.');
        $registry->addPostType(PostTypeDefinition::fromArray(['slug' => 'resource']));
    }

    public function testAddTaxonomyAcceptsADefinitionObjectAndListsItBack(): void
    {
        $registry = new ContentTypeRegistry();
        $definition = TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => ['resource']]);

        self::assertSame($definition, $registry->addTaxonomy($definition));
        self::assertSame([$definition], $registry->taxonomies());
    }

    public function testAddTaxonomyNormalizesAnArrayDefinition(): void
    {
        $registry = new ContentTypeRegistry();

        $definition = $registry->addTaxonomy(['slug' => 'genre', 'post_types' => ['resource']]);

        self::assertInstanceOf(TaxonomyDefinition::class, $definition);
        self::assertSame('genre', $definition->slug());
        self::assertSame([$definition], $registry->taxonomies());
    }

    public function testDuplicateTaxonomySlugIsRejected(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->addTaxonomy(['slug' => 'genre', 'post_types' => ['resource']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Taxonomy "genre" is already registered.');
        $registry->addTaxonomy(['slug' => 'genre', 'post_types' => ['post']]);
    }

    public function testPostTypeAndTaxonomySlugSpacesAreIndependent(): void
    {
        $registry = new ContentTypeRegistry();
        $registry->addPostType(['slug' => 'topic']);

        $taxonomy = $registry->addTaxonomy(['slug' => 'topic', 'post_types' => ['topic']]);

        self::assertSame('topic', $taxonomy->slug());
        self::assertCount(1, $registry->postTypes());
        self::assertCount(1, $registry->taxonomies());
    }

    public function testListingsKeepInsertionOrderAsSequentialLists(): void
    {
        $registry = new ContentTypeRegistry();
        $first = $registry->addPostType(['slug' => 'aa']);
        $second = $registry->addPostType(['slug' => 'bb']);
        $taxFirst = $registry->addTaxonomy(['slug' => 'genre', 'post_types' => ['aa']]);
        $taxSecond = $registry->addTaxonomy(['slug' => 'mood', 'post_types' => ['aa']]);

        self::assertSame([$first, $second], $registry->postTypes());
        self::assertSame([$taxFirst, $taxSecond], $registry->taxonomies());
    }

    public function testEmptyRegistryAnswersEmptyLists(): void
    {
        $registry = new ContentTypeRegistry();

        self::assertSame([], $registry->postTypes());
        self::assertSame([], $registry->taxonomies());
    }
}
