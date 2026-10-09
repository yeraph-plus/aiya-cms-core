<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

/**
 * Normalized reader for the credit policy. The check-in settings live on
 * the membership settings page — page slug `membership`, option name
 * `aiya_core_membership` (deliberately different; aiya_core_opt() keys on
 * the SLUG, so passing the option's own suffix here reads nothing and
 * every value silently falls back to its default). The ledger retention
 * lives on the content-management page next to the notification retention. The
 * credit domain is bookkeeping only — it never prices a downstream
 * action; the caller passes the amount into LedgerService::spend().
 *
 * The spend waiver is the one piece of policy the ledger itself holds:
 * holders at or above the configured role level spend at zero across
 * every consumer (FileServe delivery, companion-service spending), with
 * the waived spend still booked and announced. The level maps to a
 * WordPress capability so the check stays role-hierarchy-native —
 * `user_can()` on the holder, not the session, because machine callers
 * (the integration service key) spend on behalf of a user without ever
 * being that user.
 */
final class CreditSettings
{
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Upper bounds for the credit policy. The settings schema, the admin
     * forms and the server-side validation all read these, so a limit is
     * stated once and the three cannot drift apart.
     */
    public const MAX_RETENTION_DAYS = 3650;
    public const MAX_VALIDITY_DAYS = 3650;
    public const MAX_AMOUNT = 100000;

    /** Role levels the waiver radio offers, lowest first. */
    public const EXEMPT_LEVELS = ['author', 'editor', 'administrator'];
    public const DEFAULT_EXEMPT_LEVEL = 'administrator';

    /** The capability each level stands for; higher levels hold it too. */
    private const LEVEL_CAPABILITIES = [
        'author' => 'publish_posts',
        'editor' => 'edit_others_posts',
        'administrator' => 'manage_options',
    ];

    /**
     * @return array{checkinEnabled:bool, checkinCredits:int, validityDays:int}
     */
    public static function read(): array
    {
        return [
            'checkinEnabled' => (bool) aiya_core_opt('membership', 'checkin_enable', true),
            'checkinCredits' => max(0, (int) aiya_core_opt('membership', 'checkin_credits', 5)),
            'validityDays' => max(1, (int) aiya_core_opt('membership', 'credit_validity_days', 30)),
        ];
    }

    /** Ledger retention, configured on the content-management page (`credit_retention`). */
    public static function retentionDays(): int
    {
        $days = absint((string) aiya_core_opt('backend', 'credit_retention', self::DEFAULT_RETENTION_DAYS));

        return $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::DEFAULT_RETENTION_DAYS;
    }

    /** The configured waiver level, falling back to the default on any unknown stored value. */
    public static function spendExemptLevel(): string
    {
        $level = (string) aiya_core_opt('membership', 'spend_exempt_level', self::DEFAULT_EXEMPT_LEVEL);

        return in_array($level, self::EXEMPT_LEVELS, true) ? $level : self::DEFAULT_EXEMPT_LEVEL;
    }

    /** The capability the configured level stands for — the waiver's gate. */
    public static function exemptCapability(): string
    {
        return self::LEVEL_CAPABILITIES[self::spendExemptLevel()];
    }

    /**
     * Whether this holder spends at zero: their role holds the capability
     * of the configured level. Unknown or anonymous holders never waive.
     */
    public static function spendWaived(int $userId): bool
    {
        return $userId > 0 && user_can($userId, self::exemptCapability());
    }
}
