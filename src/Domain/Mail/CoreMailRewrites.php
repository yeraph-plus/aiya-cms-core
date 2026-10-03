<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

use Aiya\Core\Domain\Identity\PasswordResetService;
use Aiya\Core\Domain\Shared\FrontendDomain;
use WP_User;

/**
 * The per-mail rewrite layer (ruling ⑤): the four WP-native
 * mails whose copy links back to wp-login/profile pages get brand copy
 * and front-end links instead. Each hook returns a finished brand-shell
 * document (MailTemplate::render carries the shell marker, so the wp_mail
 * takeover filter passes it through un-wrapped) and normalises the
 * Content-Type where the hook's shape allows it.
 *
 * Links always resolve through FrontendDomain — the configured front-end
 * origin wins, home_url() is the last resort — exactly the construction
 * PasswordResetService has always used for reset links; the native
 * retrieve_password flow now lands on the same front-end page.
 *
 * Landing outside these four (update reports, recovery mode, privacy,
 * wp-admin invites) stays on the generic takeover: original copy, brand
 * shell.
 */
final class CoreMailRewrites
{
    /** WP's reset keys expire after a day (password_reset_expiration). */
    private const KEY_VALIDITY_FILTER = 'password_reset_expiration';

    public function __construct(private readonly MailTemplate $template, private readonly PasswordResetService $resets = new PasswordResetService())
    {
    }

    public function register(): void
    {
        add_filter('retrieve_password_title', [$this, 'retrievePasswordTitle'], 10, 3);
        add_filter('retrieve_password_message', [$this, 'retrievePasswordMessage'], 10, 4);
        add_filter('wp_new_user_notification_email', [$this, 'newUserEmail'], 10, 3);
        add_filter('password_change_email', [$this, 'passwordChanged'], 10, 3);
        add_filter('email_change_email', [$this, 'emailChanged'], 10, 3);
    }

