<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\SmiliesItem;
use Aiya\Core\Api\Contract\SmiliesPack;

/**
 * Projects the registry's directory-scanned smilies packs into the
 * wire shape (packs of code → URL pairs).
 */
final class SmiliesPresenter
{
    /**
     * @param list<array{slug: string, items: list<array{code: string, url: string}>}> $packs
     * @return list<array<string, mixed>>
     */
    public function packs(array $packs): array
    {
        $out = [];
        foreach ($packs as $pack) {
            $items = [];
            foreach ($pack['items'] as $item) {
                $items[] = new SmiliesItem((string) $item['code'], (string) $item['url']);
            }
            $out[] = (new SmiliesPack((string) $pack['slug'], $items))->toArray();
        }

        return $out;
    }
}
