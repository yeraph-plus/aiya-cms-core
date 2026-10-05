<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

use Aiya\Core\Domain\Identity\UserBan;
use WP_Error;

/**
 * The daily check-in: one small grant bucket per local calendar day, the
 * only earning path the holder drives themselves. The policy lives here —
 * disabled accounts do not earn (the same UserBan rule spending enforces
 * on the other side of the ledger), the settings gate the grant, and the
 * dedupe ref is the site-local calendar date — so any future caller
 * (REST today, wp-cli or a webhook tomorrow) inherits the whole shape.
 * Rate limiting and the response envelope stay with the transport.
 */
final class CheckinService
{
    public function __construct(private LedgerService $ledger = new LedgerService())
    {
    }

    /**
     * Claims today's check-in. The second attempt on the same local day
     * answers aiya_credit_checkin_done (the ledger's dedupe key underneath).
     *
     * @return array{granted:int, balance:int, expiresAt:int}|WP_Error
     */
    public function checkin(int $userId): array|WP_Error
    {
        if (UserBan::isBanned($userId)) {
            return new WP_Error('aiya_account_disabled', __('This account is disabled.', 'aiya-core'), ['status' => 403]);
        }

        $settings = CreditSettings::read();
        if (!$settings['checkinEnabled'] || $settings['checkinCredits'] <= 0) {
            return new WP_Error('aiya_credit_checkin_disabled', __('Check-in is not available.', 'aiya-core'), ['status' => 403]);
        }

        // Local calendar day: the holder's "today" is the site's day.
        $ref = current_time('Y-m-d');
        $expiresAt = time() + $settings['validityDays'] * DAY_IN_SECONDS;

        $granted = $this->ledger->grant($userId, $settings['checkinCredits'], LedgerService::SOURCE_CHECKIN, $ref, $expiresAt);
        if (is_wp_error($granted)) {
            if ($granted->get_error_code() === 'aiya_credit_duplicate') {
                return new WP_Error('aiya_credit_checkin_done', __('Already checked in today.', 'aiya-core'), ['status' => 409]);
            }

            return $granted;
        }

        return [
            'granted' => $settings['checkinCredits'],
            'balance' => $this->ledger->balance($userId),
            'expiresAt' => $expiresAt,
        ];
    }
}
