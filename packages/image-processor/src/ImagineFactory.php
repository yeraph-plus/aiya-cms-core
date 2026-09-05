<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

use Imagine\Gd\Imagine as GdImagine;
use Imagine\Image\ImagineInterface;
use Imagine\Imagick\Imagine as ImagickImagine;
use RuntimeException;
use Throwable;

/**
 * Picks the best available Imagine driver. The adapter resolves this once
 * and injects the instance (or a closure returning it) into the generators.
 *
 * Imagick is preferred but only after a functional probe: some runtimes ship
 * the extension without the needed image delegates (Docker php images are a
 * known case), where every open() would fail. GD is the fallback and only
 * counts when ext-gd is actually loaded — checking the autoloadable class
 * alone is not enough.
 */
final class ImagineFactory
{
    /** 1x1 transparent PNG, used to probe Imagick delegate health. */
    private const PROBE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public static function create(): ImagineInterface
    {
        if (extension_loaded('imagick') && class_exists(ImagickImagine::class) && self::imagickReadsImages()) {
            return new ImagickImagine();
        }

        if (extension_loaded('gd') && class_exists(GdImagine::class)) {
            return new GdImagine();
        }

        if (extension_loaded('imagick') && class_exists(ImagickImagine::class)) {
            return new ImagickImagine();
        }

        throw new RuntimeException('No Imagine driver is available; install ext-gd or ext-imagick.');
    }

    private static function imagickReadsImages(): bool
    {
        $blob = base64_decode(self::PROBE_PNG, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed in-repo constant, not obfuscated input.
        if (!is_string($blob) || $blob === '') {
            return false;
        }

        try {
            $probe = new \Imagick();
            $probe->readImageBlob($blob);
            $probe->clear();
            $probe->destroy();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
