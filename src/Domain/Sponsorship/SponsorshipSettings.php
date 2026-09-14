<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Normalized reader for the membership domain's two settings options: the
 * tier list on the sponsorship page and the cashier credentials/channels
 * on the dedicated payments page (0.50.x split, ready for future gateway
 * additions). Consumers get one merged shape; gateway credentials never
 * leave the server. The Afdian integration is parked (SDK class retained,
 * no settings, no routes) — Epay is the only wired gateway.
 */
final class SponsorshipSettings
{
    /**
     * @return array{epayEnable:bool,epayPid:string,epayKey:string,epayGateway:string,epayMethods:list<string>,epayReturnUrl:string,epaySavelog:bool,tiers:list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}>}
     */
    public static function read(): array
    {
        $tiers = (array) get_option(SponsorshipModule::OPTION_NAME, []);
        $payments = (array) get_option(SponsorshipModule::PAYMENTS_OPTION_NAME, []);

        return [
            'epayEnable' => (bool) ($payments['epay_enable'] ?? false),
            'epayPid' => (string) ($payments['epay_pid'] ?? ''),
            'epayKey' => (string) ($payments['epay_key'] ?? ''),
            'epayGateway' => (string) ($payments['epay_gateway'] ?? ''),
            'epayMethods' => self::methods($payments),
            'epayReturnUrl' => (string) ($payments['epay_return_url'] ?? ''),
            'epaySavelog' => (bool) ($payments['epay_savelog'] ?? false),
            'tiers' => self::tiers($tiers),
        ];
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
     * @return list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}>
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
                'price' => (float) ($row['price'] ?? 0),
                'cycleDays' => max(1, (int) ($row['cycle_days'] ?? 30)),
                'creditsPerCycle' => max(0, (int) ($row['credits_per_cycle'] ?? 0)),
            ];
        }

        return $tiers;
    }

    /**
     * @param list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}> $tiers
     * @return array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null
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
