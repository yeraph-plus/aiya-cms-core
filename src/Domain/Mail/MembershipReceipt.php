<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Shared\DateLabels;
use Aiya\Core\Domain\Shared\FrontendDomain;

/**
 * The activation receipt (2026-10-03): the branded bill a holder gets
 * when a purchase joins their entitlement queue — tier, order id and the
 * coverage window this purchase contributed, with the CTA on the front
 * end's membership page. Best effort by design: the in-site notification
 * (the Notification domain's listener on the same event) is the
 * authoritative notice, the mail never blocks or fails the activation.
 *
 * This is the mail domain's single hook into core business flow (the
 * owner's ruling): everything else the domain does rides WP's own mail
 * behaviour — the wp_mail takeover and the native-mail rewrites. The
 * order row arrives through the Membership service, keeping the
 * entitlement queue table inside its own domain.
 */
final class MembershipReceipt
{
    public function __construct(
        private readonly MailTemplate $template,
        private readonly EntitlementService $entitlements = new EntitlementService(),
    ) {
    }

    public function register(): void
    {
        add_action('aiya_core_membership_activated', [$this, 'send'], 10, 2);
    }

    public function send(int $userId, string $orderId): void
    {
        $holder = get_userdata($userId);
        if ($holder === false || $holder->user_email === '') {
            return;
        }

        $row = $this->entitlements->orderBy($orderId);
        if ($row === null) {
            return;
        }

        $content = $this->paragraph(sprintf(
            /* translators: %s: site name. */
            __('Thank you for supporting %1$s — this email is your receipt.', 'aiya-core'),
            esc_html(wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES))
        ))
            . $this->template->rows([
                // Context-disambiguated: the generic "Tier"/"Order" strings
                // already exist for the admin tables; the receipt translates
                // them differently and gettext forbids duplicate msgids.
                _x('Tier', 'membership receipt', 'aiya-core') => $row['tier_name'],
                _x('Order', 'membership receipt', 'aiya-core') => $orderId,
                __('Active from', 'aiya-core') => DateLabels::fromGmt($row['starts_at'], false),
                __('Active until', 'aiya-core') => DateLabels::fromGmt($row['ends_at'], false),
            ])
            . $this->paragraph(__('If your purchase covers multiple cycles, the next one starts automatically when the current subscription period ends.', 'aiya-core'))
            . $this->template->button(__('View membership', 'aiya-core'), FrontendDomain::originOrHome() . '/profile/me/');

        $subject = sprintf(
            /* translators: %s: site name. */
            __('[%1$s] Thank you — your membership is now active.', 'aiya-core'),
            wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES)
        );
        wp_mail((string) $holder->user_email, $subject, $this->template->render($content, __('Membership activated', 'aiya-core'), $holder->user_email));
    }

    private function paragraph(string $html): string
    {
        return '<p style="margin: 0 0 16px;">' . $html . '</p>';
    }
}
