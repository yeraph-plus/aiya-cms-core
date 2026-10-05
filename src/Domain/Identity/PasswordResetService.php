<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Domain\Shared\FrontendDomain;
use WP_Error;
use WP_User;

/**
 * Password reset mail for the headless front end. The reset key is the
 * native WordPress key (get_password_reset_key), but the link points at
 * the Astro front end. The link origin is site-owned since 0.97.0: the
 * Frontend page's "frontend domain" (frontend_domain) is authoritative
 * when set, so a client-reported origin can never steer a live reset
 * link anywhere the site owner did not choose. Without the setting a
 * client-reported origin is honored only while it names this site's own
 * host, and everything else falls back to the site URL — the mail must
 * always contain a working link.
 */
final class PasswordResetService
{
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
        return FrontendDomain::resetUrlOnOrigin($this->resolveOrigin($frontendOrigin), $login, $key);
    }

    /**
     * Resolves the link origin: the configured frontend domain wins
     * outright, a client-reported origin is honored only while it names
     * this site's own host, and the site URL is the last resort. The
     * configured value survives the same normalization as the reported
     * one (scheme-less input reads as https; the port is kept).
     */
    private function resolveOrigin(string $frontendOrigin): string
    {
        $origin = FrontendDomain::origin();
        if ($origin !== null) {
            return $origin;
        }

        $origin = FrontendDomain::normalize($frontendOrigin);
        if ($origin !== null && $this->namesSiteHost($origin)) {
            return $origin;
        }

        return (string) home_url();
    }

    /**
     * Host-only comparison on purpose: the site's own host on any port
     * is still the site. A distinct front-end host goes through the
     * configured frontend domain instead of a client report.
     */
    private function namesSiteHost(string $origin): bool
    {
        $host = wp_parse_url($origin, PHP_URL_HOST);
        $siteHost = wp_parse_url((string) home_url(), PHP_URL_HOST);

        return is_string($host) && is_string($siteHost) && strcasecmp($host, $siteHost) === 0;
    }

    private function validityHours(): int
    {
        $ttl = (int) apply_filters('password_reset_expiration', DAY_IN_SECONDS);

        return max(1, (int) ceil($ttl / HOUR_IN_SECONDS));
    }
}
