<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

use Aiya\Core\Contracts\Module;

/**
 * The mail domain: silences the core comment notification emails for the
 * headless split and applies the brand shell to every wp_mail() message.
 *
 * ### Silenced admin mails
 *
 * wp_notify_postauthor() and wp_notify_moderator() build their links from
 * get_permalink() / get_comment_link() — permalinks on the WP domain, where
 * the shell page renders no content. With the front end on its own origin
 * every "see it here" link in those mails dead-ends on the shell, and the
 * in-site notification system (Domain/Notification/NotificationActions)
 * already covers the same events for the people those mails were for.
 *
 * The `notify_post_author` and `notify_moderator` filters are the core's
 * last-word gates: they override the comments_notify / moderation_notify
 * options on every comment insertion path without touching the stored
 * values. Unconditional by design — no consumer is left for these mails,
 * so no setting is exposed (the stored options keep their values and any
 * moderation behaviour itself is untouched; only the mails go).
 *
 * Note the value shapes differ: notify_post_author receives a bool, while
 * notify_moderator receives the raw moderation_notify option value (a
 * string '0'/'1' as stored) — hence the untyped parameter.
 *
 * Two more admin-facing mails are silenced by ruling (2026-10-03): the
 * new-registration notice (the wp_send_new_user_notification_to_admin
 * gate — the USER welcome leg keeps its branded rewrite) and the
 * password-reset admin notice (wp_password_change_notification, unhooked
 * from after_password_reset — the reset link mail the user receives is
 * the thing that matters). Both are pure noise on this site: there is no
 * admin consumption for registration or reset events.
 *
 * ### The brand shell
 *
 * A `wp_mail` args filter (late priority, so third-party arg rewrites run
 * first) wraps every message in MailTemplate's shell and normalises the
 * Content-Type — content and style only, delivery stays on WordPress's
 * native chain (mail-design.md ②⑥, 2026-10-03). Third-party SMTP plugins
 * hooking phpmailer_init keep working underneath the shell, but with the
 * content already branded.
 */
final class MailModule implements Module
{
    public function register(): void
    {
        // $maybeNotify rides WP's filter signature only — this hook is the
        // final veto gate (WP 4.4+), it never reads the proposed value.
        add_filter('notify_post_author', static fn (mixed $maybeNotify): bool => false); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract parameter
        add_filter('notify_moderator', static fn (mixed $maybeNotify): bool => false); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract parameter

        // The new-registration admin notice is noise here (2026-10-03):
        // the user's own branded welcome mail carries the reset link.
        add_filter('wp_send_new_user_notification_to_admin', static fn (mixed $maybeNotify): bool => false); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter contract parameter

        // So is the reset-completed admin notice (2026-10-03): the user
        // already got the branded "password changed" security mail.
        remove_action('after_password_reset', 'wp_password_change_notification', 10);

        $shell = MailShell::fromSite();
        add_filter('wp_mail', [$shell, 'apply'], 999);
        (new CoreMailRewrites($shell->template()))->register();
    }
}
