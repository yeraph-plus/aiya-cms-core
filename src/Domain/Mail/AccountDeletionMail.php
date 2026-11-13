<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

use Aiya\Core\Domain\Shared\DateLabels;
use Aiya\Core\Domain\Shared\FrontendDomain;

/**
 * The deletion-request notice (0.136.0): the one mail the self-service
 * flow sends — received, when the execution runs, how to withdraw (sign
 * in once) and the not-you safety line. Best effort like the receipt: the
 * in-app countdown state is authoritative, the mail never blocks or fails
 * the request.
 *
 * The CTA lands on the front end's account settings page — same
 * FrontendDomain-origin exception the receipt rides (the route shape is
 * the front end's own; the mail only points at the site's known origin).
 */
final class AccountDeletionMail
{
    public static function sendRequestNotice(string $toEmail, int $requestedAt, int $effectiveAt): void
    {
        if ($toEmail === '') {
            return;
        }

        $template = MailShell::fromSite()->template();
        $content = self::paragraph(sprintf(
            /* translators: %s: site name. */
            __('We received your request to delete your account on %1$s.', 'aiya-core'),
            esc_html((string) get_option('blogname'))
        ))
            . $template->rows([
                __('Requested at', 'aiya-core') => DateLabels::fromTimestamp($requestedAt),
                __('Deletion runs at', 'aiya-core') => DateLabels::fromTimestamp($effectiveAt),
            ])
            . self::paragraph(__('Until that moment the account is locked. Signing in once before the date above withdraws the request and restores everything as it was.', 'aiya-core'))
            . self::paragraph(__('If you did not request this, someone else may know your password — please change it right away.', 'aiya-core'))
            . $template->button(__('Manage account', 'aiya-core'), FrontendDomain::originOrHome() . '/settings');

        $subject = sprintf(
            /* translators: %s: site name. */
            __('[%1$s] Your account deletion request was received.', 'aiya-core'),
            wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES)
        );
        wp_mail($toEmail, $subject, $template->render($content, __('Signing in once before the deletion date withdraws the request.', 'aiya-core'), $toEmail));
    }

    private static function paragraph(string $html): string
    {
        return '<p style="margin: 0 0 16px;">' . $html . '</p>';
    }
}
