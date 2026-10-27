<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

/**
 * Normalized reader for the payment domain's settings option: the gateway
 * credentials and channels on the payments page. The option
 * key predates the 0.111.0 domain split and stays
 * `aiya_core_membership_payments` — storage keys are protocol, only the
 * owning module moved. Consumers get one merged shape; gateway
 * credentials never leave the server. Epay is the cashier gateway; Afdian
 * is the platform-push gateway — its orders arrive by webhook or
 * self-service order number, never the cashier.
 *
 * The plan tables live with their own readers, one per product line:
 * `Domain\Membership\MembershipSettings` owns the cashier tiers, and
 * `AfdianTiers` owns the Afdian plans. Neither list is "the" tier list, so
 * this reader stays about credentials and channels only.
 */
final class PaymentSettings
{

    /**
     * @return array{epayEnable:bool,epayPid:string,epayKey:string,epayGateway:string,epayMethods:list<string>,afdianEnable:bool,afdianUserId:string,afdianToken:string}
     */
    public static function read(): array
    {
        $payments = (array) get_option(PaymentModule::OPTION_NAME, []);

        return [
            'epayEnable' => (bool) ($payments['epay_enable'] ?? false),
            'epayPid' => (string) ($payments['epay_pid'] ?? ''),
            'epayKey' => (string) ($payments['epay_key'] ?? ''),
            'epayGateway' => (string) ($payments['epay_gateway'] ?? ''),
            'epayMethods' => self::methods($payments),
            'afdianEnable' => (bool) ($payments['afdian_enable'] ?? false),
            'afdianUserId' => (string) ($payments['afdian_user_id'] ?? ''),
            'afdianToken' => (string) ($payments['afdian_token'] ?? ''),
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
}
