<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use WP_Comment;
use WP_Comment_Query;
use WP_Post;

/**
 * Read-side WP_Comment access for the headless comment API: the public
 * commentable-post lookup and the paged approved-comment window. The
 * write side deliberately stays with the controller — wp_new_comment IS
 * the moderation pipeline being orchestrated, not a query to wrap.
 */
final class CommentQuery
{
    /** Types that may carry comments; matches the counter feature matrix. */
    private const COMMENTABLE_TYPES = ['post', 'page', 'resource'];

    public function __construct(private PostVisibility $visibility)
    {
    }

    /**
     * Resolves a public, non-protected, ungated post of a commentable
     * type; anything else reads as content that does not exist.
     *
     * The visibility gate applies to comments exactly as it does to the
     * body: a thread under a member-only post is that post's content, so
     * an unqualified viewer gets the same answer as for a missing id —
     * the gate never confirms that the post exists. Passing the gate also
     * closes writing: no comments on a post you cannot read.
     */
    public function commentablePost(int $id): ?WP_Post
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post
            || !in_array($post->post_type, self::COMMENTABLE_TYPES, true)
            || $post->post_status !== 'publish'
            || (string) $post->post_password !== ''
            || $this->visibility->gated($post)) {
            return null;
        }

        return $post;
    }

    /**
     * One approved-comment page plus the approved total for the same
     * post, so the caller can build honest pagination from one read.
     *
     * @return array{items: list<WP_Comment>, total: int}
     */
    public function page(int $postId, int $page, int $perPage, string $order): array
    {
        $countQuery = new WP_Comment_Query([
            'post_id' => $postId,
            'status' => 'approve',
            'count' => true,
        ]);
        $total = (int) ($countQuery->total_comments ?? 0);

        $query = new WP_Comment_Query([
            'post_id' => $postId,
            'status' => 'approve',
            'order' => $order === 'desc' ? 'DESC' : 'ASC',
            'number' => min(100, max(1, $perPage)),
            'paged' => max(1, $page),
        ]);

        $items = [];
        foreach (is_array($query->comments) ? $query->comments : [] as $comment) {
            if ($comment instanceof WP_Comment) {
                $items[] = $comment;
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /** One approved comment by id, for parent validation on write. */
    public function approvedById(int $commentId): ?WP_Comment
    {
        $comment = get_comment($commentId);
        if (!$comment instanceof WP_Comment || (string) $comment->comment_approved !== '1') {
            return null;
        }

        return $comment;
    }
}
