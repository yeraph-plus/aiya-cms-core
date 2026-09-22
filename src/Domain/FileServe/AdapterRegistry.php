<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use InvalidArgumentException;

/**
 * The adapters a file group may name, keyed by the id stored in its
 * `adapter` field. The domain registers what it ships, a package adapter
 * module registers what it wraps, and the metabox picker is built from this
 * list — so a new adapter is reachable in the editor the moment it registers.
 */
final class AdapterRegistry
{
    /** @var array<string, Adapter> */
    private array $adapters = [];

    public function register(Adapter $adapter): void
    {
        if (isset($this->adapters[$adapter->id()])) {
            throw new InvalidArgumentException(sprintf('The file adapter "%s" is already registered.', $adapter->id()));
        }

        $this->adapters[$adapter->id()] = $adapter;
    }

    public function get(string $id): ?Adapter
    {
        return $this->adapters[$id] ?? null;
    }

    /** @return array<string, Adapter> registration order */
    public function all(): array
    {
        return $this->adapters;
    }
}
