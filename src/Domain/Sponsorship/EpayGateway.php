<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\PaymentEpay\Client;
use Aiya\Infra\PaymentEpay\Gateway;
use WP_Error;

/**
 * The Epay (彩虹易支付) adapter behind PaymentGateway — the WordPress half
 * of the `aiya/payment-epay` package. It owns every WordPress touchpoint:
 * reading the credentials/channels from the payments settings, building
 * the notify URL the platform pushes back to, handing the package the tier
 * keys a callback may activate, and mapping the package's answers onto
 * WP_Error plus translated copy. The package never sees WordPress.
 */
final class EpayGateway implements PaymentGateway
{
    /**
     * @param list<string> $channels
     */
    public function __construct(private Gateway $gateway, private bool $enabled, private array $channels)
    {
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = SponsorshipSettings::read();
        if (!$settings['epayEnable']) {
            return null;
        }

        $client = new Client($settings['epayPid'], $settings['epayKey'], $settings['epayGateway']);
        if (!$client->configured()) {
            return null;
        }

        return new self(
            new Gateway(
                $client,
                (string) get_rest_url(null, '/' . self::GATEWAY_NAMESPACE . '/epay/callback'),
                $settings['epayReturnUrl'],
                // Disabled tiers stay in the callback whitelist BY DESIGN
                // (2026-09-21): enabled gates the storefront buy list only —
                // SponsorshipController refuses disabled tiers at order
                // time, so a signed push naming one can only be settling a
                // purchase made while it was on sale. Whitelisting just the
                // enabled tiers would orphan that money at the one moment
                // it is verified.
                array_map(static fn (array $tier): string => (string) $tier['key'], $settings['tiers'])
            ),
            true,
            $settings['epayMethods']
        );
    }

    public function id(): string
    {
        return 'epay';
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->gateway->configured();
    }

    public function channels(): array
    {
        return $this->enabled() ? $this->channels : [];
    }

    public function orderId(string $reference): string
    {
        return Gateway::ORDER_PREFIX . $reference;
    }

    public function createPayment(array $payment): string|WP_Error
    {
        if (!$this->enabled()) {
            return new WP_Error('aiya_channel_unavailable', __('The Epay channel is not available.', 'aiya-core'), ['status' => 502]);
        }
        if (!in_array((string) $payment['channel'], $this->channels, true)) {
            return new WP_Error('aiya_channel_unavailable', __('The requested payment channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        $url = $this->gateway->createPayment($payment);
        if ($url === '') {
            return new WP_Error('aiya_channel_unavailable', __('The Epay channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        return $url;
    }

    /**
     * Verifies a callback and resolves the payment description.
     * Null means not activatable: a bad signature, a non-success trade
     * status, a malformed binding, or a tier this site does not sell.
     *
     * @param array<string, mixed> $query
     * @return array{orderId:string, userId:int, tierKey:string, cycles:int, amount:float}|null
     */
    public function verifyCallback(array $query): ?array
    {
        return $this->gateway->verifyCallback($query);
    }

    /**
     * True when a push carries an invalid signature — the one failure the
     * platform must hear about (the caller answers 400). A validly signed
     * push we merely cannot use returns false: answer success and move on.
     *
     * @param array<string, mixed> $query
     */
    public function callbackFailed(array $query): bool
    {
        return $this->gateway->callbackFailed($query);
    }
}
