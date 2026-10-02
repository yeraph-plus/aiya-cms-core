<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Notification;

/**
 * Projects notification rows into the feed wire shape, including the
 * GMT → site-offset date conversion.
 */
final class NotificationPresenter
{
    public function __construct(private readonly ?NotificationLinker $linker = null)
    {
    }

    /**
     * `title` ships as notification HTML: the escaped text wrapped in the
     * row's soft reference anchor when the linker can resolve a target —
     * the front end runs it through its content sanitizer, exactly like
     * `bodyHtml` fields. `body` stays a plain-text excerpt.
     *
     * @param object{id:int,type:string,title:string,body:string,created_at:string,actor_id?:int|string|null,object_type?:string|int|null,object_id?:int|string|null} $row
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        return (new Notification(
            (int) $row->id,
            (string) $row->type,
            $this->linker?->wrap($row, (string) $row->title) ?? esc_html((string) $row->title),
            (string) $row->body,
            $this->isoCreatedAt((string) $row->created_at)
        ))->toArray();
    }

    /** created_at is stored GMT; the contract wants offset ISO 8601. */
    private function isoCreatedAt(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
