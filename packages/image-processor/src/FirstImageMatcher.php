<?php

declare(strict_types=1);

namespace Aiya\Infra\ImageProcessor;

/**
 * Extracts the first image URL from HTML content with a plain regular
 * expression — the same matching semantics the legacy theme used for
 * "first image as thumbnail" fallbacks, now a pure utility the read layer
 * can reuse without pulling in the generators.
 */
final class FirstImageMatcher
{
    public function first(string $html): ?string
    {
        if ($html === '' || preg_match_all('/<img[^>]*?src=[\'"]([^\'"]+)[\'"][^>]*?>/i', $html, $matches) === false) {
            return null;
        }

        $url = $matches[1][0] ?? '';
        $url = trim((string) $url);

        return $url !== '' ? $url : null;
    }

    /** @return list<string> */
    public function all(string $html): array
    {
        if ($html === '' || preg_match_all('/<img[^>]*?src=[\'"]([^\'"]+)[\'"][^>]*?>/i', $html, $matches) === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            $matches[1]
        ), static fn (string $url): bool => $url !== ''));
    }
}
