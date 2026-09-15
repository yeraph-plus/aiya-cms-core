<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Http;

/**
 * The visitor identity used for rate limiting and guest dedup: the direct
 * connection's REMOTE_ADDR by default. Deployments behind a reverse
 * proxy/CDN must bridge the real client address with the
 * `aiya_core_client_ip` filter (e.g. take the rightmost X-Forwarded-For
 * entry outside your proxy CIDR) — trusting the header blindly would let
 * anyone forge a fresh identity per request.
 */
final class ClientIp
{
    public static function forVisitor(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return (string) apply_filters('aiya_core_client_ip', $ip);
    }
}
