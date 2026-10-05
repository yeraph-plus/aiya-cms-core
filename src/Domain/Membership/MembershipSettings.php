<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Membership;

/**
 * Normalized reader for the membership domain's two settings options: the
 * tier list on the membership page and the gateway credentials/channels on
 * the dedicated payments page (0.50.x split). Consumers get one merged
 * shape; gateway credentials never leave the server. Epay is the cashier
 * gateway; Afdian (0.61.0) is the platform-push gateway — its orders
 * arrive by webhook or self-service order number, never the cashier.
 */
final class MembershipSettings
{
    /** Default days the payment log keeps an unsettled order. */
    public const DEFAULT_UNPAID_RETENTION = 30;

    /** The retention floor: past the pending TTL (7), so the sweep always labels before the purge deletes. */
    public const MIN_UNPAID_RETENTION = 7;

    public const MAX_UNPAID_RETENTION = 365;

    /**
     * @return array{epayEnable:bool,epayPid:string,epayKey:string,epayGateway:string,epayMethods:list<string>,afdianEnable:bool,afdianUserId:string,afdianToken:string,afdianBindings:list<array{planId:string,tierKey:string}>,afdianFallbackTier:string,unpaidRetentionDays:int,tiers:list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int,cycles:int}>}
     */
    public static function read(): array
    {
        $tiers = (array) get_option(MembershipModule::OPTION_NAME, []);
        $payments = (array) get_option(MembershipModule::PAYMENTS_OPTION_NAME, []);

        return [
            'epayEnable' => (bool) ($payments['epay_enable'] ?? false),
            'epayPid' => (string) ($payments['epay_pid'] ?? ''),
            'epayKey' => (string) ($payments['epay_key'] ?? ''),
            'epayGateway' => (string) ($payments['epay_gateway'] ?? ''),
            'epayMethods' => self::methods($payments),
            'afdianEnable' => (bool) ($payments['afdian_enable'] ?? false),
            'afdianUserId' => (string) ($payments['afdian_user_id'] ?? ''),
            'afdianToken' => (string) ($payments['afdian_token'] ?? ''),
            'afdianBindings' => self::bindings($payments),
            'afdianFallbackTier' => sanitize_key((string) ($payments['afdian_fallback_tier'] ?? '')),
            'unpaidRetentionDays' => self::unpaidRetention($payments),
            'tiers' => self::tiers($tiers),
        ];
    }

    /**
     * Days the payment log keeps an unsettled order (paid rows are
     * forever). Clamped on both ends so a hand-edited option can never
     * delete a checkout the sweep has not yet labelled, nor keep carts
     * around for years.
     *
     * @param array<string, mixed> $payments
     */
    public static function unpaidRetention(array $payments): int
    {
        $days = absint((string) ($payments['unpaid_order_retention'] ?? self::DEFAULT_UNPAID_RETENTION));
        if ($days === 0) {
            return self::DEFAULT_UNPAID_RETENTION;
        }

        return max(self::MIN_UNPAID_RETENTION, min(self::MAX_UNPAID_RETENTION, $days));
    }

    /**
     * The enabled cashier channels, normalized against the wire
     * vocabulary. Values outside the whitelist are dropped.
     *
     * @param array<string, mixed> $payments
     * @return list<string>
     */
    public static function methods(array $payments): array
    {
        $allowed = ['alipay', 'wxpay', 'usdt'];
        $methods = [];
        foreach ((array) ($payments['epay_methods'] ?? []) as $method) {
            $method = (string) $method;
            if (in_array($method, $allowed, true) && !in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Normalized tier rows; the key is the stable cross-reference
     * identifier gateway callbacks resolve the purchase from. Tier
     * config is snapshotted into the entitlement at purchase time, so
     * later edits never rewrite existing queues.
     *
     * @param array<string, mixed> $settings
     * @return list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int,cycles:int}>
     */
    public static function tiers(array $settings): array
    {
        $tiers = [];
        foreach ((array) ($settings['tiers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $tiers[] = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? ''),
                'description' => trim(sanitize_textarea_field((string) ($row['description'] ?? ''))),
                'enabled' => (bool) ($row['enabled'] ?? true),
                // Two decimals at the read edge too — rows saved before the
                // 500-cap field constraint cannot smuggle in extra precision
                // that the gateways would round differently.
                'price' => min(500.0, round((float) ($row['price'] ?? 0), 2)),
                'cycleDays' => max(1, (int) ($row['cycle_days'] ?? 30)),
                'creditsPerCycle' => max(0, (int) ($row['credits_per_cycle'] ?? 0)),
                'cycles' => max(1, min(60, (int) ($row['cycles'] ?? 1))),
            ];
        }

        return $tiers;
    }

    /**
     * @param list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int,cycles:int}> $tiers
     * @return array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int,cycles:int}|null
     */
    public static function tierByKey(array $tiers, string $key): ?array
    {
        foreach ($tiers as $tier) {
            if ($tier['key'] === $key) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The Afdian plan→tier binding rows, normalized. Resolution against
     * the tier list happens in the adapter (rows naming a tier that was
     * deleted since are dropped there, not here) — the reader only
     * sanitizes what the settings page stored.
     *
     * @param array<string, mixed> $payments
     * @return list<array{planId:string, tierKey:string}>
     */
    public static function bindings(array $payments): array
    {
        $bindings = [];
        foreach ((array) ($payments['afdian_bindings'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bindings[] = [
                'planId' => sanitize_text_field((string) ($row['plan_id'] ?? '')),
                'tierKey' => sanitize_key((string) ($row['tier_key'] ?? '')),
            ];
        }

        return $bindings;
    }
}
