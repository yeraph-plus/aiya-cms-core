<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One customizable community board (0.45.0): the classification a
 * thread is posted into, replacing the former three-value type. Threads
 * carries it as `board`; the boards route serves the full list with
 * thread counts.
 */
final class DiscussionBoard
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description = '',
        public readonly int $threads = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'threads' => $this->threads,
        ];
    }
}
