<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\PostTypeDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PostTypeDefinitionTest extends TestCase
{
    public function testSlugIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A post type slug is required.');
        PostTypeDefinition::fromArray([]);
    }

    public function testSlugIsSanitizedToLowercaseKeys(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'Resource-V2']);

        self::assertSame('resource-v2', $definition->slug());
    }

    public function testDefaultArgsPinTheHeadlessShape(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource']);

        self::assertSame([
            'labels' => ['name' => 'resource', 'singular_name' => 'resource', 'menu_name' => 'resource'],
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'rest_base' => 'resource',
            'query_var' => true,
            'rewrite' => ['slug' => 'resource', 'with_front' => true],
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'menu_icon' => 'dashicons-admin-post',
            'supports' => ['title', 'editor', 'author', 'thumbnail', 'custom-fields'],
        ], $definition->args());
    }

    public function testCustomDeclarationCarriesThroughAndPublicFalseKillsRewriteAndArchive(): void
    {
        $definition = PostTypeDefinition::fromArray([
            'slug' => 'Resource',
            'label' => '资源',
            'icon' => 'dashicons-archive',
            'public' => false,
            'has_archive' => true,
            'hierarchical' => true,
            'show_in_rest' => false,
            'menu_position' => '7',
            'supports' => ['title', '', 5],
        ]);

        self::assertSame([
            'labels' => ['name' => '资源', 'singular_name' => '资源', 'menu_name' => '资源'],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'rest_base' => 'resource',
            'query_var' => true,
            // 非公开类型不产生重写规则，归档也随之熄灭（与 public 联动）
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => true,
            'menu_position' => 7,
            'menu_icon' => 'dashicons-archive',
            'supports' => ['title', '5'],
        ], $definition->args());
    }

    public function testRewriteSlugOverridesTheUrlSlugAndFrontPrefix(): void
    {
        $definition = PostTypeDefinition::fromArray([
            'slug' => 'resource',
            'rewrite_slug' => 'Resources-Archive',
            'rewrite_with_front' => false,
        ]);

        self::assertSame(['slug' => 'resources-archive', 'with_front' => false], $definition->args()['rewrite']);
    }

    public function testEmptyRewriteSlugDropsTheRewrite(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource', 'rewrite_slug' => '///']);

        self::assertFalse($definition->args()['rewrite']);
    }

    public function testSupportsListIsStringCastPrunedAndReindexed(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource', 'supports' => ['title', '', 5]]);

        self::assertSame(['title', '5'], $definition->args()['supports']);
    }

    public function testNonArraySupportsFallsBackToTheDefaultFive(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource', 'supports' => 'title']);

        self::assertSame(['title', 'editor', 'author', 'thumbnail', 'custom-fields'], $definition->args()['supports']);
    }

    public function testLabelFallsBackToTheSlug(): void
    {
        $definition = PostTypeDefinition::fromArray(['slug' => 'resource']);

        self::assertSame('resource', $definition->args()['labels']['name']);
    }
}
