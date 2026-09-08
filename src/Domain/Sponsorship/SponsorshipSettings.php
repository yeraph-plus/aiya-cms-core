<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Normalized reader for the domain's settings option. Both the REST
 * controllers and the admin surfaces consume this single shape; gateway
 * credentials never leave the server.
 */
final class SponsorshipSettings
{
    /**
     * @return array{afdianEnable:bool,afdianUserId:string,afdianToken:string,afdianHomeSlug:string,afdianPlanType:string,afdianPresetPlanUrl:string,afdianSavelog:bool,epayEnable:bool,epayPid:string,epayKey:string,epayGateway:string,epayAlipay:bool,epayWxpay:bool,epayUsdt:bool,epayReturnUrl:string,epaySavelog:bool,plans:list<array{key:string,name:string,price:float,days:int}>}
     */
    public static function read(): array
    {
        $settings = (array) get_option(SponsorshipModule::OPTION_NAME, []);

        return [
            'afdianEnable' => (bool) ($settings['afdian_enable'] ?? false),
            'afdianUserId' => (string) ($settings['afdian_user_id'] ?? ''),
            'afdianToken' => (string) ($settings['afdian_token'] ?? ''),
            'afdianHomeSlug' => (string) ($settings['afdian_home_slug'] ?? ''),
            'afdianPlanType' => ($settings['afdian_plan_type'] ?? 'optional') === 'preset' ? 'preset' : 'optional',
            'afdianPresetPlanUrl' => (string) ($settings['afdian_preset_plan_url'] ?? ''),
            'afdianSavelog' => (bool) ($settings['afdian_savelog'] ?? false),
            'epayEnable' => (bool) ($settings['epay_enable'] ?? false),
            'epayPid' => (string) ($settings['epay_pid'] ?? ''),
            'epayKey' => (string) ($settings['epay_key'] ?? ''),
            'epayGateway' => (string) ($settings['epay_gateway'] ?? ''),
            'epayAlipay' => (bool) ($settings['epay_method_alipay'] ?? false),
            'epayWxpay' => (bool) ($settings['epay_method_wxpay'] ?? false),
            'epayUsdt' => (bool) ($settings['epay_method_usdt'] ?? false),
            'epayReturnUrl' => (string) ($settings['epay_return_url'] ?? ''),
            'epaySavelog' => (bool) ($settings['epay_savelog'] ?? false),
            'plans' => self::plans($settings),
        ];
    }

    /**
     * Normalized plan rows; the key is the stable cross-reference identifier
     * gateway callbacks resolve days from.
     *
     * @param array<string, mixed> $settings
     * @return list<array{key:string,name:string,price:float,days:int}>
     */
    public static function plans(array $settings): array
    {
        $plans = [];
        foreach ((array) ($settings['plans'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $plans[] = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'days' => max(1, (int) ($row['days'] ?? 1)),
            ];
        }

        return $plans;
    }

    /**
     * @param list<array{key:string,name:string,price:float,days:int}> $plans
     * @return array{key:string,name:string,price:float,days:int}|null
     */
    public static function planByKey(array $plans, string $key): ?array
    {
        foreach ($plans as $plan) {
            if ($plan['key'] === $key) {
                return $plan;
            }
        }

        return null;
    }
}
