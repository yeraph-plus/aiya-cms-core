<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

/**
 * Normalized reader for the credit policy. The check-in settings live on
 * the membership settings page (option `aiya_core_sponsorship`, where the
 * 0.55.0 rework moved them) and the ledger retention on the Frontend page
 * next to the notification retention. The credit domain is bookkeeping
 * only — it never prices a downstream action; the caller passes the amount
 * into LedgerService::spend().
 */
final class CreditSettings
{
    public const DEFAULT_RETENTION_DAYS = 30;
    private const MAX_RETENTION_DAYS = 3650;

    /**
     * @return array{checkinEnabled:bool, checkinCredits:int, validityDays:int}
     */
    public static function read(): array
    {
        return [
            'checkinEnabled' => (bool) aiya_core_opt('sponsorship', 'checkin_enable', true),
            'checkinCredits' => max(0, (int) aiya_core_opt('sponsorship', 'checkin_credits', 5)),
            'validityDays' => max(1, (int) aiya_core_opt('sponsorship', 'credit_validity_days', 30)),
        ];
    }

    /** Ledger retention, configured on the Frontend page (`credit_retention`). */
    public static function retentionDays(): int
    {
        $days = absint((string) aiya_core_opt('frontend', 'credit_retention', self::DEFAULT_RETENTION_DAYS));

        return $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::DEFAULT_RETENTION_DAYS;
    }
}
