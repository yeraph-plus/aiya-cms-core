<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Redeem\AfdianRedemption;
use Aiya\Core\Domain\Payment\AfdianGateway;
use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Payment\OrderService;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The Afdian redemption chain over the redeem domain's own handler, after
 * the 2026-10-27 decoupling: the push or the redeem box trades only its
 * order number, the open-API query answers for everything else, and the
 * order's `plan_id` selects the row of the site's own plan table — which
 * supplies the entitlement snapshot. Money is booked straight into the paid
 * log under the platform's own order id.
 *
 * The unregistered-plan branch is the interesting one (D1 落账不激活): the
 * money is kept, nothing is granted, and the same order number walks on to
 * the activation once the plan is listed — so paying for a plan the site
 * forgot to register is a config fix, not a lost purchase.
 */
final class AfdianRedemptionTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const PLAN_GOLD = 'plan-gold';
    private const PLAN_SILVER = 'plan-silver';

    /** @var list<array<string, mixed>> */
    private const TIERS = [
        [
            'key' => 'gold',
            'planId' => self::PLAN_GOLD,
            'name' => 'Gold',
            'description' => '',
            'enabled' => true,
            'cycleDays' => 30,
            'creditsPerCycle' => 100,
            'maxCycles' => 12,
        ],
        [
            'key' => 'silver',
            'planId' => self::PLAN_SILVER,
            'name' => 'Silver',
            'description' => '',
            'enabled' => true,
            'cycleDays' => 30,
            'creditsPerCycle' => 20,
            'maxCycles' => 3,
        ],
    ];

    private MembershipTestWpdb $db;

    /** The platform's answer to query-order, keyed by trade number. @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** The ping's ec: 200 healthy, 400002 rejected, 0 unreachable. */
    private int $pingEc = 200;

    /** @var list<array<string, mixed>> */
    private array $tiers = self::TIERS;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $this->orders = [];
        $this->pingEc = 200;
        $this->tiers = self::TIERS;
        $GLOBALS['__aiya_test_users'] = [42 => true, 7 => true];
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
        $GLOBALS['__aiya_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** The activator over a fake transport: ping answers the staged ec, query-order the staged orders. */
    private function redemption(): AfdianRedemption
    {
        $client = new Client('user-1', self::TOKEN, function (string $url, string $payload): ?string {
            if (str_ends_with($url, '/ping')) {
                return $this->pingEc === 0 ? null : (string) json_encode(['ec' => $this->pingEc, 'em' => '']);
            }
            if (!str_ends_with($url, '/query-order')) {
                return null;
            }
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);
            $order = $this->orders[(string) ($params['out_trade_no'] ?? '')] ?? null;

            return (string) json_encode([
                'ec' => 200,
                'data' => ['list' => $order === null ? [] : [$order], 'total_count' => $order === null ? 0 : 1, 'total_page' => 1],
            ]);
        });

        $gateway = new AfdianGateway(
            $client,
            new Gateway($client, ''),
            true,
            $this->tiers
        );

        return new AfdianRedemption($gateway, new OrderService(), new EntitlementService());
    }

    /** A paid-trade order the API vouches for, attributed to a site account. @param array<string, mixed> $overrides */
    private function paidOrder(string $tradeNo, array $overrides = []): array
    {
        $order = array_merge([
            'out_trade_no' => $tradeNo,
            'user_id' => 'platform-buyer-hash',
            'plan_id' => self::PLAN_GOLD,
            'month' => 1,
            'total_amount' => '30.00',
            'status' => 2,
            'custom_order_id' => (new IdSlugEncoder(8))->encodeId(42),
        ], $overrides);
        $this->orders[$tradeNo] = $order;

        return $order;
    }

    private function push(string $tradeNo): array
    {
        // The push body carries facts too — status, amount, plan — but the
        // settle must not believe any of them, only the trade number.
        return ['ec' => 200, 'em' => 'ok', 'data' => ['type' => 'order', 'order' => [
            'out_trade_no' => $tradeNo,
            'plan_id' => 'totally-unbound',
            'month' => 99,
            'total_amount' => '0.01',
            'status' => 1,
        ]]];
    }

    // ------------------------------------------------------------- the webhook

    public function testWebhookBooksTheQueriedPurchaseDirectly(): void
    {
        $orders = new OrderService();
        $this->paidOrder('T100', ['month' => 2, 'total_amount' => '51.00']);

        $outcome = $this->redemption()->settlePush($this->push('T100'));

        self::assertStringContainsString('activated order T100', $outcome);
        self::assertStringContainsString('tier gold ×2', $outcome);

        // One paid row under the platform's own trade number, carrying
        // the queried facts (amount, cycles, plan-resolved tier), never
        // the push body's — and no second row, for there is no checkout
        // to settle into.
        $row = $orders->orderRow('afd_T100');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame(51.0, $row['amount']);
        self::assertSame(2, $row['cycles']);
        self::assertSame('gold', $row['tier_key']);
        self::assertCount(1, $this->db->rows[$this->db->paymentTable]);

        // And the entitlement queued once, on the real order id.
        $queue = $this->db->rows[$this->db->queueTable];
        self::assertCount(1, $queue);
        self::assertSame('afd_T100', $queue[0]['order_id']);
        self::assertSame(2, (int) $queue[0]['cycles_total']);
        self::assertSame('gold', $queue[0]['tier_key']);
        self::assertSame(30, (int) $queue[0]['cycle_days']);
        self::assertSame(100, (int) $queue[0]['credits_per_cycle']);
    }

    /**
     * The plan the buyer actually paid for picks the tier — the amount never
     * does. Two plans, two entitlements: buying the cheap one cannot buy the
     * expensive one, which is the whole point of holding a plan table.
     */
    public function testTheOrdersOwnPlanSelectsTheTier(): void
    {
        $this->paidOrder('T200', ['plan_id' => self::PLAN_SILVER, 'total_amount' => '99.00']);
        $this->paidOrder('T201', ['plan_id' => self::PLAN_GOLD, 'total_amount' => '0.01']);

        $redemption = $this->redemption();
        self::assertStringContainsString('tier silver', $redemption->settlePush($this->push('T200')));
        self::assertStringContainsString('tier gold', $redemption->settlePush($this->push('T201')));

        $orders = new OrderService();
        self::assertSame('silver', $orders->orderRow('afd_T200')['tier_key'], 'the plan decides, not the amount');
        self::assertSame('gold', $orders->orderRow('afd_T201')['tier_key'], 'a one-cent order still buys only its own plan');
    }

    /**
     * D1: a paid order whose plan is not listed books its money and grants
     * nothing. Never a fallback tier — that is exactly the "pay little, get
     * a lot" hole the plan table exists to close.
     */
    public function testAnUnregisteredPlanBooksTheMoneyWithoutGrantingAnything(): void
    {
        $this->paidOrder('T250', ['plan_id' => 'plan-not-listed', 'month' => 3]);
        $orders = new OrderService();

        $outcome = $this->redemption()->settlePush($this->push('T250'));

        self::assertStringContainsString('booked, plan unregistered', $outcome);

        $row = $orders->orderRow('afd_T250');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status'], 'the money is on the books');
        self::assertSame('', $row['tier_key'], 'no tier: the plan is not listed');
        self::assertSame(30.0, $row['amount']);
        self::assertSame(3, $row['cycles'], 'what was bought is still recorded');
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? [], 'nothing is granted');
    }

    /**
     * The self-heal the D1 booking buys: register the plan, re-push the same
     * trade number, and the order activates — no new mechanism, the duplicate
     * booking is tolerated and the entitlement insert is what was missing.
     */
    public function testARegisteredPlanActivatesTheEarlierBookingOnReplay(): void
    {
        $this->paidOrder('T260', ['plan_id' => 'plan-late', 'month' => 2]);
        $orders = new OrderService();

        self::assertStringContainsString('booked, plan unregistered', $this->redemption()->settlePush($this->push('T260')));
        self::assertSame('', $orders->orderRow('afd_T260')['tier_key']);

        // The operator adds the row.
        $this->tiers[] = [
            'key' => 'late',
            'planId' => 'plan-late',
            'name' => 'Late',
            'description' => '',
            'enabled' => true,
            'cycleDays' => 30,
            'creditsPerCycle' => 55,
            'maxCycles' => 12,
        ];

        $outcome = $this->redemption()->settlePush($this->push('T260'));
        self::assertStringContainsString('activated order T260', $outcome);

        self::assertCount(1, $this->db->rows[$this->db->paymentTable], 'the booking is not duplicated');
        self::assertSame('late', $orders->orderRow('afd_T260')['tier_key'], 'the blank tier key is filled in');

        $queue = $this->db->rows[$this->db->queueTable];
        self::assertCount(1, $queue);
        self::assertSame(2, (int) $queue[0]['cycles_total']);
        self::assertSame(55, (int) $queue[0]['credits_per_cycle']);
    }

    public function testWebhookReplayIsIdempotent(): void
    {
        $this->paidOrder('T300');
        $redemption = $this->redemption();

        $first = $redemption->settlePush($this->push('T300'));
        $second = $redemption->settlePush($this->push('T300'));

        self::assertStringContainsString('activated order T300', $first);
        self::assertStringContainsString('already activated', $second, 'the unique keys absorb the replay');
        self::assertCount(1, $this->db->rows[$this->db->paymentTable]);
        self::assertCount(1, $this->db->rows[$this->db->queueTable]);
    }

    public function testUnusablePushesAreLoggedAndTouchedNothing(): void
    {
        $redemption = $this->redemption();

        // No trade number in the push at all.
        self::assertStringContainsString('no order number', $redemption->settlePush(['data' => 'garbage']));

        // Unknown, unpaid, unattributed — each names its reason.
        self::assertStringContainsString('aiya_order_not_found', $redemption->settlePush($this->push('GHOST')));
        $this->paidOrder('T401', ['status' => 1]);
        self::assertStringContainsString('aiya_order_not_paid', $redemption->settlePush($this->push('T401')));
        $this->paidOrder('T402', ['custom_order_id' => '!!!not-a-code']);
        self::assertStringContainsString('aiya_order_unattributed', $redemption->settlePush($this->push('T402')));

        // API outage and credential rejection.
        $this->pingEc = 0;
        self::assertStringContainsString('aiya_afdian_unavailable', $redemption->settlePush($this->push('T404')));
        $this->pingEc = 400002;
        self::assertStringContainsString('aiya_afdian_rejected', $redemption->settlePush($this->push('T404')));

        self::assertSame([], $this->db->rows[$this->db->paymentTable] ?? [], 'no money booked by unusable pushes');
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? [], 'no rights queued by unusable pushes');
    }

    // ------------------------------------------------------- the manual path

    public function testManualActivationClaimsUnboundOrdersAndRefusesForeignOnes(): void
    {
        $this->paidOrder('T500', ['custom_order_id' => '']); // placed on the platform, no deep link
        $this->paidOrder('T501'); // carries user 42's binding

        $redemption = $this->redemption();

        // A storefront order carries no binding, and the trade number is
        // not a secret: the caller must also name the Afdian account that
        // paid, or the first person to type the number takes the purchase.
        $anonymous = $redemption->redeem(7, 'T500');
        self::assertInstanceOf(WP_Error::class, $anonymous);
        self::assertSame('aiya_order_buyer_required', $anonymous->get_error_code());

        $imposter = $redemption->redeem(7, 'T500', 'someone-else');
        self::assertInstanceOf(WP_Error::class, $imposter);
        self::assertSame('aiya_order_buyer_mismatch', $imposter->get_error_code());
        self::assertSame([], $this->db->rows[$this->db->paymentTable] ?? [], 'a refused claim books no money');

        self::assertSame(
            ['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1],
            $redemption->redeem(7, 'T500', 'platform-buyer-hash'),
            'the buyer the platform reports may claim their own order'
        );

        $bound = $redemption->redeem(7, 'T501');
        self::assertInstanceOf(WP_Error::class, $bound);
        self::assertSame('aiya_order_bound', $bound->get_error_code());

        // The owner can still activate it, and the already-activated
        // answer covers the double-typed number. A bound order needs no
        // buyer id: the deep link already proved who placed it.
        self::assertSame(['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1], $redemption->redeem(42, 'T501'));
        $used = $redemption->redeem(42, 'T501');
        self::assertInstanceOf(WP_Error::class, $used);
        self::assertSame('aiya_order_used', $used->get_error_code());
    }

    /**
     * The manual path cannot hide behind a generic failure when the plan is
     * unlisted: the buyer paid, the site booked it, and the only way forward
     * is a human. The answer names exactly that.
     */
    public function testTheManualPathNamesTheUnregisteredPlan(): void
    {
        $this->paidOrder('T520', ['plan_id' => '']); // an amount-only order: no plan at all

        $result = $this->redemption()->redeem(42, 'T520');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_plan_unregistered', $result->get_error_code());
        self::assertSame(422, $result->get_error_data()['status'] ?? 0);

        $row = (new OrderService())->orderRow('afd_T520');
        self::assertNotNull($row, 'the money is still booked, so the operator can see it');
        self::assertSame('', $row['tier_key']);
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? []);
    }

    /**
     * Money and rights are two writes, so there is a window where the
     * money row exists and the activation failed. A replay from another
     * account in that window must not close it under its own name: the
     * booked row, not the caller, decides who the entitlement belongs to.
     */
    public function testAReplayAfterAFailedActivationActivatesForTheBookedHolder(): void
    {
        $this->paidOrder('T700', ['custom_order_id' => '']);
        // A paid and its row got booked; the activation never landed.
        (new OrderService())->addPayment(42, 'afd_T700', 'gold', 30.0, 'afdian', 1);

        $result = $this->redemption()->redeem(7, 'T700', 'platform-buyer-hash');

        self::assertSame(['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1], $result);
        $queue = $this->db->rows[$this->db->queueTable];
        self::assertCount(1, $queue);
        self::assertSame(42, (int) $queue[0]['user_id'], 'the entitlement follows the money row, not the caller');
    }

    public function testQueriedCyclesClampToThePlansOwnCeiling(): void
    {
        // The queried month is platform truth, but one order may not queue
        // more cycles than its plan allows (silver: 3), and never more than
        // the settings-wide ceiling (60) whatever the row says.
        $this->paidOrder('T600', ['month' => 99, 'plan_id' => self::PLAN_SILVER]);
        $this->paidOrder('T601', ['month' => 99, 'plan_id' => self::PLAN_GOLD]);

        $redemption = $this->redemption();
        self::assertSame(3, $redemption->redeem(42, 'T600')['cycles'], 'the plan caps it at 3');
        self::assertSame(12, $redemption->redeem(42, 'T601')['cycles'], 'this plan allows 12');
    }

    public function testAMissingMonthBooksASingleCycle(): void
    {
        $this->paidOrder('T650', ['month' => 0]);

        self::assertSame(1, $this->redemption()->redeem(42, 'T650')['cycles']);
    }
}
