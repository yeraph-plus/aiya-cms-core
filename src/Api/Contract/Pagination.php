<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * List pagination block carried in `meta.pagination`. The invariants are
 * enforced at construction: totalPages = ceil(totalItems / perPage) (0
 * for an empty list), hasNext/hasPrevious derived from page.
 */
final class Pagination
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalItems,
        public readonly int $totalPages,
        public readonly bool $hasNext,
        public readonly bool $hasPrevious,
    ) {
    }

    /** Pages beyond the last answer 200 with an empty list; no silent re-paging. */
    public static function fromCounts(int $page, int $perPage, int $totalItems): self
    {
        $perPage = max(1, $perPage);
        $totalPages = (int) ceil($totalItems / $perPage);

        return new self(
            max(1, $page),
            $perPage,
            max(0, $totalItems),
            $totalPages,
            max(1, $page) < $totalPages,
            max(1, $page) > 1,
        );
    }

    /** @return array{page: int, perPage: int, totalItems: int, totalPages: int, hasNext: bool, hasPrevious: bool} */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'totalItems' => $this->totalItems,
            'totalPages' => $this->totalPages,
            'hasNext' => $this->hasNext,
            'hasPrevious' => $this->hasPrevious,
        ];
    }
}
