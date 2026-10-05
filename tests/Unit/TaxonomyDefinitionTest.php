<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\TaxonomyDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TaxonomyDefinitionTest extends TestCase
{
    public function testSlugIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A taxonomy slug is required.');
        TaxonomyDefinition::fromArray(['post_types' => ['post']]);
    }

    public function testPostTypeListIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The taxonomy "genre" needs at least one post type.');
        TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => []]);
    }

    public function testPostTypesAreStringCastPrunedAndKeptVerbatim(): void
    {
        // post_types 只做强转与剔除，不清洗——名称匹配是 WP 侧的事
        $definition = TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => ['Resource_Category', '', 5]]);

        self::assertSame(['Resource_Category', '5'], $definition->postTypes());
    }

    public function testDefaultArgsPinTheApiSurface(): void
    {
        $definition = TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => ['resource']]);

        self::assertSame([
            'labels' => ['name' => 'genre', 'singular_name' => 'genre', 'menu_name' => 'genre'],
            'hierarchical' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rest_base' => 'genre',
            'query_var' => true,
            'rewrite' => ['slug' => 'genre'],
        ], $definition->args());
    }

    public function testCustomFlagsCarryThrough(): void
    {
        $definition = TaxonomyDefinition::fromArray([
            'slug' => 'genre',
            'label' => '流派',
            'post_types' => ['resource'],
            'hierarchical' => true,
            'show_admin_column' => false,
            'rewrite_slug' => 'Genres',
        ]);

        self::assertSame([
            'labels' => ['name' => '流派', 'singular_name' => '流派', 'menu_name' => '流派'],
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => false,
            'rest_base' => 'genre',
            'query_var' => true,
            'rewrite' => ['slug' => 'genres'],
        ], $definition->args());
    }

    public function testRestSurfaceStaysDeterministicWhateverTheCallerSends(): void
    {
        // show_in_rest 无输入缝、rest_base 恒取 slug：REST 面不随声明漂移
        $definition = TaxonomyDefinition::fromArray([
            'slug' => 'genre',
            'post_types' => ['resource'],
            'show_in_rest' => false,
            'rest_base' => 'hijack',
        ]);

        self::assertTrue($definition->args()['show_in_rest']);
        self::assertSame('genre', $definition->args()['rest_base']);
    }

    public function testEmptyRewriteSlugDropsTheRewrite(): void
    {
        $definition = TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => ['resource'], 'rewrite_slug' => '!!!']);

        self::assertFalse($definition->args()['rewrite']);
    }

    public function testLabelFallsBackToTheSlug(): void
    {
        $definition = TaxonomyDefinition::fromArray(['slug' => 'genre', 'post_types' => ['resource']]);

        self::assertSame('genre', $definition->args()['labels']['name']);
    }
}
