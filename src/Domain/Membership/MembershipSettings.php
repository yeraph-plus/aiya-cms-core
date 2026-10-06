<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Membership;

/**
 * Normalized reader for the membership domain's tier list (the membership
 * page's option). The gateway credentials/channels half of the former
 * merged reader moved to the payment domain (`Domain\Payment\PaymentSettings`)
 * with the 0.111.0 split. Tier config is snapshotted into purchases at
 * purchase time, so later edits never rewrite existing queues.
 */
final class MembershipSettings
{
    /**
     * @return array{tiers:list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int}>}
     */
    public static function read(): array
    {
        return ['tiers' => self::tiers((array) get_option(MembershipModule::OPTION_NAME, []))];
    }

    /**
     * Normalized tier rows; the key is the stable cross-reference
     * identifier gateway callbacks resolve the purchase from. Tier
     * config is snapshotted into the entitlement at purchase time, so
     * later edits never rewrite existing queues.
     *
     * @param array<string, mixed> $settings
     * @return list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int}>
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
            ];
        }

        return $tiers;
    }

    /**
     * @param list<array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int}> $tiers
     * @return array{key:string,name:string,description:string,enabled:bool,price:float,cycleDays:int,creditsPerCycle:int}|null
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
}
