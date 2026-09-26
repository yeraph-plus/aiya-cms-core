<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Closure;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use RuntimeException;
use Throwable;

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

    /**
     * Bounds a source before any pixel work. Animated inputs collapse to
     * their first frame (the drivers resize every frame of a coalesced
     * animation — a large GIF/WebP burns minutes inside a single resize),
     * and sources larger than twice the target's long edge are shrunk
     * once, proportionally. Every render below then works on a small
     * master: decode is paid once, the copies and resizes stay cheap, and
     * no input size can push a generation past a request's execution
     * budget (caught live: a cron card generation died at 60s in the blur
     * frame after a full-size decode, two full-size copies and two
     * full-size resizes of the original).
     */
    protected function prepareSource(ImageInterface $image, int $width, int $height): ImageInterface
    {
        try {
            if ($image->layers()->count() > 1) {
                $image = $image->layers()->get(0);
            }
        } catch (Throwable) {
            // A driver that cannot inspect layers keeps the image as
            // opened; the generators' own try/catch still bounds failures.
        }

        $size = $image->getSize();
        $longEdge = max($size->getWidth(), $size->getHeight());
        $budget = max($width, $height) * 2;
        if ($longEdge > $budget) {
            $image = $image->resize($size->scale($budget / $longEdge));
        }

        return $image;
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
