<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\AfdianGateway;
use Aiya\Core\Domain\Payment\AfdianTiers;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The Afdian wire surface after the 2026-10-27 decoupling: the package
 * gateway's push reader (the one trusted field of a webhook body — the trade
 * number, everything else ignored) and its per-link order-create deep link,
 * plus the core adapter's own plan table (AfdianTiers) that maps platform
 * plan ids to entitlement snapshots. Nothing here resolves a membership tier:
 * Afdian sells its own plans.
 */
final class AfdianGatewayTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const PLAN = 'a45353328af911eb973052540025c377';

    protected function setUp(): void
    {
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
    }

    private function gateway(): Gateway
    {
        return new Gateway(
            new Client('user-1', self::TOKEN),
            '来自「测试站」的会员订单'
        );
    }

    /** @param array<string, mixed> $order */
    private function pushBody(array $order): array
    {
        return ['ec' => 200, 'em' => 'ok', 'data' => ['type' => 'order', 'order' => $order]];
    }

    /** One configured plan row, the shape the settings page stores. */
    private function planRow(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'plan_id' => self::PLAN,
            'key' => 'sponsor',
            'name' => 'Sponsor',
            'description' => 'Every cycle grants 100 credits.',
            'cycle_days' => 30,
            'credits_per_cycle' => 100,
            'max_cycles' => 12,
        ], $overrides);
    }

    private function stagePlan(array $overrides = []): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
            'afdian_tiers' => [$this->planRow($overrides)],
        ]);
    }

    // ----------------------------------------------------- the package gateway

    public function testPushReaderResolvesTheTradeNumberAndNothingElse(): void
    {
        $body = $this->pushBody([
            'out_trade_no' => '20260921123',
            'plan_id' => 'some-unbound-plan',
            'month' => 999,
            'total_amount' => '0.01',
            'status' => 1, // the push says "pending" — never consulted, the API re-read decides
        ]);

        self::assertSame('20260921123', $this->gateway()->pushOrderNo($body));
    }

    public function testPushReaderRefusesMalformedBodies(): void
    {
        $gateway = $this->gateway();

        self::assertNull($gateway->pushOrderNo([]));
        self::assertNull($gateway->pushOrderNo(['data' => 'garbage']));
        self::assertNull($gateway->pushOrderNo(['data' => ['type' => 'order']]));
        self::assertNull($gateway->pushOrderNo(['data' => ['order' => 'not-an-array']]));
        self::assertNull($gateway->pushOrderNo(['data' => ['order' => ['out_trade_no' => '']]]));
    }

    public function testOrderUrlAimsAtThePlanTheCallerNames(): void
    {
        $client = new Client('user-1', self::TOKEN);

        $url = $this->gateway()->orderUrl(42, self::PLAN, 3);

        self::assertStringContainsString('afdian.com/order/create', $url);
        self::assertStringContainsString('plan_id=' . self::PLAN, $url);
        self::assertStringContainsString('product_type=0', $url);
        self::assertStringContainsString('custom_order_id=' . rawurlencode($client->bindUser(42)), $url);
        self::assertStringContainsString('&month=3', $url);
    }

    public function testOrderUrlWithoutAMonthLeavesTheParamOff(): void
    {
        $url = $this->gateway()->orderUrl(42, self::PLAN);

        self::assertStringNotContainsString('month=', $url);
    }

    public function testOrderUrlIsEmptyWithoutAPlanId(): void
    {
        self::assertSame('', $this->gateway()->orderUrl(42, ''));
    }

    // ------------------------------------------------- the core-side adapter

    /**
     * The WordPress half: settings → the site's own plan table. The table is
     * the payment domain's, not a pointer into the membership tier list.
     */
    public function testTheAdapterOwnsItsPlanTable(): void
    {
        $this->stagePlan();

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame('afdian', $adapter->id());
        self::assertTrue($adapter->enabled());
        self::assertSame([], $adapter->channels(), 'Afdian never rides the cashier');
        self::assertSame('afd_pending_AB', $adapter->orderId('pending_AB'));

        $tier = $adapter->tierForPlan(self::PLAN);
        self::assertNotNull($tier);
        self::assertSame('sponsor', $tier['key']);
        self::assertSame(30, $tier['cycleDays']);
        self::assertSame(100, $tier['creditsPerCycle']);
        self::assertSame(12, $tier['maxCycles']);
        self::assertSame('sponsor', $adapter->tierByKey('sponsor')['key'] ?? null);
        self::assertNull($adapter->tierForPlan('plan-nobody-registered'));

        // The deep link carries this row's platform plan and the user binding.
        $url = $adapter->orderUrl(42, 'sponsor', 3);
        self::assertStringContainsString('plan_id=' . self::PLAN, $url);
        self::assertStringContainsString('custom_order_id=' . (new IdSlugEncoder(8))->encodeId(42), $url);
        self::assertStringContainsString('month=3', $url);
    }

    /** No plan table: the channel is on but nothing is sellable. */
    public function testAnEmptyPlanTableLeavesEveryLinkDark(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame([], $adapter->tiers());
        self::assertSame('', $adapter->orderUrl(42, 'sponsor'));
    }

    /**
     * `enabled` means "stop selling", never "refuse a purchase that was
     * already paid for": a disabled row still settles, but refuses the deep
     * link.
     */
    public function testADisabledPlanStillSettlesButRefusesTheDeepLink(): void
    {
        $this->stagePlan(['enabled' => false]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertNotNull($adapter->tierForPlan(self::PLAN), 'settlement does not care about the sales switch');
        self::assertSame('', $adapter->orderUrl(42, 'sponsor'), 'the link respects it');
    }

    /**
     * The membership tier list is a different product line: an Afdian adapter
     * built over a site whose cashier sells tiers must not pick any of them up.
     */
    public function testTheAdapterNeverReadsTheMembershipTiers(): void
    {
        update_option('aiya_core_membership', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
            // The retired pointer at a cashier tier: nothing reads it.
            'afdian_plan_id' => self::PLAN,
            'afdian_tier' => 'gold',
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame([], $adapter->tiers());
        self::assertNull($adapter->tierByKey('gold'));
        self::assertSame('', $adapter->orderUrl(42, 'gold'));
    }

    public function testTheAdapterRefusesTheCashierSurface(): void
    {
        $this->stagePlan();
        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);

        $refused = $adapter->createPayment([
            'orderId' => 'x',
            'title' => 't',
            'amount' => 30.0,
            'channel' => 'afdian',
            'binding' => '',
        ]);

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_afdian_no_cashier', $refused->get_error_code());
    }

    public function testTheAdapterStaysOffWithoutCredentialsOrTheSwitch(): void
    {
        update_option('aiya_core_membership_payments', ['afdian_enable' => true, 'afdian_user_id' => '', 'afdian_token' => self::TOKEN]);
        self::assertNull(AfdianGateway::fromSettings(), 'no user id');

        update_option('aiya_core_membership_payments', ['afdian_enable' => true, 'afdian_user_id' => 'user-1', 'afdian_token' => '']);
        self::assertNull(AfdianGateway::fromSettings(), 'no token');

        update_option('aiya_core_membership_payments', ['afdian_enable' => false, 'afdian_user_id' => 'user-1', 'afdian_token' => self::TOKEN]);
        self::assertNull(AfdianGateway::fromSettings(), 'switch off');
    }

    // ----------------------------------------------------------- the table read

    public function testTheTableNormalizesIntoTheSnapshotShape(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_tiers' => [
                [
                    'enabled' => 1,
                    'plan_id' => ' Plan-A_1 ',
                    'key' => 'SPONSOR',
                    'name' => '  ',
                    'description' => "  line\n",
                    'cycle_days' => '0',
                    'credits_per_cycle' => '-5',
                    'max_cycles' => '999',
                ],
            ],
        ]);

        $tiers = AfdianTiers::read();
        self::assertCount(1, $tiers);
        self::assertSame('Plan-A_1', $tiers[0]['planId'], 'trimmed, case preserved (an opaque platform token)');
        self::assertSame('sponsor', $tiers[0]['key'], 'keys are sanitized like every other key');
        self::assertSame('sponsor', $tiers[0]['name'], 'a nameless row falls back to its key');
        self::assertSame('line', $tiers[0]['description']);
        self::assertSame(1, $tiers[0]['cycleDays']);
        self::assertSame(0, $tiers[0]['creditsPerCycle']);
        self::assertSame(AfdianTiers::MAX_CYCLES, $tiers[0]['maxCycles'], 'the ceiling clamps a garbage max');
    }

    public function testRowsWithoutBothHalvesOfAnIdentityAreDropped(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_tiers' => [
                ['key' => 'a', 'plan_id' => ''],
                ['key' => '', 'plan_id' => 'p1'],
                ['key' => 'b', 'plan_id' => 'has space'],
                ['key' => 'c', 'plan_id' => 'p2'],
                ['key' => 'c', 'plan_id' => 'p3'], // duplicate key
                ['key' => 'd', 'plan_id' => 'p2'], // duplicate plan
                'garbage',
                ['key' => 'e', 'plan_id' => 'p4'],
            ],
        ]);

        self::assertSame(['c', 'e'], array_column(AfdianTiers::read(), 'key'));
    }
}
