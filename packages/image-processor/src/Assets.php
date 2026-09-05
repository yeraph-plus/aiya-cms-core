<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Bundled font and pattern-material paths. Self-knowledge only; the caller
 * decides whether to prefer these over its own configured assets.
 */
final class Assets
{
    public static function fontFile(): string
    {
        return dirname(__DIR__) . '/assets/font/AlibabaPuHuiTi-3-65-Medium.otf';
    }

    public static function patternDir(): string
    {
        return dirname(__DIR__) . '/assets/pattern';
    }
}
