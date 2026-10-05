<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shared;

/**
 * The canonical front-end origin: the Frontend page's "frontend domain"
 * (frontend_domain, 0.97.0). The back end names the front end itself in
 * a growing set of places — password-reset links, the admin-bar shortcut
 * — and every consumer must resolve the SAME value the same way, so the
 * normalization lives here and nowhere else.
 *
 * A configured value reduces to scheme + host + optional port; scheme-less
 * input reads as https, credentials/paths/queries are rejected (not
 * stripped), and unusable input answers null — callers fall back to their
 * own site-local default.
 */
final class FrontendDomain
{
    /** The front end's password-reset surface (request form and the
        login/key deep link both live here). */
    public const RESET_PATH = '/reset-password';

    /**
     * The configured front-end origin, or null when unset or unusable.
     */
    public static function origin(): ?string
    {
        return self::normalize((string) aiya_core_opt('frontend', 'frontend_domain', ''));
    }

    /**
     * The front-end origin with a site-local fallback — the resolution
     * every mail-facing link uses (mails cannot ship a relative path).
     */
    public static function originOrHome(): string
    {
        return self::origin() ?? (string) home_url();
    }

    /**
     * The password-reset deep link on the front end: `login` and `key`
     * are the exact query contract the reset page (and the REST flow)
     * already speak.
     */
    public static function resetUrl(string $login, string $key): string
    {
        return self::resetUrlOnOrigin(self::originOrHome(), $login, $key);
    }

    /**
     * The reset deep link on an explicit origin — the composition
     * primitive behind resetUrl(); callers with their own origin policy
     * (the reset flow honors a guarded client-reported origin) compose
     * through this so the path/query contract is written once.
     */
    public static function resetUrlOnOrigin(string $origin, string $login, string $key): string
    {
        return add_query_arg(
            ['login' => $login, 'key' => $key],
            $origin . self::RESET_PATH
        );
    }

    /**
     * Reduces an origin to scheme + host (no path, no query, no
     * credentials); null when it is not a usable web origin. Scheme-less
     * input reads as https. The explicit port survives: local dev front
     * ends always carry one.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = wp_parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        // parse_url is a lenient splitter, not a validator — a host like
        // '::' or 'not a host' passes straight through. A front-end
        // origin is a hostname or a bracketed IPv6 literal; anything
        // else is rejected wholesale.
        if (!preg_match('/^\[[0-9a-f:.]+\]$/', $host)
            && !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $host)
        ) {
            return null;
        }

        // Userinfo in the origin is never legitimate for a front-end host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // Keep the explicit port: local dev front ends always carry one.
        $port = isset($parts['port']) && is_int($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