    /** The shell's brand copy for the native lost-password flow. */
    public function retrievePasswordTitle(string $title, string $userLogin, WP_User $userData): string // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract parameter
    {
        return sprintf(
            /* translators: %s: site name. */
            __('[%s] Password reset', 'aiya-core'),
            wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES)
        );
    }

    /**
     * The reset link moves from wp-login.php to the front end's reset
     * page — same login/key contract the REST flow has always used.
     *
     * @param mixed $userLogin
     * @param mixed $userData
     */
    public function retrievePasswordMessage(mixed $message, mixed $key, mixed $userLogin, mixed $userData): string
    {
        $user = $userData instanceof WP_User ? $userData : null;
        $login = is_string($userLogin) && $userLogin !== '' ? $userLogin : (string) ($user->user_login ?? '');
        $key = is_string($key) ? $key : '';
        if ($user === null || $login === '' || $key === '') {
            return is_string($message) ? $message : '';
        }

        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $url = $this->resets->buildResetUrl('', $login, $key);
        $content = $this->paragraph(sprintf(
            /* translators: %s: site name. */
            __('Someone requested a password reset for your account at %s.', 'aiya-core'),
            esc_html($site)
        ))
            . $this->paragraph(sprintf(
                /* translators: %s: login email address. */
                __('Login email: %s', 'aiya-core'),
                esc_html((string) $user->user_email)
            ))
            . $this->paragraph(__('If this was not you, ignore this email and your password will stay unchanged.', 'aiya-core'))
            . $this->paragraph(sprintf(
                /* translators: %d: number of hours the reset link stays valid. */
                __('Open the link below within %d hours to choose a new password:', 'aiya-core'),
                $this->keyValidityHours()
            ))
            . $this->template->button(__('Reset password', 'aiya-core'), $url);

        return $this->template->render($content, __('Password reset', 'aiya-core'), (string) $user->user_email);
    }

    /** The welcome mail: the reset link points at the front end too.
     *
     * @param array<string, mixed> $email
     * @return array<string, mixed>
     */
    public function newUserEmail(array $email, WP_User $user, string $blogname): array
    {
        $key = $this->newUserKey($email['message'] ?? '');
        if ($key === null) {
            return $email;
        }

        $url = $this->resets->buildResetUrl('', (string) $user->user_login, $key);
        $content = $this->paragraph(sprintf(
            /* translators: %s: site name. */
            __('Your account on %1$s has been created.', 'aiya-core'),
            esc_html(wp_specialchars_decode($blogname, ENT_QUOTES))
        ))
            . $this->paragraph(sprintf(
                /* translators: %d: number of hours the reset link stays valid. */
                __('Open the link below within %d hours to set your password:', 'aiya-core'),
                $this->keyValidityHours()
            ))
            . $this->template->button(__('Set password', 'aiya-core'), $url);

        $email['message'] = $this->template->render($content, __('Your account is ready', 'aiya-core'), (string) $user->user_email);
        // The caller sprints the subject with the blog name after this filter.
        $email['subject'] = __('[%s] Your account is ready.', 'aiya-core'); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the blog name is appended by wp_new_user_notification()
        $email['headers'] = [$this->htmlContentType()];

        return $email;
    }

    /** The security notice for a changed password — the CTA is the front
     * end's reset-request page (no key yet, just the form). The caller
     * sprints the subject with the blog name after this filter, so the
     * `%s` placeholder stays.
     *
     * @param array<string, mixed> $email
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userdata
     * @return array<string, mixed>
     */
    public function passwordChanged(array $email, array $user, array $userdata): array // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract parameter ($userdata names the new address)
    {
        $content = $this->paragraph(__('Your password was just changed.', 'aiya-core'))
            . $this->paragraph(__('If this was not you, someone else may have access — reset your password now:', 'aiya-core'))
            . $this->template->button(__('Reset password', 'aiya-core'), $this->resetRequestUrl());

        $email['message'] = $this->template->render($content, __('Password changed', 'aiya-core'), (string) ($user['user_email'] ?? ''));
        $email['subject'] = __('[%s] Password changed', 'aiya-core'); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the blog name is appended by wp_update_user()
        $email['headers'] = [$this->htmlContentType()];

        return $email;
    }

    /** The security notice for a changed email address (mailed to the OLD address).
     *
     * @param array<string, mixed> $email
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userdata
     * @return array<string, mixed>
     */
    public function emailChanged(array $email, array $user, array $userdata): array
    {
        $rows = $this->template->rows([
            __('Old email', 'aiya-core') => (string) ($user['user_email'] ?? ''),
            __('New email', 'aiya-core') => (string) ($userdata['user_email'] ?? ''),
        ]);
        $content = $this->paragraph(__('Your account email address was just changed.', 'aiya-core'))
            . $rows
            . $this->paragraph(__('If this was not you, someone else may have access — reset your password now:', 'aiya-core'))
            . $this->template->button(__('Reset password', 'aiya-core'), $this->resetRequestUrl());

        $email['message'] = $this->template->render($content, __('Email address changed', 'aiya-core'), (string) ($user['user_email'] ?? ''));
        $email['subject'] = __('[%s] Your email address was changed', 'aiya-core'); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- the blog name is appended by wp_update_user()
        $email['headers'] = [$this->htmlContentType()];

        return $email;
    }

    /** WP hands the welcome mail's message with the reset URL embedded —
        the `key` query argument is what matters; everything else is
        replaced wholesale. */
    private function newUserKey(mixed $message): ?string
    {
        if (!is_string($message) || preg_match('/[?&]key=([A-Za-z0-9_\-]+)/', $message, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function resetRequestUrl(): string
    {
        return FrontendDomain::originOrHome() . FrontendDomain::RESET_PATH;
    }

    private function keyValidityHours(): int
    {
        return max(1, (int) ceil(((int) apply_filters(self::KEY_VALIDITY_FILTER, DAY_IN_SECONDS)) / HOUR_IN_SECONDS));
    }

    private function paragraph(string $html): string
    {
        return '<p style="margin: 0 0 16px;">' . $html . '</p>';
    }

    private function htmlContentType(): string
    {
        return 'Content-Type: text/html; charset=' . ((string) get_option('blog_charset') !== '' ? (string) get_option('blog_charset') : 'UTF-8');
    }
}
