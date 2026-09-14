<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

/**
 * Normalized reader for the credit policy: the check-in settings live on
 * the bespoke credits admin card, the ledger retention on the Frontend
 * page next to the notification retention (2026-09-13 rework). The
 * credit domain is bookkeeping only — it never prices a downstream
 * action; the caller passes the amount into LedgerService::spend().
 */
final class CreditSettings
{
    public const OPTION_NAME = 'aiya_core_credit';
    public const DEFAULT_RETENTION_DAYS = 30;
    private const MAX_RETENTION_DAYS = 3650;

    /**
     * @return array{checkinEnabled:bool, checkinCredits:int, validityDays:int}
     */
    public static function read(): array
    {
        $settings = (array) get_option(self::OPTION_NAME, []);

        return [
            'checkinEnabled' => (bool) ($settings['checkin_enable'] ?? true),
            'checkinCredits' => max(0, (int) ($settings['checkin_credits'] ?? 5)),
            'validityDays' => max(1, (int) ($settings['credit_validity_days'] ?? 30)),
        ];
    }

    /** Ledger retention, configured on the Frontend page (`credit_retention`). */
    public static function retentionDays(): int
    {
        $days = absint((string) aiya_core_opt('frontend', 'credit_retention', self::DEFAULT_RETENTION_DAYS));

        return $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::DEFAULT_RETENTION_DAYS;
    }
}
