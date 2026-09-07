<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * SEO projection for the front end's `<head>`. `title` falls back to the
 * post title; `description` to the excerpt. `noindex` is a backend
 * decision surfaced verbatim.
 */
final class Seo
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly bool $noindex,
    ) {
    }

    /** @return array{title: string, description: string, noindex: bool} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'noindex' => $this->noindex,
        ];
    }
}
