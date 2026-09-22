<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Domain\Content\PublicTypes;

/**
 * The content types a file list hangs off: the public content types — post,
 * page and resource — with `aiya_core_fileserve_post_types` as the seam for a
 * type outside the public contract (or for narrowing the set on one site).
 * The metabox and both routes read the same list, so a type shows up
 * everywhere at once.
 *
 * Resolved per call rather than memoized: the filter is a seam other code may
 * extend at any point, and building the list costs three array walks.
 */
final class PostTypes
{
    /** @return list<string> */
    public static function all(): array
    {
        $types = [];
        foreach (PublicTypes::all() as $type) {
            foreach ($type->postTypes as $postType) {
                $types[$postType] = true;
            }
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('aiya_core_fileserve_post_types', array_keys($types));

        return array_values(array_filter(
            array_map('strval', (array) $filtered),
            static fn (string $postType): bool => $postType !== ''
        ));
    }

    public static function supports(string $postType): bool
    {
        return in_array($postType, self::all(), true);
    }
}
