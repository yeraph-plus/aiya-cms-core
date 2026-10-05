<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

/**
 * The membership domain's one random-suffix recipe: an uppercase
 * alphanumeric token cut from a CSPRNG-seeded digest (wp_rand feeds
 * uniqid's entropy; PHP's uniqid is seeded from the RNG source). The
 * Epay checkout's out_trade_no tail reads this helper so the wire shape
 * cannot drift between requests.
 */
final class RandomToken
{
    /** An uppercase [0-9A-Z] suffix of the requested length. */
    public static function suffix(int $length): string
    {
        return strtoupper(substr(md5(uniqid((string) wp_rand(), true)), 0, max(1, min(32, $length))));
    }
}
