<?php

declare(strict_types=1);

namespace Aiya\Core\Contracts;

interface Module
{
    public function register(): void;
}
