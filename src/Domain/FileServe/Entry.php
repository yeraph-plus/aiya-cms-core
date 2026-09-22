<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

/**
 * One row of a file list, normalized across adapters: an adapter fills what
 * its platform knows and leaves the rest unset.
 *
 * `url` and `code` are the delivery payload — they reach a reader only through
 * the claim response, never through the list (which is public and
 * shared-cached) — and `id` is the provider's own handle for the row, kept for
 * the same reason. `path`, `id`, the link or the name is the row's identity:
 * the service derives the opaque `ref` the wire carries from it, and matches a
 * claim against the same derivation.
 *
 * The array form is the cache form: plain scalars only, so a persistent
 * object cache never has to serialize objects.
 */
final class Entry
{
    public const FILE = 'file';
    public const DIR = 'dir';

    public function __construct(
        public readonly string $name,
        public readonly string $kind = self::FILE,
        public readonly int $size = 0,
        public readonly ?int $modified = null,
        public readonly ?string $id = null,
        public readonly ?string $path = null,
        public readonly ?string $url = null,
        public readonly ?string $code = null,
    ) {
    }

    public function isDir(): bool
    {
        return $this->kind === self::DIR;
    }

    /**
     * The row's identity: its path when the source has one, otherwise the
     * provider's own handle (an id that survives link rotation), otherwise the
     * link, otherwise the name.
     */
    public function identity(): string
    {
        foreach ([$this->path, $this->id, $this->url] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $this->name;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'size' => $this->size,
            'modified' => $this->modified,
            'id' => $this->id,
            'path' => $this->path,
            'url' => $this->url,
            'code' => $this->code,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['name'] ?? ''),
            (string) ($row['kind'] ?? self::FILE),
            max(0, (int) ($row['size'] ?? 0)),
            isset($row['modified']) ? (int) $row['modified'] : null,
            self::optional($row['id'] ?? null),
            self::optional($row['path'] ?? null),
            self::optional($row['url'] ?? null),
            self::optional($row['code'] ?? null),
        );
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
