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

    /** The public type a WP post type belongs to, or null when none does. */
    public static function forPostType(string $wpType): ?PublicType
    {
        foreach (self::all() as $type) {
            if (in_array($wpType, $type->postTypes, true)) {
                return $type;
            }
        }

        return null;
    }

    /** Every WP post type the public surface reads, flattened.
     *
     * @return list<string>
     */
    public static function wpPostTypes(): array
    {
        $types = [];
        foreach (self::all() as $type) {
            foreach ($type->postTypes as $wpType) {
                $types[] = $wpType;
            }
        }

        return $types;
    }

    /** @return array<string, PublicType> */
    public static function all(): array
    {
        return [
            'post' => new PublicType(
                'post',
                ['post'],
                '/posts/%s/',
                [['category', 'category'], ['post_tag', 'tag']],
                'category'
            ),
            'page' => new PublicType(
                'page',
                ['page'],
                '/pages/%s/',
                [['page_category', 'category']],
                'page_category'
            ),
            'resource' => new PublicType(
                'resource',
                ['resource'],
                '/resources/%s/',
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
