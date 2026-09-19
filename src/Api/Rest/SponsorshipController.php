<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\CheckinPolicy;
use Aiya\Core\Api\Contract\MembershipEntitlement;
use Aiya\Core\Api\Contract\MembershipState;
use Aiya\Core\Api\Contract\PlanChannels;
use Aiya\Core\Api\Contract\Tier;
use Aiya\Core\Api\Contract\TiersPayload;
use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
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
    private const MAX_CYCLES = 60;

    public function __construct(
        private MembershipService $membership,
        private EntitlementService $entitlements,
        private LedgerService $ledger,
        private RateLimiter $limiter,
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
                // Optional: pre-selects the months on the Afdian checkout.
                'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 36],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/sponsorship/orders', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->createOrder($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'tierKey' => ['type' => 'string', 'required' => true, 'maxLength' => 32],
                'channel' => ['type' => 'string', 'required' => true, 'enum' => ['alipay', 'wxpay', 'usdt']],
                'cycles' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CYCLES, 'default' => 1],
            ],
        ]);
    }

    private function plans(): WP_REST_Response
    {
        $gateway = $this->gateway();
        $afdian = AfdianGateway::fromSettings();

        $tiers = [];
        foreach (SponsorshipSettings::read()['tiers'] as $row) {
            $tiers[] = new Tier(
                (string) $row['key'],
                (string) $row['name'],
                (float) $row['price'],
                (int) $row['cycleDays'],
                (int) $row['creditsPerCycle'],
                (bool) ($row['enabled'] ?? true)
            );
        }

        return new WP_REST_Response((new TiersPayload(
            // Each gateway's own answer is authoritative: it knows both
            // the admin switch and whether credentials exist.
            new PlanChannels(
                $gateway !== null && $gateway->enabled(),
                $afdian !== null && $afdian->enabled(),
                $gateway?->channels() ?? []
            ),
            $tiers
        ))->toArray());
    }

    /**
     * The viewer's personalized Afdian order-create deep link for one
     * tier: the custom_order_id segment carries the user binding back
     * into the webhook, so the purchase self-attributes on arrival.
     */
    private function afdianOrderUrl(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $gateway = AfdianGateway::fromSettings();
        if ($gateway === null || !$gateway->enabled()) {
            return new WP_Error('aiya_afdian_unavailable', __('The Afdian channel is not available.', 'aiya-core'), ['status' => 502]);
        }

        $month = max(0, min(36, (int) $request->get_param('month')));
        $url = $gateway->orderUrl((int) get_current_user_id(), $month);
        if ($url === '') {
            return new WP_Error('aiya_plan_unbound', __('This tier is not bound to an Afdian plan.', 'aiya-core'), ['status' => 422]);
        }

        return new WP_REST_Response(['url' => $url]);
    }

    private function membershipState(): WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $window = $this->entitlements->window($userId);

        $queue = [];
        foreach ($this->entitlements->queueFor($userId) as $row) {
            $queue[] = (new MembershipEntitlement(
                $row['tier_key'],
                $row['tier_name'],
                $row['cycle_days'],
                $row['credits_per_cycle'],
                $row['cycles_total'],
                $row['cycles_granted'],
                $this->iso($row['starts_at']),
                $this->iso($row['ends_at']),
                $row['status'],
            ))->toArray();
        }

        $checkin = CreditSettings::read();

        return new WP_REST_Response((new MembershipState(
            $this->membership->isActive($userId),
            $window['expiresAt'] > 0 ? (string) wp_date('c', $window['expiresAt']) : null,
            $window['nextGrantAt'] > 0 ? (string) wp_date('c', $window['nextGrantAt']) : null,
            $this->ledger->balance($userId),
            $queue,
            new CheckinPolicy(
                $checkin['checkinEnabled'],
                $checkin['checkinCredits'],
                $checkin['validityDays']
            ),
        ))->toArray());
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
        $cycles = max(1, min(self::MAX_CYCLES, (int) $request->get_param('cycles')));
        $tier = SponsorshipSettings::tierByKey($settings['tiers'], $tierKey);
        if ($tier === null) {
            return new WP_Error('aiya_not_found', __('Unknown membership tier.', 'aiya-core'), ['status' => 404]);
        }
        // The purchase list hides disabled tiers client-side; the gate has
        // to hold server-side too, or a hand-crafted POST buys one anyway.
        if (!(bool) ($tier['enabled'] ?? true)) {
            return new WP_Error('aiya_tier_disabled', __('This membership tier is not available.', 'aiya-core'), ['status' => 410]);
        }

        // Entropy beyond the second: two orders in the same second must
        // never collide on the platform's out_trade_no.
        $orderId = gmdate('Ymd') . str_pad((string) $userId, 5, '0', STR_PAD_LEFT) . time()
            . strtoupper(substr(md5(uniqid((string) wp_rand(), true)), 0, 6));
        $binding = (new IdSlugEncoder(8))->encodeId($userId) . '|' . $tier['key'] . '|' . $cycles;

        $url = $gateway->createPayment([
            'orderId' => $orderId,
            // Production-proven shape: the buyer sees what they pay for
            // ("体验卡*1"-style — tier name × cycle count).
            'title' => sprintf('%s*%d', $tier['name'], $cycles),
            'amount' => round($tier['price'] * $cycles, 2),
            'channel' => $channel,
            'binding' => $binding,
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

    /** GMT DATETIME queue value → ISO 8601 for the wire. */
    private function iso(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
