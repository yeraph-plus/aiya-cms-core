<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Attachment;

/**
 * Projects the OpenList file listing rows into the attachment wire
 * shape (viewer-gated link inclusion already happened in the domain).
 */
final class AttachmentPresenter
{
    /**
     * @param list<array{name: string, size: int, type: string, modified: string|null, url: string|null, ready: bool}> $items
     * @return list<array<string, mixed>>
     */
    public function items(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = (new Attachment(
                (string) $item['name'],
                (int) $item['size'],
                (string) $item['type'],
                $item['modified'] !== null ? (string) $item['modified'] : null,
                $item['url'] !== null ? (string) $item['url'] : null,
                (bool) $item['ready'],
            ))->toArray();
        }

        return $out;
    }
}
