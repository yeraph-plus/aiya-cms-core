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
     * Keyed by the visitor address — the right subject for anonymous
     * flows; machine-to-machine callers all share a few server IPs, so
     * use hitFor() with the acting user instead.
     *
     * @param string $bucket Logical action name (e.g. "login").
     * @param int    $limit  Allowed hits per window.
     * @param int    $window Window length in seconds.
     */
    public function hit(string $bucket, int $limit, int $window): bool
    {
        return $this->hitFor($bucket, ClientIp::forVisitor(), $limit, $window);
    }

    /**
     * The subject-keyed twin: same fixed-window transient, but the bucket
     * belongs to an arbitrary identity (a user id) rather than the client
     * address. Server-side callers all egress from a handful of IPs — one
     * address-keyed bucket there would lock the site's whole user base
     * out together.
     *
     * @param string          $bucket  Logical action name (e.g. "login").
     * @param int|string|null $subject The identity the budget belongs to.
     * @param int             $limit   Allowed hits per window.
     * @param int             $window  Window length in seconds.
     */
    public function hitFor(string $bucket, int|string|null $subject, int $limit, int $window): bool
    {
        $windowId = intdiv(time(), max(1, $window));
        $key = 'aiya_core_rl_' . md5($bucket . '|' . (string) $subject . '|' . $windowId);

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, max(1, $window));

        return $count <= $limit;
    }
}
