<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\ChannelPost;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\WireDates;
use Aiya\Core\Domain\Telegram\FeedIngestor;
use Aiya\Core\Domain\Telegram\TelegramSettings;
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
                'channelId' => ['type' => 'integer'],
                'search' => ['type' => 'string', 'maxLength' => 100],
            ],
        ]);
    }

    private function list(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');
        $channelId = $request->get_param('channelId');
        $search = trim((string) ($request->get_param('search') ?? ''));

        $total = $this->feed->count($channelId, $search);
        $rows = $this->feed->page($perPage, ($page - 1) * $perPage, $channelId, $search);

        return Envelope::payload(
            array_map([$this, 'present'], $rows),
            Pagination::fromCounts($page, $perPage, $total)
        );
    }

    /**
     * The row → contract mapping. The pool's content-relative path stays
     * server-side; the wire carries the resolved URL only (internal keys
     * never surface, per the storage protocol). The channel identity
     * prefers the mirror config's display title and carries its NSFW
     * mark; a channel that left the config keeps serving from its stored
     * snapshot, unmarked.
     *
     * @param array<string, mixed> $row
     */
    private function present(array $row): ChannelPost
    {
        $identity = TelegramSettings::mirrorRow(
            (int) ($row['source_chat_id'] ?? 0),
            self::groupId($row['chat_username'] ?? null)
        );

        return new ChannelPost(
            (int) ($row['id'] ?? 0),
            self::kindName((int) ($row['kind'] ?? 0)),
            (string) ($row['text'] ?? ''),
            self::entities($row['entities'] ?? null),
            self::media($row['media'] ?? null),
            [
                'id' => (int) ($row['source_chat_id'] ?? 0),
                'title' => $identity !== null && $identity['title'] !== ''
                    ? $identity['title']
                    : (string) ($row['chat_title'] ?? ''),
                'username' => self::groupId($row['chat_username'] ?? null),
                'nsfw' => $identity !== null && $identity['nsfw'],
            ],
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
     * The stored span JSON onto the wire — `url` always present (null on
     * the payload-less types) so the front-end schema stays exact.
     *
     * @return list<array{type: string, offset: int, length: int, url: string|null}>
     */
    private static function entities(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entities = [];
        foreach ($decoded as $entity) {
            if (!is_array($entity) || !is_string($entity['type'] ?? null)) {
                continue;
            }
            $url = $entity['url'] ?? null;
            $entities[] = [
                'type' => (string) $entity['type'],
                'offset' => (int) ($entity['offset'] ?? 0),
                'length' => (int) ($entity['length'] ?? 0),
                'url' => is_string($url) && $url !== '' ? $url : null,
            ];
        }

        return $entities;
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
