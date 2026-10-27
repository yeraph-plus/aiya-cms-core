<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use WP_Error;

/**
 * The Afdian (爱发电) adapter behind PaymentGateway — the WordPress half of
 * the `aiya/payment-afdian` package. It owns every WordPress touchpoint:
 * the settings read, the `wp_remote_post` transport the package client
 * calls through, the site-name remark the platform shows the buyer, and the
 * site's own plan table (AfdianTiers), which maps each platform `plan_id` to
 * the entitlement snapshot its orders activate.
 *
 * Afdian is a sponsorship platform, not a cashier, so the channel has no
 * price list and no cashier submit URL: the buyer pays on the platform and
 * the site settles what the open API reports. The plan table is the payment
 * domain's own — nothing here reads the membership domain's tier list, and
 * no single plan is "the plan" any more.
 */
final class AfdianGateway implements PaymentGateway
{
    /**
     * @param list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}> $tiers
     */
    public function __construct(
        private Client $client,
        private Gateway $gateway,
        private bool $enabled,
        private array $tiers,
    ) {
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = PaymentSettings::read();
        if (!$settings['afdianEnable'] || $settings['afdianUserId'] === '' || $settings['afdianToken'] === '') {
            return null;
        }

        // The real HTTP transport for the open API: raw JSON in, response
        // body out (API-level errors ride HTTP 200 with their own ec).
        $client = new Client(
            $settings['afdianUserId'],
            $settings['afdianToken'],
            static function (string $url, string $jsonBody): ?string {
                $response = wp_remote_post($url, [
                    'timeout' => 20,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => $jsonBody,
                ]);
                if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500) {
                    return null;
                }

                $body = wp_remote_retrieve_body($response);

                return is_string($body) && $body !== '' ? $body : null;
            }
        );

        return new self(
            $client,
            new Gateway(
                $client,
                // The remark rides the outbound payment page, so it reads in
                // the site language like every other buyer-facing string.
                /* translators: %s: site name. */
                sprintf(__('A membership order from %s', 'aiya-core'), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES))
            ),
            true,
            AfdianTiers::read(),
        );
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function id(): string
    {
        return 'afdian';
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Afdian never rides the cashier; the platform owns its checkout. */
    public function channels(): array
    {
        return [];
    }

    public function orderId(string $reference): string
    {
        return Gateway::ORDER_PREFIX . $reference;
    }

    /**
     * Afdian has no cashier submit step — the buyer pays on the platform and
     * the settlement rides the webhook / order-number path. Reaching this is
     * a wiring bug, so it refuses loudly instead of inventing a URL.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): WP_Error
    {
        return new WP_Error(
            'aiya_afdian_no_cashier',
            __('Afdian purchases are placed on the platform, not through the site cashier.', 'aiya-core'),
            ['status' => 400]
        );
    }

    /**
     * The site's Afdian plan table.
     *
     * @return list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /**
     * The plan row an order settles into, matched on the platform's own
     * `plan_id`. Disabled rows still match on purpose: `enabled` means "stop
     * selling", never "refuse a purchase that was already paid for".
     *
     * @return array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|null
     */
    public function tierForPlan(string $planId): ?array
    {
        return AfdianTiers::byPlanId($this->tiers, trim($planId));
    }

    /**
     * @return array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|null
     */
    public function tierByKey(string $tierKey): ?array
    {
        return AfdianTiers::byKey($this->tiers, $tierKey);
    }

    /**
     * The order-create deep link for one of the site's Afdian plans on behalf
     * of one user. Empty when the tier is unknown, disabled or carries no
     * plan id, so callers can hide the jump.
     */
    public function orderUrl(int $userId, string $tierKey, int $month = 0): string
    {
        $tier = $this->tierByKey($tierKey);
        if ($tier === null || !$tier['enabled']) {
            return '';
        }

        return $this->gateway->orderUrl($userId, $tier['planId'], $month);
    }

    /**
     * The webhook push's trade number — the push's one trusted field (the
     * package carries the RSA-free push-trust model; the site re-reads the
     * order through the open API).
     *
     * @param array<string, mixed> $body
     */
    public function pushOrderNo(array $body): ?string
    {
        return $this->gateway->pushOrderNo($body);
    }
}
