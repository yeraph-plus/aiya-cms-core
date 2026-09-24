<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Presenter\SponsorshipPresenter;
use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\PaymentGateway;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Membership self-service routes of the versioned API under the 0.50.0
 * tier model: the tier list (public, from the domain settings), the
 * viewer's queue state with the derived credit balance, the Epay cashier
 * order builder (price × cycles bound inside the signed params, so
 * gateway callbacks never resolve amounts into rights) and the Afdian
 * order deep link carrying the user binding for webhook self-attribution.
 * Code redemption lives in CreditController.
 */
final class SponsorshipController
{
    public function __construct(
        private MembershipService $membership,
        private EntitlementService $entitlements,
        private LedgerService $ledger,
        private OrderService $orders,
        private RateLimiter $limiter,
        private SponsorshipPresenter $presenter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/plans', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->plans(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/membership', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->membershipState(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/afdian/order-url', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->afdianOrderUrl($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                // The tier resolves the cycle count; the plan binding still
                // decides activation at webhook time.
                'tierKey' => ['type' => 'string', 'required' => true, 'maxLength' => 32],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/orders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->createOrder($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'tierKey' => ['type' => 'string', 'required' => true, 'maxLength' => 32],
                'channel' => ['type' => 'string', 'required' => true, 'enum' => ['alipay', 'wxpay', 'usdt']],
                'returnUrl' => ['type' => 'string', 'maxLength' => 500],
            ],
        ]);
    }

    private function plans(): WP_REST_Response
    {
        $gateway = $this->gateway();
        $afdian = AfdianGateway::fromSettings();

        // Each gateway's own answer is authoritative: it knows both the
        // admin switch and whether credentials exist. The Afdian purchase
        // channel exists only while at least one plan is bound — with no
        // binding there is no deep link to offer the buyer.
        return new WP_REST_Response($this->presenter->plans(
            $gateway !== null && $gateway->enabled(),
            $afdian !== null && $afdian->enabled() && $afdian->hasBindings(),
            $gateway?->channels() ?? [],
            SponsorshipSettings::read()['tiers']
        ));
    }

    /**
     * The viewer's personalized Afdian order-create deep link for one
     * tier: the custom_order_id segment carries the user binding back
     * into the webhook, so the purchase self-attributes on arrival.
     */
    private function afdianOrderUrl(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // Same fixed window as the cashier's order builder: both endpoints
        // hand out one outbound platform artifact per hit, and the buyer
        // has no reason to hammer either.
        if (!$this->limiter->hit('afdian_order_url', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $gateway = AfdianGateway::fromSettings();
        if ($gateway === null || !$gateway->enabled()) {
            return new WP_Error('aiya_afdian_unavailable', __('The Afdian channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        // The tier decides the cycle count (there is no front-end picker):
        // the deep link pre-selects it on the platform page and the local
        // placeholder row queues it until the push replaces both id and
        // cycles with the queried order's reality.
        $tier = SponsorshipSettings::tierByKey(
            SponsorshipSettings::read()['tiers'],
            sanitize_key((string) $request->get_param('tierKey'))
        );
        if ($tier === null) {
            return new WP_Error('aiya_not_found', __('Unknown membership tier.', 'aiya-core'), ['status' => 404]);
        }
        $cycles = (int) $tier['cycles'];

        $userId = (int) get_current_user_id();
        $url = $gateway->orderUrl($userId, $cycles, (string) $tier['key']);
        if ($url === '') {
            return new WP_Error('aiya_plan_unbound', __('This tier is not bound to an Afdian plan.', 'aiya-core'), ['status' => 422]);
        }

        $pending = $this->orders->createPending(
            $userId,
            $gateway->orderId('pending_' . strtoupper(substr(md5(uniqid((string) wp_rand(), true)), 0, 12))),
            (string) $tier['key'],
            $cycles,
            0.0,
            'afdian'
        );
        if (is_wp_error($pending)) {
            return $pending;
        }

        return new WP_REST_Response(['url' => $url]);
    }

    private function membershipState(): WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $window = $this->entitlements->window($userId);

        return new WP_REST_Response($this->presenter->membershipState(
            $this->membership->isActive($userId),
            $window['expiresAt'] > 0 ? $window['expiresAt'] : null,
            $window['nextGrantAt'] > 0 ? $window['nextGrantAt'] : null,
            $this->ledger->balance($userId),
            $this->entitlements->queueFor($userId),
            CreditSettings::read()
        ));
    }

    /**
     * Builds a signed cashier order for the viewer through the gateway
     * seam: the domain owns order identity and the tier/cycles binding,
     * the adapter owns everything gateway-specific (signing, params
     * shape, callback URL). Callbacks never resolve amounts into rights.
     */
    private function createOrder(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hit('sponsorship_order', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $gateway = $this->gateway();
        $channel = (string) $request->get_param('channel');
        if ($gateway === null || !$gateway->enabled()) {
            return new WP_Error('aiya_channel_unavailable', __('No payment gateway is available.', 'aiya-core'), ['status' => 502]);
        }
        if (!in_array($channel, $gateway->channels(), true)) {
            return new WP_Error('aiya_channel_unavailable', __('The requested payment channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        $settings = SponsorshipSettings::read();
        $tierKey = sanitize_key((string) $request->get_param('tierKey'));
        $tier = SponsorshipSettings::tierByKey($settings['tiers'], $tierKey);
        if ($tier === null) {
            return new WP_Error('aiya_not_found', __('Unknown membership tier.', 'aiya-core'), ['status' => 404]);
        }
        // The tier's own configuration decides the cycle count (and with it
        // the total price); the buyer picks nothing but the payment channel.
        $cycles = (int) $tier['cycles'];
        // The purchase list hides disabled tiers client-side; the gate has
        // to hold server-side too, or a hand-crafted POST buys one anyway.
        if (!(bool) ($tier['enabled'] ?? true)) {
            return new WP_Error('aiya_tier_disabled', __('This membership tier is not available.', 'aiya-core'), ['status' => 410]);
        }

        // The browser return URL is the front end's own call: it knows the
        // page its payer initiated the checkout on and sends the landing
        // address with the order. Shape-validated only — the gateway sends
        // the payer's own browser there, so this is convenience routing,
        // not an authorization boundary (the notify callback is). The rule
        // mirrors the front end's own schema: absolute http(s), a host, no
        // credentials. wp_http_validate_url is deliberately NOT used: it is
        // a "may the server request this" gate and refuses loopback hosts,
        // which dev front ends legitimately live on.
        $returnUrl = (string) $request->get_param('returnUrl');
        if ($returnUrl !== '') {
            $parts = wp_parse_url($returnUrl);
            $valid = isset($parts['scheme'], $parts['host'])
                && in_array($parts['scheme'], ['http', 'https'], true)
                && (string) $parts['host'] !== ''
                && empty($parts['user']) && empty($parts['pass'])
                && !preg_match('/[\s<>"\']/', $returnUrl);
            if (!$valid) {
                return new WP_Error('aiya_invalid_return_url', __('The return URL is not a valid absolute http(s) address.', 'aiya-core'), ['status' => 400]);
            }
        }

        // Entropy beyond the second: two orders in the same second must
        // never collide on the platform's out_trade_no.
        $orderId = gmdate('Ymd') . str_pad((string) $userId, 5, '0', STR_PAD_LEFT) . time()
            . strtoupper(substr(md5(uniqid((string) wp_rand(), true)), 0, 6));
        $binding = (new IdSlugEncoder(8))->encodeId($userId) . '|' . $tier['key'] . '|' . $cycles;

        // The checkout's own record of what is about to be paid for: the
        // push later settles against this row instead of against values
        // travelling through the callback. A checkout that cannot be
        // recorded must not proceed — the push would have nothing to match.
        $pending = $this->orders->createPending(
            $userId,
            $gateway->orderId($orderId),
            $tier['key'],
            $cycles,
            round($tier['price'] * $cycles, 2),
            'epay'
        );
        if (is_wp_error($pending)) {
            return $pending;
        }

        $url = $gateway->createPayment([
            'orderId' => $orderId,
            // Production-proven shape: the buyer sees what they pay for
            // ("体验卡*1"-style — tier name × cycle count).
            'title' => sprintf('%s*%d', $tier['name'], $cycles),
            'amount' => round($tier['price'] * $cycles, 2),
            'channel' => $channel,
            'binding' => $binding,
            'returnUrl' => $returnUrl,
        ]);
        if (is_wp_error($url)) {
            return $url;
        }

        return new WP_REST_Response([
            'orderId' => $orderId,
            'submitUrl' => $url,
        ]);
    }

    /** The active gateway seam; adding a gateway swaps this one factory. */
    private function gateway(): ?PaymentGateway
    {
        return EpayGateway::fromSettings();
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }
}
