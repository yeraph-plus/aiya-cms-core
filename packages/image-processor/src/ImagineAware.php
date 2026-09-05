<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Closure;
use Imagine\Image\ImagineInterface;
use RuntimeException;

/**
 * Lazy Imagine resolution for the generators: the constructor accepts an
 * ImagineInterface instance or a closure returning one, and the driver is
 * only resolved on first use. This is the package's single injection point —
 * callers (the core adapter) decide how the driver is built.
 */
abstract class ImagineAware
{
    private ImagineInterface|Closure $imagine;
    private ?ImagineInterface $resolved = null;

    public function __construct(ImagineInterface|Closure $imagine)
    {
        $this->imagine = $imagine;
    }

    protected function imagine(): ImagineInterface
    {
        if ($this->resolved === null) {
            $this->resolved = $this->resolveImagine();
        }

        return $this->resolved;
    }

    private function resolveImagine(): ImagineInterface
    {
        if ($this->imagine instanceof ImagineInterface) {
            return $this->imagine;
        }

        $resolved = ($this->imagine)();
        if (!$resolved instanceof ImagineInterface) {
            throw new RuntimeException('The Imagine resolver must return an ImagineInterface instance.');
        }

        return $resolved;
    }
}
