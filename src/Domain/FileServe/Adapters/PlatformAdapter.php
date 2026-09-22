<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe\Adapters;

use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\Failure;

/**
 * A hand-written cloud-drive share (Baidu/Quark-style) as a data group: one
 * link, its extraction code and a price. The group is the row — a share *is*
 * its link, which is why the link is what the delivery response hands over and
 * what the opaque wire ref is derived from.
 *
 * The code is display data, never a gate: it reaches the reader beside the
 * link, and nothing asks anyone to type it.
 */
final class PlatformAdapter implements Adapter
{
    public const ID = 'platform';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Drive share', 'aiya-core');
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return [
            [
                'id' => 'url',
                'type' => 'text',
                'label' => __('Share link', 'aiya-core'),
                'description' => __('The cloud-drive share a reader opens. A bare domain is read as https at delivery time, so the stored value stays what you typed.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'code',
                'type' => 'text',
                'label' => __('Extraction code', 'aiya-core'),
                'description' => __('The drive\'s own code, handed to the reader together with the link.', 'aiya-core'),
                'default' => '',
            ],
        ];
    }

    /** @param array<string, mixed> $config */
    public function configured(array $config): bool
    {
        return self::url((string) ($config['url'] ?? '')) !== null;
    }

    public function siteConfig(): array
    {
        return []; // the stored group is the whole input; nothing site-level shapes its rows
    }

    /**
     * A stored row cannot fail to read, so this implementation never reports a
     * Failure (the port allows it; a remote adapter is the one that needs it).
     *
     * @param array<string, mixed> $config
     * @return list<Entry>
     */
    public function entries(array $config): array
    {
        $url = self::url((string) ($config['url'] ?? ''));
        if ($url === null) {
            return [];
        }

        $title = trim((string) ($config['title'] ?? ''));
        $code = trim((string) ($config['code'] ?? ''));

        // An untitled row is named by the adapter, never by the link: the
        // list is public and shared-cached, so the share URL (which is what
        // the claim charges for) may not ride it as a name.
        return [
            new Entry(
                name: $title !== '' ? $title : $this->label(),
                url: $url,
                code: $code !== '' ? $code : null,
            ),
        ];
    }

    /** The stored value as an absolute http(s) URL, or null when it is not one. */
    private static function url(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $stored) !== 1) {
            $stored = 'https://' . ltrim($stored, '/');
        }

        $url = esc_url_raw($stored, ['http', 'https']);

        return $url !== '' ? $url : null;
    }
}
