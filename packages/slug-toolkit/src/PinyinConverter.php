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

    private function engine(): Pinyin
    {
        return $this->engine ??= new Pinyin();
    }

    /** Full-text pinyin with a divider: '带着希望去旅行' -> 'dai-zhe-xi-wang-qu-lyu-xing'. */
    public function permalink(string $text, string $divider = '-'): string
    {
        return $this->engine()->permalink($text, $divider);
    }

    /** First-letter abbreviation: '带着希望去旅行' -> 'dzxwqlx'. */
    public function abbr(string $text): string
    {
        return $this->engine()->abbr($text);
    }
}
