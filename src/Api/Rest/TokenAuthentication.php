<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Payment\PaymentGateway;

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

    /**
     * The namespaces a presented bearer token may authenticate against.
     * Pulled from the owning classes' constants — NOT from the
     * first-party endpoint-discovery filter: discovery is not an opt-in to
     * visitor authentication. A namespace explicitly joins this list.
     *
     * @return list<string>
     */
    private function namespaces(): array
    {
        return [
            Contract::API_NAMESPACE,
            IntegrationsController::API_NAMESPACE,
            PaymentGateway::GATEWAY_NAMESPACE,
        ];
    }

    public function register(): void
    {
        add_filter('rest_authentication_errors', [$this, 'authenticate'], 20);
    }

    /**
     * Runs after WordPress resolves rewrite/query/body routing and before
     * the native cookie-nonce check. Non-REST entrypoints never invoke it.
     *
     * @param mixed $result Earlier authentication result.
     */
    public function authenticate(mixed $result): mixed
    {
        if ($result !== null || get_current_user_id() > 0) {
            return $result;
        }

        if (!$this->tokenAppliesHere()) {
            return $result;
        }

        $token = $this->presentedToken();

        $userId = $token !== null ? $this->tokens->resolve($token) : 0;
        if ($userId > 0) {
            wp_set_current_user($userId);
        }

        return $result;
    }

    /**
     * Tokens only authenticate requests to the plugin's own namespaces —
     * everywhere else (admin-ajax, /wp/v2, xmlrpc) the header is ignored,
     * so a leaked token gains nothing outside the contract API. The
     * integrations namespace is in the list for the companion-service
     * ticket flow: minting a ticket IS the bearer's one job there. A
     * presented service key never matches (no `{userId}.{secret}` shape).
     * Only WordPress's resolved route is authoritative. Testing the raw
     * URL as an alternative would authenticate a native route overridden
     * by rest_route while the visible path names our namespace.
     */
    private function tokenAppliesHere(): bool
    {
        global $wp;
        $route = $wp->query_vars['rest_route'] ?? null;
        if (!is_string($route)) {
            return false;
        }
        $route = untrailingslashit($route);

        foreach ($this->namespaces() as $namespace) {
            $prefix = '/' . trim($namespace, '/');
            if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
                return true;
            }
        }

        return false;
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
