<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Security;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Security hardening for the headless backend, ported from the still-valid
 * surface of the legacy basic-security component.
 *
 * Scope (batch 1 of docs/optimize-migration-assessment.md; the native /wp/v2
 * surface lock and the sitemap toggles moved to HeadlessModule in the
 * current batch — the API lock now lives with the other headless strips):
 *  - force email-address logins for wp-admin;
 *  - optional role gate for the admin back end;
 *  - optional countdown gate for wp-login.php (self-rotating unlock
 *    parameter — the login form only appears after the countdown, no
 *    shared secret to configure);
 *  - cheap request-URI sanity guard against probe traffic;
 *  - CORS origin allowlist for the contract API (rest_allowed_origins).
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
    private const GATE_PARAM = 'login_open';
    private const GATE_WINDOW = 30 * MINUTE_IN_SECONDS;
    private const GATE_DELAY_DEFAULT = 8;

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
            'parent' => 'aiya-core-frontend',
            'option_name' => 'aiya_core_security',
            'fields' => [
                [
                    'id' => 'heading_rest',
                    'type' => 'heading',
                    'label' => __('REST route control', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'rest_allowed_origins',
                    'type' => 'array',
                    'label' => __('REST cross-origin origins', 'aiya-core'),
                    'description' => __('Full front-end origins (scheme://host[:port]) allowed to call this API from the browser, comma-separated. Empty keeps every CORS header off — same-origin deployments need nothing here.', 'aiya-core'),
                    'default' => [],
                ],
                [
                    'id' => 'heading_login',
                    'type' => 'heading',
                    'label' => __('Login restrictions', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'force_email_login',
                    'type' => 'switch',
                    'label' => __('Email-address logins', 'aiya-core'),
                    'checkbox_label' => __('Only accept email addresses as the login field', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'login_param_gate_enable',
                    'type' => 'switch',
                    'label' => __('Login page countdown gate', 'aiya-core'),
                    'checkbox_label' => __('Show a countdown before the wp-login.php form becomes usable', 'aiya-core'),
                    'description' => __('The login form loads by itself after a short countdown; nothing to configure. Credential-stuffing requests that never wait out the countdown reach a page without a form, and the unlock parameter rotates by itself every 30 minutes.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'heading_admin',
                    'type' => 'heading',
                    'label' => __('Admin protection', 'aiya-core'),
                    'level' => '2',
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
                    'id' => 'request_uri_guard',
                    'type' => 'switch',
                    'label' => __('Request URI guard', 'aiya-core'),
                    'checkbox_label' => __('Reject logged-out requests with oversized or probe-shaped URIs (414, REST routes excepted)', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'heading_uninstall',
                    'type' => 'heading',
                    'label' => __('Uninstall', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'note_uninstall',
                    'type' => 'note',
                    'variant' => 'warning',
                    'label' => __('Deleting the plugin keeps all of its data — reinstalling picks up where it left off. The Plugins screen always asks before erasing; the switch below is the standing answer for WP-CLI and scripted uninstalls, and wiping cannot be undone.', 'aiya-core'),
                    'default' => null,
                ],
                [
                    'id' => 'uninstall_purge',
                    'type' => 'switch',
                    'label' => __('Erase data on scripted uninstalls', 'aiya-core'),
                    'checkbox_label' => __('Wipe everything on WP-CLI / scripted uninstalls', 'aiya-core'),
                    'description' => __('Off (default) keeps the data. AIYA_CORE_UNINSTALL_PURGE = true in wp-config.php forces the wipe everywhere and skips the question.', 'aiya-core'),
                    'default' => false,
                ],
            ],
        ]);
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
     * wp-login.php is hijacked for everyone who arrives without the
     * rotating unlock parameter or a fresh unlock cookie: the page renders
     * as a bare countdown (no login form at all — nothing for credential
     * stuffers to submit against) and the browser jumps to the same URL
     * with a time-derived token once the countdown ends. The token is
     * HMAC-derived from a time window, so nothing needs to be configured
     * or remembered: it rotates on its own, accepts the previous window as
     * grace, and a direct POST (the credential-stuffing shape) never
     * carries it.
     */
    public function gateLoginPage(): void
    {
        if (!$this->gateEnabled()) {
            return;
        }

        if ($this->gateUnlocked()) {
            return;
        }

        $this->renderCountdownPage();
    }

    private function gateEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'login_param_gate_enable', false);
    }

    /** The rotating window token; pure in the slot so tests can pin it. */
    public static function gateToken(int $slot): string
    {
        return substr(wp_hash('aiya-core-login-gate|' . $slot, 'nonce'), 0, 20);
    }

    /** The current time window slot (and its immediate predecessor).
     *
     * @return list<int>
     */
    private function gateSlots(): array
    {
        $slot = (int) floor(time() / self::GATE_WINDOW);

        return [$slot, $slot - 1];
    }

    /**
     * The unlock cookie carries a salted hash of the accepted window
     * token, never the token itself; validation accepts the current and
     * previous window so a cookie minted late in one window survives its
     * own TTL across the boundary.
     */
    private function gateUnlocked(): bool
    {
        $presented = isset($_REQUEST[self::GATE_PARAM]) ? wp_unslash((string) $_REQUEST[self::GATE_PARAM]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the derived token itself is the gate; its value is only compared, never stored or rendered.
        if ($presented !== '') {
            foreach ($this->gateSlots() as $slot) {
                if (hash_equals(self::gateToken($slot), $presented)) {
                    $this->setGateCookie($this->gateCookieValue(self::gateToken($slot)));
                    return true;
                }
            }
        }

        $cookie = isset($_COOKIE[self::GATE_COOKIE]) ? (string) $_COOKIE[self::GATE_COOKIE] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        if ($cookie !== '') {
            foreach ($this->gateSlots() as $slot) {
                if (hash_equals($this->gateCookieValue(self::gateToken($slot)), $cookie)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function setGateCookie(string $value): void
    {
        if (isset($_COOKIE[self::GATE_COOKIE]) && hash_equals((string) $_COOKIE[self::GATE_COOKIE], $value)) {
            return;
        }

        setcookie(self::GATE_COOKIE, $value, [
            'expires' => time() + self::GATE_COOKIE_TTL,
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** The cookie carries a salted hash of the window token, never the token itself. */
    public static function gateCookieValue(string $token): string
    {
        return hash('sha256', 'aiya_core_login_gate|' . $token);
    }

    /**
     * The hijacked login screen: a bare countdown card, no form, no
     * username field — after the delay the browser re-enters the same URL
     * carrying the current window token. A noscript anchor keeps the page
     * usable without JS; the token is in the page either way, the wait is
     * the friction.
     */
    private function renderCountdownPage(): never
    {
        $delay = (int) apply_filters('aiya_core_login_gate_delay', self::GATE_DELAY_DEFAULT);
        $delay = min(60, max(3, $delay));
        $slots = $this->gateSlots();

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/wp-login.php'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- embedded through add_query_arg + esc_js below, never echoed raw
        $unlockUrl = add_query_arg([self::GATE_PARAM => self::gateToken($slots[0])], home_url($uri));

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width">
<title><?php echo esc_html($site); ?> &rsaquo; <?php esc_html_e('Sign in', 'aiya-core'); ?></title>
<style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f0f0f1; color: #3c434a; font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .card { width: 320px; padding: 26px 24px; background: #fff; border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0,0,0,.04); text-align: center; }
    .card h1 { font-size: 20px; margin: 0 0 12px; }
    .count { font-size: 34px; font-weight: 600; margin: 10px 0; }
    p { margin: 8px 0; }
    a { color: #2271b1; }
</style>
</head>
<body>
<div class="card">
    <h1><?php echo esc_html($site); ?></h1>
    <p><?php esc_html_e('This sign-in page opens automatically.', 'aiya-core'); ?></p>
    <div class="count" id="aiya-gate-count"><?php echo (int) $delay; ?></div>
    <p><?php esc_html_e('Please wait a moment — the sign-in form loads by itself.', 'aiya-core'); ?></p>
    <noscript><p><a href="<?php echo esc_url($unlockUrl); ?>"><?php esc_html_e('Continue to the sign-in form', 'aiya-core'); ?></a></p></noscript>
</div>
<script>
(function () {
    var left = <?php echo (int) $delay; ?>,
        url = <?php echo wp_json_encode((string) $unlockUrl); ?>,
        node = document.getElementById('aiya-gate-count');
    var timer = setInterval(function () {
        left -= 1;
        if (left <= 0) {
            clearInterval(timer);
            window.location.replace(url);
            return;
        }
        if (node) {
            node.textContent = String(left);
        }
    }, 1000);
})();
</script>
</body>
</html>
        <?php
        exit;
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
        if (self::isBlockedUri($uri)) {
            status_header(414);
            nocache_headers();
            exit;
        }
    }

    /**
     * The guard's decision, pure so it is testable without the exit.
     *
     * REST requests are exempt. The rule was born in the legacy theme's
     * basic-optimize as a front-end path filter — probe shapes like
     * `eval(` or `/**` name files that get executed — while a REST request
     * is parsed as data by the REST layer. The ceiling is what broke:
     * the Epay gateway push is an anonymous GET whose signed query alone
     * runs ~300 bytes, so covering /wp-json rejected payments (414) rather
     * than probes. Everything else, including every front-end path, keeps
     * the original limits.
     */
    public static function isBlockedUri(string $uri): bool
    {
        if (str_contains($uri, '/' . rest_get_url_prefix() . '/')) {
            return false;
        }

        // Plain-permalink REST form: /?rest_route=%2Faiya%2Fcore%2Fv1%2F…
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing hint read at init, not form processing
        if (isset($_GET['rest_route']) && is_string($_GET['rest_route']) && $_GET['rest_route'] !== '') {
            return false;
        }

        return strlen($uri) > 255
            || stripos($uri, 'eval(') !== false
            || stripos($uri, 'base64') !== false
            || strpos($uri, '/**/') !== false;
    }
}
