<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Http;

use Aiya\Core\Contracts\Module;

/**
 * Reverse-proxy bridge for the headless deployment: the Astro front station
 * is the only direct client of the contract API, so every proxied visitor
 * shares its REMOTE_ADDR — which collapses rate limiting into one site-wide
 * bucket and degrades guest dedup to a single identity.
 *
 * When the {@see self::SECRET_CONSTANT} constant is defined in wp-config and
 * the request carries the matching secret header, the visitor IP is taken
 * from X-Forwarded-For (the front station overwrites that header with the
 * address it resolved for the visitor). Without the constant, or on a
 * secret mismatch, REMOTE_ADDR stands — trusting the forwarded header
 * blindly would let anyone forge a fresh identity per request. The secret
 * lives in deploy config on both sides, like the DB credentials; never in
 * the database.
 */
final class TrustedProxy implements Module
{
    public const SECRET_CONSTANT = 'AIYA_PROXY_SECRET';
    public const SECRET_HEADER = 'HTTP_X_AIYA_PROXY_SECRET';
    public const FORWARDED_HEADER = 'HTTP_X_FORWARDED_FOR';

    /** The shared secret, or null while unconfigured (bridge inert). */
    private readonly ?string $secret;

    public function __construct(?string $secret = null)
    {
        // An explicitly empty secret is as good as none — the bridge stays inert.
        $secret = $secret !== '' ? $secret : null;
        if ($secret !== null) {
            $this->secret = $secret;

            return;
        }
        $configured = defined(self::SECRET_CONSTANT)
            && is_string(constant(self::SECRET_CONSTANT))
            ? constant(self::SECRET_CONSTANT)
            : null;
        $this->secret = $configured !== '' ? $configured : null;
    }

    /** True while the bridge is configured (secret present, non-empty). */
    public function enabled(): bool
    {
        return $this->secret !== null;
    }

    /**
     * The visitor IP for this request: the forwarded address when the secret
     * authenticates the proxy hop, the passed REMOTE_ADDR otherwise. Wired
     * to the `aiya_core_client_ip` filter, so RateLimiter, CounterService
     * and the comment IP all share one resolution.
     */
    public function resolve(string $remote): string
    {
        if ($this->secret === null) {
            return $remote;
        }
        $given = (string) ($_SERVER[self::SECRET_HEADER] ?? '');
        if ($given === '' || !hash_equals($this->secret, $given)) {
            return $remote;
        }
        $forwarded = trim((string) ($_SERVER[self::FORWARDED_HEADER] ?? ''));
        if ($forwarded === '') {
            return $remote;
        }
        // The front station sends exactly one address; if a chain ever
        // accumulates instead, the first entry is the client it resolved.
        $candidate = trim(explode(',', $forwarded)[0]);

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $remote;
    }

    public function register(): void
    {
        add_filter('aiya_core_client_ip', [$this, 'resolve'], 10, 1);
    }
}
