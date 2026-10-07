<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One channel-mirror row as the front end receives it: the site owner's
 * Telegram channel posts mirrored into the web feed, 入库即显. `kind`
 * names the display shape — 'text', 'photo' (media carries the
 * transferred pool URLs), 'media' (video/files: `tgLink` is the only
 * access path). `text` is the sanitized plain text; `entities` carries
 * the styling spans ({type, offset, length, url}) — offsets are UTF-16
 * code units into `text`, so a JS renderer slices natively; building the
 * styled output is the front end's work. `channel` is the source
 * channel's identity snapshot (several channels can mirror into one
 * feed). An album arrives as several rows sharing `mediaGroupId`; the
 * front end groups consecutive ones. Rows are immutable from the site
 * side — the channel is the single source of truth.
 */
final class ChannelPost
{
    /**
     * @param array{id: int, title: string, username: string|null} $channel
     * @param list<array{url: string, width: int, height: int}> $media
     * @param list<array{type: string, offset: int, length: int, url: string|null}> $entities
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $text,
        public readonly array $entities,
        public readonly array $media,
        public readonly array $channel,
        public readonly string $tgLink,
        public readonly ?string $mediaGroupId,
        public readonly string $postedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'text' => $this->text,
            'entities' => $this->entities,
            'media' => $this->media,
            'channel' => $this->channel,
            'tgLink' => $this->tgLink,
            'mediaGroupId' => $this->mediaGroupId,
            'postedAt' => $this->postedAt,
        ];
    }
}
