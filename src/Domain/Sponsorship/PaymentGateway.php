<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * The thin payment-gateway seam of the membership domain: the domain
 * (orders, entitlement queue, controllers) depends on this interface
 * only — adding a gateway means adding one adapter class plus its
 * settings, never touching SponsorshipController/GatewayController flow.
 *
 * A payment is a value description; gateways never resolve amounts into
 * rights. How a push proves itself is each adapter's own business: the
 * Epay cashier verifies its signed callback into the description, the
 * Afdian webhook treats the push as a hint and re-reads the purchase
 * through the platform's authenticated API (2026-09-21 decision) — so
 * this seam carries order identity, availability, channels and the
 * signed payment URL only, no callback protocol.
 */
interface PaymentGateway
{
    /**
     * The webhook namespace of the membership domain's callback routes.
     * Owned here — the domain's push surface — so adapters build their
     * notify URLs and controllers register their routes without reaching
     * across layers. First-party REST namespaces additionally announce
     * themselves through the `aiya_core_firstparty_rest_namespaces`
     * filter for the headless REST gate.
     */
    public const GATEWAY_NAMESPACE = 'aiya/sponsorship/v1';

    /** Stable identifier used in settings, order sources and routes. */
    public function id(): string;

    /** True when credentials are configured and the admin switch is on. */
    public function enabled(): bool;

    /**
     * The payment channels this gateway currently offers (e.g.
     * ['alipay', 'wxpay']). Empty when disabled.
     *
     * @return list<string>
     */
    public function channels(): array;

    /**
     * Builds the gateway's signed payment URL for one order. `binding`
     * is the domain's opaque user|tier|cycles payload the gateway must
     * return untouched on the callback.
     *
     * @param array{orderId:string, title:string, amount:float, channel:string, binding:string} $payment
     * @return string|WP_Error The redirect URL for the front end.
     */
    public function createPayment(array $payment): string|WP_Error;

    /**
     * The payment-log order id for one checkout reference: the provider's
     * own prefix plus the wire id (the callbacks resolve back to
     * `epc_<out_trade_no>` and `afd_<platform order no>`). The checkout
     * row must be written under THIS id — it is what a push is matched
     * against — while the wire keeps the bare reference the buyer sees.
     */
    public function orderId(string $reference): string;
}
