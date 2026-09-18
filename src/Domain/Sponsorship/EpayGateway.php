<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use WP_Error;

/**
 * The Epay (彩虹易支付) adapter behind PaymentGateway: reads its own
 * credentials/channels from the payments settings option, signs cashier
 * URLs through the byte-compatible EpayClient and verifies callbacks
 * back into the domain's payment description. Everything Epay-specific
 * (params shape, '0'-skip signing quirk) lives here.
 */
final class EpayGateway implements PaymentGateway
{
    /**
     * @param list<string> $channels
     */
    public function __construct(private EpayClient $client, private bool $enabled, private array $channels)
    {
    }

    /** Builds the adapter from the domain settings (null when disabled/unconfigured). */
    public static function fromSettings(): ?self
    {
        $settings = SponsorshipSettings::read();
        if (!$settings['epayEnable']) {
            return null;
        }

        $client = new EpayClient($settings['epayPid'], $settings['epayKey'], $settings['epayGateway']);
        if (!$client->configured()) {
            return null;
        }

        return new self($client, true, $settings['epayMethods']);
    }

    public function id(): string
    {
        return 'epay';
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->client->configured();
    }

    public function channels(): array
    {
        return $this->enabled() ? $this->channels : [];
    }

    public function createPayment(array $payment): string|WP_Error
    {
        if (!$this->enabled()) {
            return new WP_Error('aiya_channel_unavailable', __('The Epay channel is not available.', 'aiya-core'), ['status' => 502]);
        }
        if (!in_array((string) $payment['channel'], $this->channels, true)) {
            return new WP_Error('aiya_channel_unavailable', __('The requested payment channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        $settings = SponsorshipSettings::read();
        $submitQuery = $this->client->buildSubmitQuery([
            'out_trade_no' => (string) $payment['orderId'],
            'name' => (string) $payment['title'],
            'money' => number_format(round((float) $payment['amount'], 2), 2, '.', ''),
            'param' => (string) $payment['binding'],
            'type' => (string) $payment['channel'],
        ], get_rest_url(null, '/' . self::GATEWAY_NAMESPACE . '/epay/callback'), $settings['epayReturnUrl']);

        return $this->client->submitUrl($submitQuery);
    }

    public function verifyCallback(array $query): ?array
    {
        if (!$this->client->verifyCallback($query)) {
            return null;
        }
        if ((string) ($query['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return null;
        }

        $outTradeNo = (string) ($query['out_trade_no'] ?? '');
        $binding = (string) ($query['param'] ?? '');
        if ($outTradeNo === '' || $binding === '') {
            return null;
        }

        [$userBinding, $tierKey, $cyclesRaw] = array_pad(explode('|', $binding, 3), 3, '');
        $userId = (int) (new IdSlugEncoder(8))->decodeId($userBinding);
        $tierKey = sanitize_key($tierKey);
        $cycles = absint($cyclesRaw);

        if ($userId <= 0 || $tierKey === '' || $cycles < 1) {
            return null;
        }

        $tier = SponsorshipSettings::tierByKey(SponsorshipSettings::read()['tiers'], $tierKey);
        if ($tier === null) {
            return null;
        }

        return [
            'orderId' => 'epc_' . $outTradeNo,
            'userId' => $userId,
            'tierKey' => $tierKey,
            'cycles' => $cycles,
            // The actually-paid amount from the platform, not a settings
            // recompute — the money log must reflect what was paid even if
            // the tier price changed between checkout and callback.
            'amount' => round((float) ($query['money'] ?? 0), 2),
        ];
    }

    /**
     * True when a push carries an invalid signature — the one callback
     * failure mode the platform must hear about (400). A validly signed
     * push we merely cannot use (bad status, unknown binding) returns
     * false: answer success and move on.
     *
     * @param array<string, mixed> $query
     */
    public function callbackFailed(array $query): bool
    {
        return !$this->client->verifyCallback($query);
    }
}
