<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use WP_Post;

/**
 * Bulk "switch post type": moves rows between the public types and
 * nothing else. Category/tag relations are deliberately not migrated —
 * core's own defaults apply (a row entering `post` picks up the default
 * category; relations of taxonomies the target does not carry simply
 * stay as they are). A sticky row leaving the `post` type is unstuck —
 * stickiness only makes sense for posts and core never releases it on
 * type changes itself.
 */
final class PostTypeSwitcher
{
    /**
     * Switches a batch of posts to one target type.
     *
     * @param list<int> $ids
     * @return array{switched: int, skipped: int}
     */
    public function switchPosts(array $ids, string $targetTypeName): array
    {
        $skipped = count($ids);
        $target = PublicTypes::get($targetTypeName);
        $typeObject = $target !== null ? get_post_type_object($target->name) : null;
        if ($target === null || $typeObject === null || !current_user_can($typeObject->cap->edit_posts)) {
            return ['switched' => 0, 'skipped' => $skipped];
        }

        $switched = 0;
        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if ($post instanceof WP_Post && $this->switchPost($post, $target)) {
                ++$switched;
            }
        }

        return ['switched' => $switched, 'skipped' => $skipped - $switched];
    }

    /** Switches one post; false when skipped (unknown source, no permission, already the target, failed update). */
    public function switchPost(WP_Post $post, PublicType $target): bool
    {
        $source = PublicTypes::get((string) $post->post_type);
        if ($source === null || $source->name === $target->name) {
            return false;
        }
        if (!current_user_can('edit_post', (int) $post->ID)) {
            return false;
        }

        if (is_sticky((int) $post->ID) && $target->name !== 'post') {
            unstick_post((int) $post->ID);
        }

        $updated = wp_update_post(['ID' => (int) $post->ID, 'post_type' => $target->name], true);

        return !is_wp_error($updated);
    }
}
