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

if (!class_exists('WP_REST_Request')) {
    // The REST stack is not shimmed in bootstrap; the rate-limit test only
    // needs an inert request object that satisfies the controller's hint.
    final class FakeRestRequest
    {
        public function get_param(string $key): mixed
        {
            return null;
        }
    }
    class_alias(FakeRestRequest::class, 'WP_REST_Request');
}

/**
 * The personalized Afdian deep link rides the same fixed-window limiter
 * as the cashier's order builder (10 per 10 minutes per IP): both
 * endpoints mint one outbound platform artifact per hit, and the webhook
 * hands the buyer no second budget.
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
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => 't',
            'afdian_bindings' => [['plan_id' => 'plan-gold', 'tier_key' => 'gold']],
        ]);
        update_option('aiya_core_sponsorship', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);

        $method = new \ReflectionMethod($this->controller(), 'afdianOrderUrl');
        $request = new FakeRestRequest();

        // Hits 1–10 pass the gate (they fail later on the absent session
        // user — anonymous here — which proves the limiter was not what
        // stopped them); hit 11 answers the limiter.
        for ($i = 0; $i < 10; $i++) {
            $result = $method->invoke($this->controller(), $request);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertNotSame('aiya_rate_limited', $result->get_error_code());
        }

        $limited = $method->invoke($this->controller(), $request);
        self::assertInstanceOf(WP_Error::class, $limited);
        self::assertSame('aiya_rate_limited', $limited->get_error_code());
    }
}

}

namespace {
    // The controller reaches the global wp_rand() while minting the
    // checkout placeholder id; bootstrap does not shim it.
    if (!function_exists('wp_rand')) {
        function wp_rand(int $min = 0, int $max = 0): int
        {
            return random_int(0, PHP_INT_MAX);
        }
    }
}
