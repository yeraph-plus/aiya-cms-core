<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

use Aiya\Core\Api\Presenter\SponsorshipPresenter;
use Aiya\Core\Api\Rest\RateLimiter;
use Aiya\Core\Api\Rest\SponsorshipController;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/SponsorshipTestWpdb.php';

if (!class_exists('WP_REST_Request')) {
    // The REST stack is not shimmed in bootstrap; the rate-limit test only
    // needs an inert request object that satisfies the controller's hint.
    final class FakeRestRequest
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
    class_alias(FakeRestRequest::class, 'WP_REST_Request');
}

if (!class_exists('WP_REST_Response')) {
    // Same for the deep link's success face: the controller wraps the URL
    // in a response object the suite only needs to carry the payload.
    final class FakeRestResponse
    {
        public function __construct(private mixed $data = null)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }
    }
    class_alias(FakeRestResponse::class, 'WP_REST_Response');
}

/**
 * The personalized Afdian deep link rides the same fixed-window limiter
 * as the cashier's order builder (10 per 10 minutes per IP): both
 * endpoints mint one outbound platform artifact per hit, and the webhook
 * hands the buyer no second budget. Minting writes nothing local — the
 * books open only on the webhook→query settle (0.104.0).
 */
final class AfdianOrderUrlTest extends TestCase
{
    private SponsorshipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new SponsorshipTestWpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [1 => true];
        $GLOBALS['__aiya_test_current_user_id'] = 0; // the route gate owns auth; the limiter is hit first regardless
        $GLOBALS['__aiya_test_transients'] = [];
        delete_option('aiya_core_sponsorship_payments');
        delete_option('aiya_core_sponsorship');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** The controller with its production collaborators over the shared wpdb double. */
    private function controller(): SponsorshipController
    {
        return new SponsorshipController(
            new MembershipService(),
            new EntitlementService(),
            new LedgerService(),
            new OrderService(),
            new RateLimiter(),
            new SponsorshipPresenter()
        );
    }

    public function testOrderUrlSharesTheCashierRateWindow(): void
    {
        // No plan bindings: the limiter is what is under test, so every
        // in-budget hit is allowed to fail downstream on the missing deep
        // link (aiya_plan_unbound) — which proves the limiter was not what
        // stopped them.
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 't',
        ]);
        update_option('aiya_core_sponsorship', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);

        $method = new \ReflectionMethod($this->controller(), 'afdianOrderUrl');
        $request = new FakeRestRequest(['tierKey' => 'gold']);

        // Hits 1–10 pass the gate and fail on the unbound plan; hit 11
        // answers the limiter.
        for ($i = 0; $i < 10; $i++) {
            $result = $method->invoke($this->controller(), $request);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_plan_unbound', $result->get_error_code());
        }

        $limited = $method->invoke($this->controller(), $request);
        self::assertInstanceOf(WP_Error::class, $limited);
        self::assertSame('aiya_rate_limited', $limited->get_error_code());
    }

    /**
     * The deep link obeys the same server-side gate as the checkout POST:
     * a hand-crafted order-url request must not pre-select a tier the
     * site pulled from sale.
     */
    public function testOrderUrlRefusesADisabledTierServerSide(): void
    {
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 't',
            'afdian_bindings' => [['plan_id' => 'plan-gold', 'tier_key' => 'gold']],
        ]);
        update_option('aiya_core_sponsorship', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100, 'enabled' => false]],
        ]);

        $method = new \ReflectionMethod($this->controller(), 'afdianOrderUrl');
        $result = $method->invoke($this->controller(), new FakeRestRequest(['tierKey' => 'gold']));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_tier_disabled', $result->get_error_code());
        self::assertSame(410, $result->get_error_data()['status'] ?? 0);
    }

    /**
     * Minting the link writes nothing: no checkout row to age out, no
     * queue entry — booking happens only when a webhook-delivered trade
     * number survives the open-API re-query.
     */
    public function testOrderUrlReturnsTheLinkWithoutBookingAnything(): void
    {
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 't',
            'afdian_bindings' => [['plan_id' => 'plan-gold', 'tier_key' => 'gold']],
        ]);
        update_option('aiya_core_sponsorship', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);
        $GLOBALS['__aiya_test_current_user_id'] = 1;

        $method = new \ReflectionMethod($this->controller(), 'afdianOrderUrl');
        $result = $method->invoke($this->controller(), new FakeRestRequest(['tierKey' => 'gold']));

        self::assertNotInstanceOf(WP_Error::class, $result);
        $data = $result->get_data();
        self::assertStringContainsString('afdian.com/order/create', (string) $data['url']);
        self::assertStringContainsString('plan_id=plan-gold', (string) $data['url']);
        self::assertStringContainsString('custom_order_id=', (string) $data['url'], 'the user binding rides the link');
        self::assertSame([], $this->db->rows[$this->db->paymentTable] ?? [], 'minting the deep link writes no order row');
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? []);
    }
}

}
