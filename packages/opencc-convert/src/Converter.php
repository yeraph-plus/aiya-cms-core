<?php

declare(strict_types=1);

namespace Aiya\Infra\OpenCc;

use Overtrue\PHPOpenCC\OpenCC;

/**
 * Simplified-to-Traditional conversion strategies selected from a locale tag.
 *
 * WordPress-free on purpose: the core-side adapter module decides when and on
 * which strings to convert; this package only owns the strategy mapping and
 * the shared OpenCC engine instance.
 */
final class Converter
{
    public const STRATEGY_S2HK = 'S2HK';
    public const STRATEGY_S2TW = 'S2TW';

    private ?OpenCC $engine = null;

    /**
     * Maps a locale tag (zh_HK, zh-TW, zh_Hant_TW, …) to an OpenCC strategy.
     * Returns null when no conversion applies to the locale.
     */
    public function strategyForLocale(string $locale): ?string
    {
        $normalized = str_replace('-', '_', strtolower($locale));

        if (str_starts_with($normalized, 'zh_hk')) {
            return self::STRATEGY_S2HK;
        }
        if (str_starts_with($normalized, 'zh_tw') || str_starts_with($normalized, 'zh_hant')) {
            return self::STRATEGY_S2TW;
        }

        return null;
    }

    public function convert(string $content, string $strategy): string
    {
        if ($content === '') {
            return $content;
        }

        $this->engine ??= new OpenCC();

        return $this->engine->convert($content, $strategy);
    }
}
