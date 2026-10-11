<?php

declare(strict_types=1);

namespace Aiya\Infra\SlugToolkit;

use Overtrue\Pinyin\Pinyin;

/**
 * Basic calls over overtrue/pinyin. WordPress-free and policy-free on
 * purpose: no truncating, sanitizing, or uniqueness here — consumers (the
 * core-side slug module) own that behavior and only borrow the conversion.
 */
final class PinyinConverter
{
    private ?Pinyin $engine = null;

    /**
     * The engine, or null when overtrue/pinyin is not installed. The
     * composer dependency ships in the plugin's vendor tree; a
     * deployment that strips it would otherwise fatal on the first
     * term insert, so an absent engine degrades to the identity
     * conversion below instead of taking the request down.
     */
    private function engine(): ?Pinyin
    {
        if ($this->engine === null && class_exists(Pinyin::class)) {
            $this->engine = new Pinyin();
        }

        return $this->engine;
    }

    /** Full-text pinyin with a divider: '带着希望去旅行' -> 'dai-zhe-xi-wang-qu-lyu-xing'. */
    public function permalink(string $text, string $divider = '-'): string
    {
        $engine = $this->engine();

        return $engine !== null ? $engine->permalink($text, $divider) : $text;
    }

    /** First-letter abbreviation: '带着希望去旅行' -> 'dzxwqlx'. */
    public function abbr(string $text): string
    {
        $engine = $this->engine();

        return $engine !== null ? $engine->abbr($text)->join('') : $text;
    }
}
