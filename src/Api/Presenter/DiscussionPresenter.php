<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Discussion;
use Aiya\Core\Api\Contract\DiscussionBoard;
use Aiya\Core\Api\Contract\DiscussionDetail;
use Aiya\Core\Api\Contract\DiscussionReply;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Domain\Discussion\DiscussionContent;
use Aiya\Core\Domain\Discussion\DiscussionLikeService;
use Aiya\Core\Domain\Content\Mentions;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use Aiya\Core\Domain\Parts\BuiltinParts;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;

/**
 * Maps discussion table rows onto the contract. Permission flags are
 * derived here from the acting viewer (author or `edit_pages`
 * administrator) so the front end never re-implements the rules; guests
 * read false everywhere. This is the only WP-row touchpoint of the
 * discussion read path. Smilies tokens convert on the contentHtml
 * projections only — tags() and images() parse the raw stored HTML, so a
 * rendered token never leaks into the grid extraction.
 *
 * Bodies expand registered shortcodes (template parts) and a thread bound
 * to a post gets that post's card appended at the bottom — one mechanism
 * for "this thread is about that article", rendered by the same shortcode
 * an editor would type. Both are viewer-independent, which is what makes
 * them safe inside contentHtml (a shared-cacheable payload).
 */
final class DiscussionPresenter
{
    /** Object cache group/TTL for the viewer-independent content render. */
    private const CACHE_GROUP = 'aiya_core_content';
    private const CACHE_TTL = 600;

    /**
     * Per-request render memo keyed by thread id — detail() reaches the
     * same projection through present() and again directly, and the
     * smilies + shortcode + card chain is the most expensive single piece
     * of the row.
     *
     * @var array<int, string>
     */
    private array $contentHtmlMemo = [];

    public function __construct(
        private readonly SmiliesRenderer $smilies,
        private readonly DiscussionService $threads,
        private readonly DiscussionLikeService $likes,
        private readonly ?Mentions $mentions = null,
    )
    {
    }

    /** @param object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string} $row */
    public function present(object $row, int $viewerId, ?int $likeCount = null, ?bool $viewerLiked = null): Discussion
    {
        $likeCount ??= $this->likes->counts([(int) $row->id])[(int) $row->id] ?? 0;
        $viewerLiked ??= $this->likes->has((int) $row->id, $viewerId);
        $canModerate = $this->canModerate((int) $row->user_id, $viewerId);

        return new Discussion(
            (int) $row->id,
            (string) $row->title,
            $this->board($row),
            (string) $row->status,
            $this->author((int) $row->user_id),
            (int) $row->reply_count,
            DiscussionContent::tags((string) $row->content),
            $this->images((string) $row->content),
            $this->iso((string) ($row->last_reply_at ?? '')),
            $this->iso((string) $row->created_at),
            $canModerate,
            $canModerate,
            $viewerId > 0 && !ThreadStatus::locksReplies((string) $row->status),
            $this->contentHtml($row),
            $likeCount,
            $viewerLiked,
        );
    }

