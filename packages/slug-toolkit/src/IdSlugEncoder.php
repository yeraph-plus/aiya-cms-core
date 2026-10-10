<?php

declare(strict_types=1);

namespace Aiya\Infra\SlugToolkit;

/**
 * Typed entry point over the frozen XDE_code algorithm. Generated slugs are
 * byte-identical to the algorithm's own output, so every post shares one
 * slug space. The default length of 8 matches the BV-style slug usage.
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
