<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * The sponsorship domain's one random-suffix recipe: an uppercase
 * alphanumeric token cut from a CSPRNG-seeded digest (wp_rand feeds
 * uniqid's entropy; PHP's uniqid is seeded from the RNG source). Every
 * site-facing artifact — pending order ids, epay out_trade_no tails —
 * reads the same helper, so the shapes cannot drift apart.
 */
final class RandomToken
{
    /** An uppercase [0-9A-Z] suffix of the requested length. */
    public static function suffix(int $length): string
    {
        return strtoupper(substr(md5(uniqid((string) wp_rand(), true)), 0, max(1, min(32, $length))));
    }
}
