<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use WP_Comment;
use WP_Post;

/**
 * The content workflow's event delegation: the comment/publish semantics
 * this domain owns get named do_action points so consumers (the
 * notification domain, future webhooks) hang in without listening to
 * WP's raw hooks themselves.
 *
 *  - aiya_core_comment_posted (comment_id, comment) — a comment became
 *    visible (approved insertion; held comments never fire, so the
 *    approval gate is Content's ruling, not each consumer's);
 *  - aiya_core_post_approved (post) — an editor greenlit a pending
 *    submission (article type, pending → publish; a direct draft publish
 *    is the author's own doing and never fires this);
 *  - aiya_core_post_published (post) — a piece's first publication of
 *    any type (the approval path fires this too, after post_approved);
 *  - aiya_core_post_updated (post) — an edit landed on an
 *    already-published piece.
 *
 * Pure delegation: the handlers read nothing but the WP objects the
 * hooks hand over and fire onward — no storage, no consumer knowledge.
 */
final class ContentEventsModule implements Module
{
    public function register(): void
    {
        add_action('wp_insert_comment', [$this, 'onCommentInserted'], 10, 2);
        add_action('transition_post_status', [$this, 'onPostTransition'], 10, 3);
    }

    public function onCommentInserted(int $commentId, WP_Comment $comment): void
    {
        // Held comments re-firing through wp_transition_comment_status do
        // NOT re-enter wp_insert_comment, so this gate is the only one the
        // insertion path needs.
        if ((string) $comment->comment_approved !== '1') {
            return;
        }

        do_action('aiya_core_comment_posted', $commentId, $comment);
    }

    public function onPostTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus !== 'publish') {
            return;
        }

        if ($oldStatus === 'publish') {
            do_action('aiya_core_post_updated', $post);

            return;
        }

        if ($post->post_type === 'post' && $oldStatus === 'pending') {
            do_action('aiya_core_post_approved', $post);
        }
        do_action('aiya_core_post_published', $post);
    }
}
