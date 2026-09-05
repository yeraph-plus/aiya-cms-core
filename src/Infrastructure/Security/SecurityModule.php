<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Security;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Security hardening for the headless backend, ported from the still-valid
 * surface of the legacy basic-security component.
 *
 * Scope (batch 1 of docs/optimize-migration-assessment.md):
 *  - deny anonymous enumeration of users over REST (/wp/v2/users) — author
 *    data reaches the front end through the M4 AuthorDto instead;
 *  - drop the users provider from WP sitemaps;
 *  - force email-address logins for wp-admin;
 *  - optional role gate for the admin back end;
 *  - optional secret-parameter gate for wp-login.php;
 *  - cheap request-URI sanity guard against probe traffic.
 *
 * Username blocklisting (the legacy logged_sanitize_user_* group) was
 * dropped on purpose: the owner decided against it. Every toggle is
 * evaluated lazily at callback time so the settings page stays the single
 * source of truth; no master switch — each hardening measure stands alone.
 */
final class SecurityModule implements Module
{
    private const PAGE_SLUG = 'security';
    private const GATE_COOKIE = 'aiya_core_login_gate';
    private const GATE_COOKIE_TTL = 10 * MINUTE_IN_SECONDS;

    private const CAPABILITY_BY_ROLE = [
        'subscriber' => 'read',
        'contributor' => 'edit_posts',
        'author' => 'publish_posts',
        'editor' => 'publish_pages',
        'administrator' => 'manage_options',
    ];

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        add_action('init', [$this, 'guardRequestUri'], 5);
        add_action('admin_init', [$this, 'guardBackend']);
        add_action('login_init', [$this, 'gateLoginPage']);
        add_filter('authenticate', [$this, 'forceEmailLogin'], 20, 3);
        add_filter('rest_endpoints', [$this, 'filterUserEndpoints']);
        add_filter('wp_sitemaps_add_provider', [$this, 'filterSitemapProviders'], 10, 2);
    }

    /** @return bool True when a hardening toggle is enabled; defaults keep the passive measures on. */
    private function enabled(string $field): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, $field, true);
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Security hardening', 'aiya-core'),
            'menu_title' => __('Security hardening', 'aiya-core'),
            'parent' => 'aiya-core-sample',
            'option_name' => 'aiya_core_security',
            'fields' => [
                [
                    'id' => 'guard_rest_users',
                    'type' => 'switch',
                    'label' => __('REST user enumeration', 'aiya-core'),
                    'checkbox_label' => __('Remove the /wp/v2/users endpoints for anonymous visitors', 'aiya-core'),
                    'description' => __('Author data reaches the front end through the post DTOs. Admin screens that read /wp/v2/users (media library author filter) degrade; turn off if that matters.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'hide_sitemap_users',
                    'type' => 'switch',
                    'label' => __('Sitemap user list', 'aiya-core'),
                    'checkbox_label' => __('Drop the users provider from WP sitemaps', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'force_email_login',
                    'type' => 'switch',
                    'label' => __('Email-address logins', 'aiya-core'),
                    'checkbox_label' => __('Only accept email addresses as the login field', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'admin_backend_min_role',
                    'type' => 'select',
                    'label' => __('Admin back end minimum role', 'aiya-core'),
                    'description' => __('Users below the selected role are redirected away from wp-admin. Off by default.', 'aiya-core'),
                    'default' => 'off',
                    'options' => [
                        'off' => __('Off', 'aiya-core'),
                        'subscriber' => __('Subscriber and above', 'aiya-core'),
                        'contributor' => __('Contributor and above', 'aiya-core'),
                        'author' => __('Author and above', 'aiya-core'),
                        'editor' => __('Editor and above', 'aiya-core'),
                        'administrator' => __('Administrator only', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'login_param_gate_enable',
                    'type' => 'switch',
                    'label' => __('Login page parameter gate', 'aiya-core'),
                    'checkbox_label' => __('wp-login.php only loads with the secret parameter below', 'aiya-core'),
                    'description' => __('Obscurity, not authentication: keep the value long and treat it as a compliment to credentials, never a replacement.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'login_param_gate_value',
                    'type' => 'text',
                    'label' => __('Login gate parameter value', 'aiya-core'),
                    'description' => __('Reach the login screen via /wp-login.php?auth=<value>; the pass persists for 10 minutes as a cookie and covers the full flow including password reset. Leave empty to disable the gate regardless of the switch above.', 'aiya-core'),
                    'default' => '',
                    'attributes' => ['autocomplete' => 'off'],
                ],
                [
                    'id' => 'request_uri_guard',
                    'type' => 'switch',
                    'label' => __('Request URI guard', 'aiya-core'),
                    'checkbox_label' => __('Reject logged-out requests with oversized or probe-shaped URIs (414)', 'aiya-core'),
                    'default' => true,
                ],
            ],
        ]);
    }

    /**
     * Denies anonymous user enumeration over REST by removing the users
     * collection and single-user routes. Application-password sub-routes
     * stay registered (permission-gated) so authenticated writes in M5 keep
     * working.
     *
     * @param array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public function filterUserEndpoints(array $endpoints): array
    {
        if (!$this->enabled('guard_rest_users')) {
            return $endpoints;
        }

        unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);

        return $endpoints;
    }

    /**
     * Removes the users provider from WP sitemaps.
     *
     * @param mixed $provider
     */
    public function filterSitemapProviders(mixed $provider, string $name): mixed
    {
        if ($this->enabled('hide_sitemap_users') && $name === 'users') {
            return false;
        }

        return $provider;
    }

    /**
     * Forces the login field to be an email address. The rejection must run
     * before earlier authenticate handlers are honored: the core username
     * handler already returned a WP_User by the time this fires at priority
     * 20. Email logins continue natively via wp_authenticate_email_password,
     * so no credential resolution happens here.
     */
    public function forceEmailLogin(mixed $user, string $username, string $password): mixed
    {
        if (!$this->enabled('force_email_login')) {
            return $user;
        }
        if ($username !== '' && !is_email($username)) {
            return new \WP_Error('invalid_email_login', __('Please log in with your email address.', 'aiya-core'));
        }

        return $user;
    }

    /**
     * Redirects users below the configured minimum role away from wp-admin.
     */
    public function guardBackend(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        $minimum = (string) aiya_core_opt(self::PAGE_SLUG, 'admin_backend_min_role', 'off');
        if ($minimum === 'off' || !isset(self::CAPABILITY_BY_ROLE[$minimum])) {
            return;
        }
        if (current_user_can(self::CAPABILITY_BY_ROLE[$minimum])) {
            return;
        }

        wp_safe_redirect(home_url('/'));
        exit;
    }

    /**
     * wp-login.php only loads for visitors holding the secret. The presented
     * secret (query string or form field) exchanges for a short-lived cookie
     * that unlocks the whole flow — the form POST back to wp-login.php never
     * carries the original query parameter, and lost-password / reset forms
     * are separate submissions as well.
     */
    public function gateLoginPage(): void
    {
        $expected = $this->gateSecret();
        if ($expected === null) {
            return;
        }

        $presented = isset($_REQUEST['auth']) ? wp_unslash((string) $_REQUEST['auth']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the secret itself is the gate; its value is only compared, never stored or rendered.
        if ($presented !== '' && hash_equals($expected, $presented)) {
            if (!isset($_COOKIE[self::GATE_COOKIE]) || !hash_equals($this->gateCookieValue($expected), (string) $_COOKIE[self::GATE_COOKIE])) {
                setcookie(self::GATE_COOKIE, $this->gateCookieValue($expected), [
                    'expires' => time() + self::GATE_COOKIE_TTL,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            return;
        }

        $cookie = isset($_COOKIE[self::GATE_COOKIE]) ? (string) $_COOKIE[self::GATE_COOKIE] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        if ($cookie !== '' && hash_equals($this->gateCookieValue($expected), $cookie)) {
            return;
        }

        wp_die(
            esc_html__('Not found.', 'aiya-core'),
            '',
            ['response' => 404]
        );
    }

    private function gateSecret(): ?string
    {
        if (!(bool) aiya_core_opt(self::PAGE_SLUG, 'login_param_gate_enable', false)) {
            return null;
        }
        $expected = trim((string) aiya_core_opt(self::PAGE_SLUG, 'login_param_gate_value', ''));

        return $expected !== '' ? $expected : null;
    }

    /** The cookie carries a salted hash of the secret, never the secret itself. */
    private function gateCookieValue(string $expected): string
    {
        return hash('sha256', 'aiya_core_login_gate|' . $expected);
    }

    /**
     * Cheap probe filter: logged-out requests whose URI is oversized or
     * carries classic exploit shapes are dropped with 414 before any
     * query work happens.
     */
    public function guardRequestUri(): void
    {
        if (!$this->enabled('request_uri_guard') || is_user_logged_in()) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $suspicious = strlen($uri) > 255
            || stripos($uri, 'eval(') !== false
            || stripos($uri, 'base64') !== false
            || strpos($uri, '/**/') !== false;

        if ($suspicious) {
            status_header(414);
            nocache_headers();
            exit;
        }
    }
}
