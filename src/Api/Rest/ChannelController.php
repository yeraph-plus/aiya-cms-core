<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\ChannelPost;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\WireDates;
use Aiya\Core\Domain\Telegram\FeedIngestor;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The channel-mirror feed: the source channels' posts as the front end
 * pulls them (public read, page-ordered newest first — the same page
 * vocabulary every other list endpoint speaks). Rows are presentational
 * facts only: no viewer state, no moderation surface, the channel is the
 * single source of truth.
 */
final class ChannelController
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function __construct(private FeedIngestor $feed)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/channel/feed', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->list($request),
            'permission_callback' => '__return_true',
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => [
                    'type' => 'integer',
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                ],
            ],
        ]);
    }

    private function list(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');

        $total = $this->feed->count();
        $rows = $this->feed->page($perPage, ($page - 1) * $perPage);

        return Envelope::payload(
            array_map([$this, 'present'], $rows),
            Pagination::fromCounts($page, $perPage, $total)
        );
    }

    /**
     * The row → contract mapping. The pool's content-relative path stays
     * server-side; the wire carries the resolved URL only (internal keys
     * never surface, per the storage protocol).
     *
     * @param array<string, mixed> $row
     */
    private function present(array $row): ChannelPost
    {
        return new ChannelPost(
            (int) ($row['id'] ?? 0),
            self::kindName((int) ($row['kind'] ?? 0)),
            (string) ($row['text'] ?? ''),
            self::media($row['media'] ?? null),
            (string) ($row['tg_link'] ?? ''),
            self::groupId($row['media_group_id'] ?? null),
            WireDates::fromGmt((string) ($row['posted_at'] ?? ''))
        );
    }

    private static function kindName(int $kind): string
    {
        return match ($kind) {
            FeedIngestor::KIND_PHOTO => 'photo',
            FeedIngestor::KIND_MEDIA => 'media',
            default => 'text',
        };
    }

    /**
     * @return list<array{url: string, width: int, height: int}>
     */
    private static function media(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $media = [];
        foreach ($decoded as $image) {
            if (is_array($image) && is_string($image['url'] ?? null)) {
                $media[] = [
                    'url' => (string) $image['url'],
                    'width' => (int) ($image['width'] ?? 0),
                    'height' => (int) ($image['height'] ?? 0),
                ];
            }
        }

        return $media;
    }

    private static function groupId(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
