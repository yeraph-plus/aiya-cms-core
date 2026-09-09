<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use WP_Error;
use WP_User;

/**
 * Password reset mail for the headless front end. The reset key is the
 * native WordPress key (get_password_reset_key), but the link points at
 * the Astro front end: the requesting client passes its own origin, and
 * the service concatenates origin + /reset-password so the whole flow
 * stays on the front end instead of wp-login.php.
 *
 * A malformed or unapproved origin falls back to the site URL rather than
 * failing — the mail must always contain a working link. The site's own
 * host is the only default target; extra front-end hosts are configured
 * on the Security settings page or pinned down with the
 * `aiya_core_password_reset_allowed_hosts` filter.
 */
final class PasswordResetService
{
    private const RESET_PATH = '/reset-password';

    /**
     * Generates the key and mails the front-end reset link. Errors are
     * returned as WP_Error; `true` means the mail was handed to wp_mail().
     *
     * @return true|WP_Error
     */
    public function sendResetLink(WP_User $user, string $frontendOrigin = '')
    {
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            return $key;
        }

        $url = $this->buildResetUrl($frontendOrigin, $user->user_login, $key);

        $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $subject = sprintf(
            /* translators: %s: site name. */
            __('[%s] Password reset', 'aiya-core'),
            (string) $site
        );

        $message = sprintf(
            /* translators: %s: site name. */
            __('Someone requested a password reset for your account at %s.', 'aiya-core'),
            (string) $site
        ) . "\r\n\r\n";
        $message .= sprintf(
            /* translators: %s: login email address. */
            __('Login email: %s', 'aiya-core'),
            $user->user_email
        ) . "\r\n\r\n";
        $message .= __('If this was not you, ignore this email and your password will stay unchanged.', 'aiya-core') . "\r\n\r\n";
        $message .= sprintf(
            /* translators: %d: number of hours the reset link stays valid. */
            __('Open the link below within %d hours to choose a new password:', 'aiya-core'),
            $this->validityHours()
        ) . "\r\n\r\n";
        $message .= $url . "\r\n";

        if (!wp_mail($user->user_email, $subject, $message)) {
            return new WP_Error('aiya_mail_failed', __('The password reset email could not be sent.', 'aiya-core'));
        }

        return true;
    }

    /**
     * Front-end origin + reset path + `login`/`key` query args.
     */
    public function buildResetUrl(string $frontendOrigin, string $login, string $key): string
    {
        $origin = $this->normalizeOrigin($frontendOrigin);
        if ($origin === null || !$this->originAllowed($origin)) {
            $origin = home_url();
        }

        return add_query_arg(
            ['login' => $login, 'key' => $key],
            $origin . self::RESET_PATH
        );
    }

    /**
     * The site's own host is always acceptable. Any other front-end host
     * must be configured on the Security settings page or through the
     * `aiya_core_password_reset_allowed_hosts` filter — an anonymous
     * client never gets to point a live reset link at an arbitrary host.
     */
    private function originAllowed(string $origin): bool
    {
        $host = wp_parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $homeHost = wp_parse_url((string) home_url(), PHP_URL_HOST);
        if (is_string($homeHost) && strcasecmp($host, $homeHost) === 0) {
            return true;
        }

        $configured = (array) aiya_core_opt('security', 'password_reset_allowed_hosts', []);
        $allowed = array_map(
            'strtolower',
            array_merge(
                array_map('strval', $configured),
                array_map('strval', (array) apply_filters('aiya_core_password_reset_allowed_hosts', []))
            )
        );

        return in_array(strtolower($host), $allowed, true);
    }

    /**
     * Reduces the client-supplied origin to scheme + host (no path, no
     * query, no credentials); null when it is not a usable web origin.
     */
    private function normalizeOrigin(string $raw): ?string
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

        // Userinfo in the origin is never legitimate for a front-end host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // Keep the explicit port: local dev front ends always carry one.
        $port = isset($parts['port']) && is_int($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function validityHours(): int
    {
        $ttl = (int) apply_filters('password_reset_expiration', DAY_IN_SECONDS);

        return max(1, (int) ceil($ttl / HOUR_IN_SECONDS));
    }
}
