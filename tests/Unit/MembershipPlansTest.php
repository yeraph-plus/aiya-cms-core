<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\MembershipPresenter;
use Aiya\Core\Api\Rest\MembershipController;
use Aiya\Core\Api\Rest\RateLimiter;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Membership\MembershipService;
use Aiya\Core\Domain\Payment\OrderService;
use PHPUnit\Framework\TestCase;
use WP_REST_Response;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * GET /membership/plans after the 2026-10-27 decoupling: ONE list, two
 * product lines. A cashier tier is sold by the Epay checkout, an Afdian plan
 * by the platform, and each row says which — the purchase UI draws its
 * actions from the row instead of comparing keys against a single "the
 * Afdian tier" pointer.
 *
 * The Afdian rows carry no site-side price (0): the amount lives on the
 * platform page.
 */
final class MembershipPlansTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_transients'] = [];
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /** The public plan list, invoked through the route handler. */
    private function plans(): array
    {
        $controller = new MembershipController(
            new MembershipService(),
            new EntitlementService(),
            new LedgerService(),
            new OrderService(),
            new RateLimiter(),
            new MembershipPresenter()
        );
        $method = new \ReflectionMethod($controller, 'plans');
        $response = $method->invoke($controller);
        assert($response instanceof WP_REST_Response);

        $data = $response->get_data();
        assert(is_array($data));

        return $data;
    }

    /** @param list<array<string, mixed>> $rows */
    private function stageCashier(array $rows, bool $enabled = true): void
    {
        update_option('aiya_core_membership_payments', [
            'epay_enable' => $enabled,
            'epay_pid' => '1001',
            'epay_key' => 'secret',
            'epay_gateway' => 'https://pay.example.com/submit.php',
            'epay_methods' => ['alipay', 'wxpay'],
        ]);
        update_option('aiya_core_membership', ['tiers' => $rows]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function stageAfdian(array $rows, array $overrides = []): void
    {
        update_option('aiya_core_membership_payments', array_merge(
            (array) get_option('aiya_core_membership_payments', []),
            [
                'afdian_enable' => true,
                'afdian_user_id' => 'user-1',
                'afdian_token' => 't',
                'afdian_tiers' => $rows,
            ],
            $overrides
        ));
    }

    /** @return array<string, mixed> */
    private function plan(string $key, string $planId, array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'plan_id' => $planId,
            'key' => $key,
            'name' => ucfirst($key),
            'description' => '',
            'cycle_days' => 30,
            'credits_per_cycle' => 40,
            'max_cycles' => 12,
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $items */
    private function byKey(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    public function testBothProductLinesShareOneListAndNameTheirChannels(): void
    {
        $this->stageCashier([
            ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
        ]);
        $this->stageAfdian([$this->plan('sponsor', 'plan-sponsor')]);

        $payload = $this->plans();

        self::assertTrue($payload['channels']['epay']);
        self::assertTrue($payload['channels']['afdian']);
        self::assertSame('', $payload['channels']['afdianTierKey'], 'no plan is "the Afdian one" any more');
        self::assertSame(['alipay', 'wxpay'], $payload['channels']['methods']);

        self::assertSame(['gold', 'sponsor'], array_column($payload['items'], 'key'));

        $gold = $this->byKey($payload['items'], 'gold');
        self::assertNotNull($gold);
        self::assertSame(['epay'], $gold['channels']);
        self::assertSame(30.0, $gold['price']);

        $sponsor = $this->byKey($payload['items'], 'sponsor');
        self::assertNotNull($sponsor);
        self::assertSame(['afdian'], $sponsor['channels']);
        self::assertSame(0.0, $sponsor['price'], 'the amount lives on the platform');
        self::assertSame(30, $sponsor['cycleDays']);
        self::assertSame(40, $sponsor['creditsPerCycle']);
        self::assertTrue($sponsor['enabled']);
    }

    public function testTheAfdianChannelGoesDarkWithoutPlans(): void
    {
        $this->stageCashier([
            ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
        ]);
        $this->stageAfdian([]);

        $payload = $this->plans();

        self::assertFalse($payload['channels']['afdian'], 'nothing to sell: the channel is not offered');
        self::assertSame(['gold'], array_column($payload['items'], 'key'), 'the Afdian block adds no rows');
    }

    public function testAfdianPlansAreNotListedWhileTheIntegrationIsOff(): void
    {
        $this->stageCashier([
            ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
        ]);
        $this->stageAfdian([$this->plan('sponsor', 'plan-sponsor')], ['afdian_enable' => false]);

        $payload = $this->plans();

        self::assertFalse($payload['channels']['afdian']);
        self::assertSame(['gold'], array_column($payload['items'], 'key'));
    }

    /**
     * A disabled Afdian plan is still listed (with enabled=false) so the UI
     * can hide it uniformly, exactly like a disabled cashier tier.
     */
    public function testADisabledAfdianPlanIsListedButNotPurchasable(): void
    {
        $this->stageAfdian([$this->plan('sponsor', 'plan-sponsor', ['enabled' => false])]);

        $sponsor = $this->byKey($this->plans()['items'], 'sponsor');

        self::assertNotNull($sponsor);
        self::assertFalse($sponsor['enabled']);
    }

    /**
     * A key shared by both product lines would put two cards under one React
     * key. The save-time guard refuses to store such a config; this is the
     * runtime backstop for a hand-edited option — the cashier row wins and
     * the Afdian row is not offered.
     */
    public function testACollidingAfdianKeyIsNotOffered(): void
    {
        $this->stageCashier([
            ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
        ]);
        $this->stageAfdian([$this->plan('gold', 'plan-gold')]);

        $payload = $this->plans();

        self::assertSame(['gold'], array_column($payload['items'], 'key'));
        self::assertSame(['epay'], $this->byKey($payload['items'], 'gold')['channels']);
    }

    /** Cashier off: the tiers still list (they are the site's products), the channel says no. */
    public function testCashierTiersSurviveAnOffCashier(): void
    {
        $this->stageCashier([
            ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
        ], false);
        $this->stageAfdian([$this->plan('sponsor', 'plan-sponsor')]);

        $payload = $this->plans();

        self::assertFalse($payload['channels']['epay']);
        self::assertSame(['epay'], $this->byKey($payload['items'], 'gold')['channels'], 'the row still names its channel');
    }
}
