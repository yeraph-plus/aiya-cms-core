<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\FileDownload;
use Aiya\Core\Api\Contract\FileEntry;
use Aiya\Core\Api\Contract\FileList;

/**
 * Projects the assembled file lists and a claim result into the wire shapes.
 * The rows arrive complete — opaque ref, icon category, ISO stamp and the
 * price all decided in the domain — so this maps field by field and nothing
 * else.
 */
final class FilePresenter
{
    /**
     * @param list<array<string, mixed>> $lists
     * @return list<array<string, mixed>>
     */
    public function lists(array $lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            $items = [];
            foreach (is_array($list['items'] ?? null) ? $list['items'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = new FileEntry(
                    ref: (string) ($item['ref'] ?? ''),
                    name: (string) ($item['name'] ?? ''),
                    kind: (string) ($item['kind'] ?? 'file'),
                    size: (int) ($item['size'] ?? 0),
                    type: (string) ($item['type'] ?? 'unknown'),
                    modified: isset($item['modified']) && is_string($item['modified']) ? $item['modified'] : null,
                );
            }

            $out[] = (new FileList(
                id: (string) ($list['id'] ?? ''),
                adapter: (string) ($list['adapter'] ?? ''),
                title: (string) ($list['title'] ?? ''),
                price: max(0, (int) ($list['price'] ?? 0)),
                items: $items,
            ))->toArray();
        }

        return $out;
    }

    /**
     * @param array{url: string, code: ?string, price: int, balance: ?int} $claim
     * @return array<string, mixed>
     */
    public function download(array $claim): array
    {
        $code = $claim['code'] ?? null;

        return (new FileDownload(
            url: (string) $claim['url'],
            code: is_string($code) && $code !== '' ? $code : null,
            price: max(0, (int) $claim['price']),
            balance: isset($claim['balance']) ? (int) $claim['balance'] : null,
        ))->toArray();
    }
}
