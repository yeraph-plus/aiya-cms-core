<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

/**
 * Registry of the public types and their contract names.
 */
final class PublicTypes
{
    public static function get(string $name): ?PublicType
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, PublicType> */
    public static function all(): array
    {
        return [
            'post' => new PublicType(
                'post',
                ['post'],
                '/posts/%d/',
                [['category', 'category'], ['post_tag', 'tag']],
                'category'
            ),
            'page' => new PublicType(
                'page',
                ['page'],
                '/pages/%d/',
                [['page_category', 'category']],
                'page_category'
            ),
            'resource' => new PublicType(
                'resource',
                ['resource'],
                '/resources/%d/',
                [
                    ['resource_category', 'category'],
                    ['resource_original', 'tag'],
                    ['resource_character', 'tag'],
                    ['resource_author', 'tag'],
                    ['resource_content', 'tag'],
                    ['resource_other', 'tag'],
                ],
                'resource_category'
            ),
        ];
    }
}