    /**
     * A page of threads: the like projection batches — one counts read and
     * one viewer-liked read per page instead of two queries per row, the
     * same list-path discipline as the user cache warmup.
     *
     * @param list<object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}> $rows
     * @return list<Discussion>
     */
    public function presentAll(array $rows, int $viewerId): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $counts = $this->likes->counts($ids);
        $liked = $this->likes->likedBy($viewerId, $ids);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $out[] = $this->present($row, $viewerId, $counts[$id] ?? 0, isset($liked[$id]));
        }

        return $out;
    }

    /**
     * @param object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string} $row
     * @param list<DiscussionReply> $replies
     */
    public function detail(object $row, array $replies, int $viewerId): DiscussionDetail
    {
        return new DiscussionDetail(
            $this->present($row, $viewerId),
            $this->contentHtml($row),
            $replies
        );
    }

    /** @param object{id:int,user_id:int,content:string,created_at:string} $row */
    public function reply(object $row, int $viewerId): DiscussionReply
    {
        return new DiscussionReply(
            (int) $row->id,
            $this->author((int) $row->user_id),
            $this->bodyHtml((string) $row->content),
            $this->images((string) $row->content),
            $this->iso((string) $row->created_at),
            $this->canModerate((int) $row->user_id, $viewerId),
        );
    }

    /**
     * The public board list with thread counts, menu order preserved —
     * the service returns raw board rows, the wire shape is built here
     * like every other payload.
     *
     * @param list<object{id:int|string,slug:string,name:string,description:string,threads:int|string}> $rows
     * @return list<array<string, mixed>>
     */
    public function boards(array $rows): array
    {
        $items = [];
        foreach ($rows as $board) {
            $items[] = (new DiscussionBoard(
                (int) $board->id,
                (string) $board->slug,
                (string) $board->name,
                (string) $board->description,
                (int) $board->threads,
            ))->toArray();
        }

        return $items;
    }

    /** @param object{board_id:int,board_slug:string|null,board_name:string|null} $row */
    private function board(object $row): ?DiscussionBoard
    {
        $slug = (string) ($row->board_slug ?? '');

        return $slug === ''
            ? null
            : new DiscussionBoard((int) $row->board_id, $slug, (string) ($row->board_name ?? ''));
    }

    /** @return list<Image> */
    private function images(string $html): array
    {
        $images = [];
        foreach (DiscussionContent::images($html) as $extracted) {
            $images[] = new Image($extracted['url'], '', $extracted['width'], $extracted['height']);
        }

        return $images;
    }

    /**
     * The body as the front end receives it: smilies first (on the author's
     * text), then registered shortcodes, then — for a thread bound to a post
     * — that post's card. The card is produced by the same shortcode an
     * editor would type, so a bound thread and a hand-embedded card cannot
     * render differently.
     *
     * The projection is viewer-independent (that is what makes it safe as a
     * shared-cacheable payload), so it memoizes per request and mirrors into
     * the object cache under a content-folded key: the stored content hash
     * plus the bound post's modified time cover every input the markup
     * reads, so edits re-key the entry with no invalidation hook. The 600s
     * TTL is the freshness contract for whatever the markup bakes in from
     * request-independent state (smilies packs, part renderers) — the same
     * stance as the shell cache.
     */
    /**
     * @param object{id:int,content:string,post_id:int} $row
     */
    private function contentHtml(object $row): string
    {
        $threadId = (int) $row->id;
        if (isset($this->contentHtmlMemo[$threadId])) {
            return $this->contentHtmlMemo[$threadId];
        }

        $raw = (string) $row->content;
        $postId = (int) $row->post_id;
        $postModified = $postId > 0 ? (string) get_post_field('post_modified_gmt', $postId) : '';
        // sha256 over author-authored content: the key material is user
        // controlled, so the stronger digest costs nothing and forecloses
        // any chosen-prefix daydream against the object cache.
        $key = 'content_' . $threadId . '_' . substr(hash('sha256', $raw . '|' . $postModified), 0, 24);
        /** @var string|false $cached */
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_string($cached)) {
            $this->contentHtmlMemo[$threadId] = $cached;

            return $cached;
        }

        $html = $this->bodyHtml($raw);
        if ($postId > 0) {
            $card = do_shortcode(
                '[' . BuiltinParts::REF_TAG . ' post="' . $postId . '"]'
            );
            if ($card !== '') {
                $html .= "\n" . $card;
            }
        }

        wp_cache_set($key, $html, self::CACHE_GROUP, self::CACHE_TTL);
        $this->contentHtmlMemo[$threadId] = $html;

        return $html;
    }

    /** A body with mentions, smilies and shortcodes expanded — replies
     * included. Mentions resolve before the shortcode pass so their anchors
     * never ride a shortcode's own output; the ref part's enclosed content
     * is ignored anyway, so the two passes cannot nest. */
    private function bodyHtml(string $raw): string
    {
        $mentioned = $this->mentions?->linkify($this->smilies->render($raw)) ?? $this->smilies->render($raw);

        return do_shortcode($mentioned);
    }

    private function author(int $userId): Author
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new Author(0, '', '', null);
        }

        $avatarUrl = get_avatar_url($userId, ['size' => 128]);

        return new Author(
            $userId,
            (string) $user->user_nicename,
            (string) $user->display_name,
            is_string($avatarUrl) && $avatarUrl !== '' ? new Image($avatarUrl, (string) $user->display_name, null, null) : null,
        );
    }

    /** The contract flags come from the service's own rule — one authority, no drift. */
    private function canModerate(int $ownerId, int $viewerId): bool
    {
        return $this->threads->canModerate($ownerId, $viewerId);
    }

    private function iso(string $mysqlGmt): string
    {
        if ($mysqlGmt === '' || $mysqlGmt === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
