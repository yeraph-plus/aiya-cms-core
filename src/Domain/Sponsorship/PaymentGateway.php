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
 * rights. The domain binds tier/cycles inside the gateway's own signed
 * payload (the adapter's job to carry it) and verifies callbacks back
 * into the same description.
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
     * Verifies a gateway callback and returns the bound payment
     * description. Null means the push is not activatable (non-success
     * status, unresolvable binding) — callers answer the gateway with
     * success without touching the domain.
     *
     * @param array<string, mixed> $query
     * @return array{orderId:string, userId:int, tierKey:string, cycles:int, amount:float}|null
     */
    public function verifyCallback(array $query): ?array;

    /**
     * True when a push carries an invalid signature — the one callback
     * failure mode the platform must hear about (the caller answers 400).
     * A validly signed push that verifyCallback() rejected returns false.
     *
     * @param array<string, mixed> $query
     */
    public function callbackFailed(array $query): bool;
}
