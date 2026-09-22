<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;

/**
 * The Afdian (爱发电) adapter behind PaymentGateway — the WordPress half of
 * the `aiya/payment-afdian` package. It owns every WordPress touchpoint:
 * the settings read, the `wp_remote_post` transport the package client
 * calls through, the site-name remark the platform shows the buyer, and
 * the plan→tier binding table (2026-09-21 model: many plans may bind, each
 * to one tier, plus a fallback tier for the amount-only plan). The
 * package turns the primary binding into the deep link; the resolution
 * helpers (tierForPlan/planForTier/fallbackTier) are what the activation
 * chain settles purchases with.
 */
final class AfdianGateway implements PaymentGateway
{
    /** @var array<string, array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}> plan id => tier it activates */
    private array $planTiers;

    /** @var array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null the first binding's tier — the deep link's target */
    private ?array $primaryTier;

    /**
     * @param array<string, array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}> $planTiers
     * @param array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int}|null $fallbackTier
     */
    public function __construct(
        private Client $client,
        private Gateway $gateway,
        private bool $enabled,
        array $planTiers,
        private ?array $fallbackTier,
    ) {
        $this->planTiers = $planTiers;
        $this->primaryTier = $planTiers === [] ? null : $planTiers[(string) array_key_first($planTiers)];
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = SponsorshipSettings::read();
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

        // The binding table: plan id → tier. The first row wins for a
        // duplicated plan id (an admin slip), and bindings naming a tier
        // that no longer exists drop out here — they resolve like unknown
        // plans: ignored, never a purchase.
        $planTiers = [];
        foreach ($settings['afdianBindings'] as $binding) {
            $tier = SponsorshipSettings::tierByKey($settings['tiers'], $binding['tierKey']);
            if ($binding['planId'] !== '' && $tier !== null && !array_key_exists($binding['planId'], $planTiers)) {
                $planTiers[$binding['planId']] = $tier;
            }
        }

        $primaryPlan = (string) array_key_first($planTiers);

        return new self(
            $client,
            new Gateway(
                $client,
                $primaryPlan,
                $primaryPlan === '' ? null : $planTiers[$primaryPlan]['key'],
                sprintf('来自「%s」的会员订单', (string) get_bloginfo('name'))
            ),
            true,
            $planTiers,
            SponsorshipSettings::tierByKey($settings['tiers'], $settings['afdianFallbackTier'])
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
     * The order-create deep link for the primary binding on behalf of one
     * user. With several bindings the deep link can only point at one —
     * it targets the first bound plan, and the webhook still settles by
     * whatever plan the buyer actually paid.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): string
    {
        return $this->gateway->createPayment($payment);
    }

    /** The personalized order-create deep link; empty when nothing is bound. */
    public function orderUrl(int $userId, int $month = 0): string
    {
        return $this->gateway->orderUrl($userId, $month);
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

    /**
     * The tier a bound plan activates; null for unknown plans.
     *
     * @return array{key:string, name:string, price:float, cycleDays:int, creditsPerCycle:int}|null
     */
    public function tierForPlan(string $planId): ?array
    {
        return $this->planTiers[$planId] ?? null;
    }

    /** The first plan bound to a tier; null when the tier has none. Feeds the per-tier plan id (a contract field the v1 Tier shape does not carry yet). */
    public function planForTier(string $tierKey): ?string
    {
        foreach ($this->planTiers as $planId => $tier) {
            if ($tier['key'] === $tierKey) {
                return $planId;
            }
        }

        return null;
    }

    /**
     * The tier the amount-only plan (empty plan_id) falls into; null
     * refuses those orders.
     *
     * @return array{key:string, name:string, price:float, cycleDays:int, creditsPerCycle:int}|null
     */
    public function fallbackTier(): ?array
    {
        return $this->fallbackTier;
    }

    /**
     * The first binding's tier — the checkout placeholder and deep link
     * target; null when nothing is bound.
     *
     * @return array{key:string, name:string, price:float, cycleDays:int, creditsPerCycle:int}|null
     */
    public function primaryTier(): ?array
    {
        return $this->primaryTier;
    }

    /** The purchase channel exists only while at least one plan is bound. */
    public function hasBindings(): bool
    {
        return $this->planTiers !== [];
    }
}
