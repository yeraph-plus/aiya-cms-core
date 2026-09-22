<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One row of a file list. The listing is public and shared-cached, so a row
 * carries no link and nothing that could be turned into one: `ref` is an
 * opaque, site-keyed reference to the row, and the delivery response — not
 * this — is where a link and an extraction code come from.
 *
 * `type` is the icon category the backend derived from the name (pdf,
 * archive, folder, …); `kind` says what the row is (a folder carries no
 * link at all).
 */
final class FileEntry
{
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $size,
        public readonly string $type,
        public readonly ?string $modified,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'kind' => $this->kind,
            'size' => $this->size,
            'type' => $this->type,
            'modified' => $this->modified,
        ];
    }
}
