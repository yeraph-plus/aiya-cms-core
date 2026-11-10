<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The answer of a like write: the materialized count after the write, the
 * viewer's resulting state, and whether this hit was the already-liked
 * no-op. Spoken by both like surfaces — the content like (add-only, a
 * successful write always leaves `viewerLiked` true) and the discussion
 * like/unlike toggle (0.102.0; unlike is always a fresh answer, `already`
 * stays false).
 */
final class LikeResponse
{
    public function __construct(
        public readonly int $likes,
        public readonly bool $viewerLiked,
        public readonly bool $already = false,
    ) {
    }

    /** @return array{likes: int, viewerLiked: bool, already: bool} */
    public function toArray(): array
    {
        return [
            'likes' => $this->likes,
            'viewerLiked' => $this->viewerLiked,
            'already' => $this->already,
        ];
    }
}
