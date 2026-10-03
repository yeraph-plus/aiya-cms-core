<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Comment;
use Aiya\Core\Api\Contract\CommentAuthor;
use Aiya\Core\Domain\Content\Mentions;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use WP_Comment;

/**
 * Projects WP_Comment rows into the comment wire shape. The kses
 * whitelist here is the comment body's contract: it runs at write (the
 * controller sanitizes the incoming body through it) and again on read
 * (idempotent, and it keeps legacy plain-text rows on the same path);
 * the front end runs its own sanitizer on top as defense in depth.
 */
final class CommentPresenter
{
    /**
     * Rich-comment whitelist (2026-09-17 batch): comment bodies ride as
     * restricted HTML so the shared tiptap editor and its uploaded images
     * survive storage.
     */
    public const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'blockquote' => [],
        'code' => [],
        'span' => ['data-spoiler' => true],
        // class carries exactly one value, via kses's own `values` rule (an
        // attribute spec array is a rule table — maxlen/maxval/values —, the
        // rule parameter a plain value list): the smilies renderer's class,
        // and nothing else, so a comment cannot borrow arbitrary site styles.
        'img' => ['src' => true, 'alt' => true, 'class' => ['values' => [SmiliesRenderer::IMG_CLASS]], 'loading' => true],
    ];

    public function __construct(
        private readonly SmiliesRenderer $smilies,
        private readonly ?Mentions $mentions = null,
    ) {
    }

    /** The kses-clean body with mention anchors injected (renderer-side
     * only — stored content stays anchor-free). */
    private function bodySource(WP_Comment $comment): string
    {
        $clean = wp_kses((string) $comment->comment_content, self::ALLOWED_TAGS);

        return $this->mentions?->linkify($clean) ?? $clean;
    }

    /** @return array<string, mixed> the contract-ready comment shape */
    public function present(WP_Comment $comment): array
    {
        $authorId = (int) $comment->user_id;
        $avatar = get_avatar_url($authorId > 0 ? $authorId : (string) $comment->comment_author_email, ['size' => 64]);

        $publishedAt = mysql2date('c', (string) $comment->comment_date, false);

        return (new Comment(
            (int) $comment->comment_ID,
            (int) $comment->comment_parent > 0 ? (int) $comment->comment_parent : null,
            new CommentAuthor(
                $authorId,
                (string) $comment->comment_author,
                is_string($avatar) && $avatar !== '' ? $avatar : null
            ),
            (string) $comment->comment_content,
            // Storage carries kses'd restricted HTML (legacy rows are the
            // plain text they always were); the read re-runs the whitelist
            // and lets the renderer inject exactly its whitelisted smilies
            // imgs — the state machine only touches text nodes. `body`
            // stays the source form.
            // Mentions resolve against the kses-clean body (the anchor is
            // renderer-injected, never stored), then smilies ride on top.
            $this->smilies->render($this->bodySource($comment)),
            is_string($publishedAt) ? $publishedAt : '',
        ))->toArray();
    }
}
