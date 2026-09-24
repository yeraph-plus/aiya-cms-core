<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;

/**
 * The Afdian wire surface after the push-trust retirement (2026-09-21):
 * the package gateway's push reader (the one trusted field of a webhook
 * body — the trade number, everything else ignored), the order-create
 * deep link, and the core adapter's binding-table resolution (plan →
 * tier, fallback tier for the amount-only plan) that the activation
 * chain settles purchases with.
 */
final class AfdianGatewayTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const BOUND_PLAN = 'a45353328af911eb973052540025c377';

    protected function setUp(): void
    {
        delete_option('aiya_core_sponsorship_payments');
        delete_option('aiya_core_sponsorship');
    }

    private function gateway(): Gateway
    {
        return new Gateway(
            new Client('user-1', self::TOKEN),
            self::BOUND_PLAN,
            'gold',
            '来自「测试站」的会员订单'
        );
    }

    /** @param array<string, mixed> $order */
    private function pushBody(array $order): array
    {
        return ['ec' => 200, 'em' => 'ok', 'data' => ['type' => 'order', 'order' => $order]];
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

    public function testCreatePaymentBuildsTheOrderCreateDeepLink(): void
    {
        $client = new Client('user-1', self::TOKEN);
        $binding = $client->bindUser(42) . '|gold|1';

        $url = $this->gateway()->createPayment([
            'orderId' => 'ignored',
            'title' => 'Gold',
            'amount' => 30.0,
            'channel' => 'afdian',
            'binding' => $binding,
        ]);

        self::assertStringContainsString('afdian.com/order/create', $url);
        self::assertStringContainsString('plan_id=' . self::BOUND_PLAN, $url);
        self::assertStringContainsString('product_type=0', $url);
        self::assertStringContainsString('custom_order_id=' . rawurlencode($client->bindUser(42)), $url);
    }

    public function testOrderUrlPassesOptionalMonthThrough(): void
    {
        $gateway = $this->gateway();

        $withMonth = $gateway->orderUrl(42, 3);
        self::assertStringContainsString('&month=3', $withMonth);

        $withoutMonth = $gateway->orderUrl(42);
        self::assertStringNotContainsString('month=', $withoutMonth);
    }

    public function testOrderUrlIsEmptyWhenUnbound(): void
    {
        $gateway = new Gateway(new Client('user-1', self::TOKEN), '', null);

        self::assertSame('', $gateway->orderUrl(42));
    }

    // ------------------------------------------------- the core-side adapter

    /**
     * The WordPress half: settings → binding table. Many plans may bind,
     * one tier each; the amount-only plan falls into the fallback tier;
     * dangling bindings (tier deleted since) resolve like unknown plans.
     */
    public function testTheAdapterResolvesTheBindingTable(): void
    {
        update_option('aiya_core_sponsorship', [
            'tiers' => [
                ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
                ['key' => 'silver', 'name' => 'Silver', 'price' => 10, 'cycle_days' => 30, 'credits_per_cycle' => 20],
            ],
        ]);
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
            'afdian_bindings' => [
                ['plan_id' => self::BOUND_PLAN, 'tier_key' => 'gold'],
                ['plan_id' => 'plan-other', 'tier_key' => 'ghost-tier'], // dangling: dropped
                ['plan_id' => self::BOUND_PLAN, 'tier_key' => 'silver'], // duplicate plan: first row wins
                ['plan_id' => '', 'tier_key' => 'gold'], // plan-less row: dropped
            ],
            'afdian_fallback_tier' => 'silver',
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame('afdian', $adapter->id());
        self::assertTrue($adapter->enabled());
        self::assertSame([], $adapter->channels(), 'Afdian never rides the cashier');
        self::assertSame('afd_pending_AB', $adapter->orderId('pending_AB'));
        self::assertTrue($adapter->hasBindings());

        // Plan resolution: bound plan → its tier, unknown plan → null.
        self::assertSame('gold', $adapter->tierForPlan(self::BOUND_PLAN)['key'] ?? null);
        self::assertNull($adapter->tierForPlan('plan-other'), 'a binding naming a deleted tier resolves like an unknown plan');
        self::assertNull($adapter->tierForPlan('never-seen'));

        // The reverse lookup and the fallback.
        self::assertSame(self::BOUND_PLAN, $adapter->planForTier('gold'));
        self::assertNull($adapter->planForTier('silver'), 'the duplicate row lost, so silver has no plan');
        self::assertSame('silver', $adapter->fallbackTier()['key'] ?? null);

        // The deep link carries the user binding and the month pre-select.
        $url = $adapter->orderUrl(42, 3);
        self::assertStringContainsString('plan_id=' . self::BOUND_PLAN, $url);
        self::assertStringContainsString('custom_order_id=' . (new IdSlugEncoder(8))->encodeId(42), $url);
        self::assertStringContainsString('month=3', $url);

        // Per-tier links target the tier's own bound plan; a tier with no
        // binding refuses instead of landing on another plan's page.
        self::assertStringContainsString('plan_id=' . self::BOUND_PLAN, $adapter->orderUrl(42, 1, 'gold'));
        self::assertSame('', $adapter->orderUrl(42, 1, 'silver'), 'an unbound tier must not deep-link to the primary plan');
    }

    public function testWithoutBindingsTheChannelExistsButNothingIsBound(): void
    {
        update_option('aiya_core_sponsorship_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertFalse($adapter->hasBindings(), 'no binding rows: the plans() channel goes dark');
        self::assertNull($adapter->fallbackTier());
        self::assertNull($adapter->tierForPlan(self::BOUND_PLAN));
        self::assertSame('', $adapter->orderUrl(42));
    }

    public function testTheAdapterStaysOffWithoutCredentialsOrTheSwitch(): void
    {
        update_option('aiya_core_sponsorship_payments', ['afdian_enable' => true, 'afdian_user_id' => '', 'afdian_token' => self::TOKEN]);
        self::assertNull(AfdianGateway::fromSettings(), 'no user id');

        update_option('aiya_core_sponsorship_payments', ['afdian_enable' => true, 'afdian_user_id' => 'user-1', 'afdian_token' => '']);
        self::assertNull(AfdianGateway::fromSettings(), 'no token');

        update_option('aiya_core_sponsorship_payments', ['afdian_enable' => false, 'afdian_user_id' => 'user-1', 'afdian_token' => self::TOKEN]);
        self::assertNull(AfdianGateway::fromSettings(), 'switch off');
    }
}
