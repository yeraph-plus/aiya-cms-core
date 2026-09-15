<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Infrastructure\Http\ClientIp;

/**
 * Minimal fixed-window throttle for the public auth endpoints, keyed by
 * bucket + client IP. Deliberately cheap (one transient per window) — it
 * blunts credential stuffing and reset-mail abuse without pretending to
 * be a real rate-limiting backend.
 */
final class RateLimiter
{
    /**
     * Records one hit and reports whether the bucket still has budget.
     *
     * @param string $bucket Logical action name (e.g. "login").
     * @param int    $limit  Allowed hits per window.
     * @param int    $window Window length in seconds.
     */
    public function hit(string $bucket, int $limit, int $window): bool
    {
        $windowId = intdiv(time(), max(1, $window));
        $key = 'aiya_core_rl_' . md5($bucket . '|' . ClientIp::forVisitor() . '|' . $windowId);

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, max(1, $window));

        return $count <= $limit;
    }
}
