<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One smilies token of a pack: the literal `::code::` body the front end
 * matches in plain text (comment bodies, future pickers) and the image
 * URL the renderer inlines for HTML content.
 */
final class SmiliesItem
{
    public function __construct(
        public readonly string $code,
        public readonly string $url,
    ) {
    }

    /** @return array{code: string, url: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'url' => $this->url,
        ];
    }
}
