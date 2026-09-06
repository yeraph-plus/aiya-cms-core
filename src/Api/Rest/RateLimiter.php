<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

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
        $key = 'aiya_core_rl_' . md5($bucket . '|' . $this->clientIp() . '|' . $windowId);

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, max(1, $window));

        return $count <= $limit;
    }

    private function clientIp(): string
    {
        $raw = '';
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $raw = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : 'unknown';
    }
}
