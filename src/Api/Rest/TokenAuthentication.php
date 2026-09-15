<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Identity\TokenStore;

/**
 * Bearer-token authentication for the headless API: resolves
 * `Authorization: Bearer {token}` to a user via the TokenStore. Cookie
 * sessions keep working untouched — this only kicks in when WordPress
 * has no user yet and the header is present.
 *
 * Scope is deliberately restricted to the versioned contract namespace
 * (and its gateway callbacks): a bearer token must not authenticate
 * admin-ajax, xmlrpc or the native /wp/v2 surface — it is a 14-day
 * credential held by a browser SPA, so its blast radius stays the
 * contract API only.
 */
final class TokenAuthentication
{
    public function __construct(private TokenStore $tokens)
    {
    }

    public function register(): void
    {
        add_filter('determine_current_user', [$this, 'authenticate'], 20);
    }

    /**
     * @param int|false $userId
     * @return int|false
     */
    public function authenticate(int|false $userId): int|false
    {
        if ($userId) {
            return $userId;
        }

        if (!$this->tokenAppliesHere()) {
            return $userId;
        }

        $token = $this->presentedToken();

        return $token !== null ? $this->tokens->resolve($token) : $userId;
    }

    /**
     * Tokens only authenticate requests to the plugin's own namespaces —
     * everywhere else (admin-ajax, /wp/v2, xmlrpc) the header is ignored,
     * so a leaked token gains nothing outside the contract API. Matching
     * runs against the URL PATH only: the raw REQUEST_URI includes the
     * query string, and a stray `?x=/aiya/core/v1/` marker must not
     * re-enable token resolution outside the namespaces.
     */
    private function tokenAppliesHere(): bool
    {
        $uri = '';
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $uri = wp_unslash($_SERVER['REQUEST_URI']);
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return str_contains($path, '/aiya/core/v1/') || str_contains($path, '/aiya/sponsorship/v1/');
    }

    /** The presented bearer token, or null when the header is absent/malformed. */
    public function presentedToken(): ?string
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }

        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
