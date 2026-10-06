<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

use Aiya\Core\Domain\Membership\MembershipSettings;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;

/**
 * The Afdian (爱发电) adapter behind PaymentGateway — the WordPress half of
 * the `aiya/payment-afdian` package. It owns every WordPress touchpoint:
 * the settings read, the `wp_remote_post` transport the package client
 * calls through, the site-name remark the platform shows the buyer, and
 * the single binding that aims the deep link at one platform plan. The
 * 2026-10-06 ruling collapsed the old many-plan binding table and the
 * fallback tier: every Afdian order settles into the one bound tier,
 * whatever plan it was paid under.
 */
final class AfdianGateway implements PaymentGateway
{
    /**
     * @param array{key:string,name:string,description:string,price:float,cycleDays:int,creditsPerCycle:int}|null $boundTier the tier every Afdian order activates; null leaves the channel unoffered
     */
    public function __construct(
        private Client $client,
        private Gateway $gateway,
        private bool $enabled,
        private ?array $boundTier,
    ) {
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = PaymentSettings::read();
        if (!$settings['afdianEnable'] || $settings['afdianUserId'] === '' || $settings['afdianToken'] === '') {
            return null;
        }
        $tier = MembershipSettings::tierByKey(MembershipSettings::read()['tiers'], $settings['afdianTier']);

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

        $planId = $settings['afdianPlanId'];

        return new self(
            $client,
            new Gateway(
                $client,
                $planId,
                $tier !== null && $planId !== '' ? $tier['key'] : null,
                // The remark rides the outbound payment page, so it reads in
                // the site language like every other buyer-facing string.
                /* translators: %s: site name. */
                sprintf(__('A membership order from %s', 'aiya-core'), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES))
            ),
            true,
            $tier !== null && $planId !== '' ? $tier : null,
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
     * The order-create deep link for the single bound plan on behalf of
     * one user. The custom_order_id segment carries the user binding back
     * into the webhook; empty when the plan/tier pairing is unconfigured
     * so callers can hide the jump.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     */
    public function createPayment(array $payment): string
    {
        return $this->gateway->createPayment($payment);
    }

    /**
     * The personalized order-create deep link; empty when the plan/tier
     * pairing is unconfigured.
     */
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
     * The tier every Afdian order activates; null while the plan/tier
     * pairing is unconfigured (the channel stays unoffered).
     *
     * @return array{key:string, name:string, description:string, price:float, cycleDays:int, creditsPerCycle:int}|null
     */
    public function boundTier(): ?array
    {
        return $this->boundTier;
    }
}
