<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Shared\PublicTypes;
use WP_Comment;

/**
 * Wraps a notification's text in the reference vocabulary's soft anchor —
 * the same `data-aiya-ref` markup the `[ref]` part and the mention
 * injection emit, resolved by the front end's existing anchor transform
 * (zero-routing rule: kind + handles, never an href). The jump target
 * follows the row's object: post rows anchor to the article, comment rows
 * deep-link through the parent post's handles, discussion rows carry the
 * thread id and board, and a follow row anchors the follower's profile
 * (the object is the followed user — the actor is who you go look at).
 *
 * Kinds whose target is the viewer's own surface (credit, sponsorship,
 * account) and broadcast rows stay anchor-free — the front end routes
 * those by the row's type alone. Unresolvable targets (a deleted post or
 * comment) degrade to escaped plain text, never a dead anchor.
 */
final class NotificationLinker
{
    public function __construct(private readonly DiscussionService $threads = new DiscussionService())
    {
    }

    /**
     * Feeds a whole notification page's anchors in bulk before the row
     * loop: core objects warm their own caches via the priming helpers,
     * thread rows land in a per-request memo (the self-table has no core
     * cache behind it). Steady-state wrap() calls then answer from memory
     * instead of one query per row.
     *
     * @param list<object{object_type?:string|int|null,object_id?:int|string|null,actor_id?:int|string|null}> $rows
     */
    public function prime(array $rows): void
    {
        $postIds = [];
        $commentIds = [];
        $actorIds = [];
        $threadIds = [];
        foreach ($rows as $row) {
            $objectId = (int) ($row->object_id ?? 0);
            $actorId = (int) ($row->actor_id ?? 0);
            if ($actorId > 0) {
                $actorIds[] = $actorId;
            }

            switch ((string) ($row->object_type ?? '')) {
                case 'post':
                    if ($objectId > 0) {
                        $postIds[] = $objectId;
                    }
                    break;
                case 'comment':
                    if ($objectId > 0) {
                        $commentIds[] = $objectId;
                    }
                    break;
                case 'discussion':
                    if ($objectId > 0) {
                        $threadIds[] = $objectId;
                    }
                    break;
                case 'user':
                    if ($actorId > 0) {
                        $actorIds[] = $actorId;
                    }
                    break;
            }
        }

        // Comment anchors read their parent post too; collect those after
        // the comments land in cache (get_comment is then a cache hit).
        if ($commentIds !== []) {
            _prime_comment_caches(array_values(array_unique($commentIds)));
            foreach (array_unique($commentIds) as $id) {
                $comment = get_comment($id);
                if ($comment instanceof WP_Comment) {
                    $postIds[] = (int) $comment->comment_post_ID;
                }
            }
        }

        if ($postIds !== []) {
            _prime_post_caches(array_values(array_unique($postIds)));
        }
        if ($actorIds !== []) {
            cache_users(array_values(array_unique($actorIds)));
        }

        if ($threadIds !== []) {
            foreach ($this->threads->byIds(array_values(array_unique($threadIds))) as $id => $thread) {
                $this->threadMemo[$id] = $thread;
            }
        }
    }

    /** Per-request thread-row memo fed by prime(); see prime().
     * @var array<int, object{id:int,board_slug:string|null}|null> */
    private array $threadMemo = [];

    /**
     * @param object{object_type?:string|int|null,object_id?:int|string|null,actor_id?:int|string|null} $row
     */
    public function wrap(object $row, string $title): string
    {
        $anchor = match ((string) ($row->object_type ?? '')) {
            'post' => $this->post((int) ($row->object_id ?? 0)),
            'comment' => $this->comment((int) ($row->object_id ?? 0)),
            'discussion' => $this->thread((int) ($row->object_id ?? 0)),
            'user' => $this->user((int) ($row->actor_id ?? 0)),
            default => '',
        };

        $text = esc_html($title);

        return $anchor === '' ? $text : $anchor . $text . '</a>';
    }

    /** The article anchor: type + slug handles, the post card's vocabulary. */
    private function post(int $id): string
    {
        $post = $id > 0 ? get_post($id) : null;
        $vocab = $post !== null ? PublicTypes::forPostType((string) $post->post_type)?->name : null;

        return $vocab === null
            ? ''
            : '<a data-aiya-ref="post" data-aiya-type="' . esc_attr($vocab) . '"'
                . ' data-aiya-slug="' . esc_attr((string) $post->post_name) . '">';
    }

    /** The comment anchor: parent post + comment handles for the deep link. */
    private function comment(int $id): string
    {
        $comment = $id > 0 ? get_comment($id) : null;
        if (!$comment instanceof WP_Comment) {
            return '';
        }

        $post = get_post((int) $comment->comment_post_ID);
        $vocab = $post !== null ? PublicTypes::forPostType((string) $post->post_type)?->name : null;

        return $vocab === null
            ? ''
            : '<a data-aiya-ref="comment" data-aiya-post="' . esc_attr((string) $comment->comment_post_ID) . '"'
                . ' data-aiya-comment="' . esc_attr((string) $id) . '"'
                . ' data-aiya-type="' . esc_attr($vocab) . '"'
                . ' data-aiya-slug="' . esc_attr((string) $post->post_name) . '">';
    }

    /** The thread anchor: id + board handles, the [ref thread] vocabulary. */
    private function thread(int $id): string
    {
        if (!array_key_exists($id, $this->threadMemo)) {
            // Cache the miss too — a deleted thread costs one lookup per
            // id per request, not one per row.
            $this->threadMemo[$id] = $id > 0 ? $this->threads->byId($id) : null;
        }
        $thread = $this->threadMemo[$id];

        return $thread === null
            ? ''
            : '<a data-aiya-ref="thread" data-aiya-id="' . esc_attr((string) $id) . '"'
                . ' data-aiya-board="' . esc_attr((string) ($thread->board_slug ?? '')) . '">';
    }

    /** The follower's profile anchor (a follow row's actor). */
    private function user(int $actorId): string
    {
        $actor = $actorId > 0 ? get_userdata($actorId) : false;

        return $actor === false
            ? ''
            : '<a data-aiya-ref="user" data-aiya-nicename="' . esc_attr((string) $actor->user_nicename) . '">';
    }
}
