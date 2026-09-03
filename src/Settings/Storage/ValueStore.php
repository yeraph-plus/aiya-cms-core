<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Storage;

interface ValueStore
{
    /** @return array<string, mixed> */
    public function all(): array;

    /** @param array<string, mixed> $values */
    public function replace(array $values): void;

    public function delete(): void;
}

