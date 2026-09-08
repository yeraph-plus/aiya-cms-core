<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Author;
use Aiya\Core\Api\Contract\Discussion;
use Aiya\Core\Api\Contract\DiscussionDetail;
use Aiya\Core\Api\Contract\DiscussionReply;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\PostRef;
use Aiya\Core\Domain\Content\PublicTypes;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use WP_Post;

/**
 * Maps discussion table rows onto the contract. Permission flags are
 * derived here from the acting viewer (author or `edit_pages`
 * administrator) so the front end never re-implements the rules; guests
 * read false everywhere. This is the only WP-row touchpoint of the
 * discussion read path.
 */
final class DiscussionPresenter
{
    /** @param object{id:int,user_id:int,type:string,status:string,title:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string} $row */
    public function present(object $row, int $viewerId): Discussion
    {
        return new Discussion(
            (int) $row->id,
            '/community/' . (int) $row->id . '/',
            (string) $row->title,
            (string) $row->type,
            (string) $row->status,
            $this->author((int) $row->user_id),
            $this->postRef((int) $row->post_id),
            (int) $row->reply_count,
            $this->iso((string) ($row->last_reply_at ?? '')),
            $this->iso((string) $row->created_at),
            $this->canModerate((int) $row->user_id, $viewerId),
            $this->canModerate((int) $row->user_id, $viewerId),
            $viewerId > 0 && !ThreadStatus::locksReplies((string) $row->status),
        );
    }

    /**
     * @param object{id:int,user_id:int,type:string,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string} $row
     * @param list<DiscussionReply> $replies
     */
    public function detail(object $row, array $replies, int $viewerId): DiscussionDetail
    {
        return new DiscussionDetail($this->present($row, $viewerId), (string) $row->content, $replies);
    }

    /** @param object{id:int,user_id:int,content:string,created_at:string} $row */
    public function reply(object $row, int $viewerId): DiscussionReply
    {
        return new DiscussionReply(
            (int) $row->id,
            $this->author((int) $row->user_id),
            (string) $row->content,
            $this->iso((string) $row->created_at),
            $this->canModerate((int) $row->user_id, $viewerId),
        );
    }

    private function postRef(int $postId): ?PostRef
    {
        if ($postId <= 0) {
            return null;
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || !in_array($post->post_type, ['post', 'resource'], true)) {
            return null;
        }

        $type = PublicTypes::get($post->post_type);
        if ($type === null) {
            return null;
        }

        return new PostRef(
            (int) $post->ID,
            (string) $post->post_type,
            (string) get_the_title($post),
            $type->url((int) $post->ID),
        );
    }

    private function author(int $userId): Author
    {
        $user = get_userdata($userId);
        if (!$user) {
            return new Author(0, '', null);
        }

        $avatarUrl = get_avatar_url($userId, ['size' => 128]);

        return new Author(
            $userId,
            (string) $user->display_name,
            is_string($avatarUrl) && $avatarUrl !== '' ? new Image($avatarUrl, (string) $user->display_name, null, null) : null,
        );
    }

    /** Mirrors the service's authorization rule for the contract flags. */
    private function canModerate(int $ownerId, int $viewerId): bool
    {
        return $ownerId === $viewerId || ($viewerId > 0 && user_can($viewerId, 'edit_pages'));
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
