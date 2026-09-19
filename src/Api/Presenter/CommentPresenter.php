<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

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
        'img' => ['src' => true, 'alt' => true, 'class' => true, 'loading' => true],
    ];

    public function __construct(private readonly SmiliesRenderer $smilies)
    {
    }

    /** @return array<string, mixed> */
    public function present(WP_Comment $comment): array
    {
        $authorId = (int) $comment->user_id;
        $avatar = get_avatar_url($authorId > 0 ? $authorId : (string) $comment->comment_author_email, ['size' => 64]);

        $publishedAt = mysql2date('c', (string) $comment->comment_date, false);

        return [
            'id' => (int) $comment->comment_ID,
            'parentId' => (int) $comment->comment_parent > 0 ? (int) $comment->comment_parent : null,
            'author' => [
                'id' => $authorId,
                'name' => (string) $comment->comment_author,
                'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
            ],
            'body' => (string) $comment->comment_content,
            // Storage carries kses'd restricted HTML (legacy rows are the
            // plain text they always were); the read re-runs the whitelist
            // and lets the renderer inject exactly its whitelisted smilies
            // imgs — the state machine only touches text nodes. `body`
            // stays the source form.
            'bodyHtml' => $this->smilies->render(wp_kses((string) $comment->comment_content, self::ALLOWED_TAGS)),
            'publishedAt' => is_string($publishedAt) ? $publishedAt : '',
        ];
    }
}
