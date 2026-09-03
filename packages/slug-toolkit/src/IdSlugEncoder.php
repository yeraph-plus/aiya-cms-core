<?php

declare(strict_types=1);

namespace Aiya\Infra\SlugToolkit;

/**
 * Typed entry point over the frozen XDE_code algorithm. Inheritance (rather
 * than a rewrite) keeps generated slugs byte-identical to the legacy theme's
 * so old and new posts share one slug space. The default length of 8 matches
 * the legacy BV-style slug usage.
 */
final class IdSlugEncoder extends XDE_code
{
    public function __construct(int $length = 8)
    {
        parent::__construct($length);
    }

    public function encodeId(int $id): string
    {
        return $this->encode((string) $id);
    }

    public function decodeId(string $code): string
    {
        return $this->decode($code);
    }
}
