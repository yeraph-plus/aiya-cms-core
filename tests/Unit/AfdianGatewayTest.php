<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\AfdianGateway;
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
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
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
     * The WordPress half: settings → the single plan/tier pairing. The
     * pairing needs both halves — a plan id without a tier (or the tier
     * deleted since) keeps the channel dark, never a half-bound deep link.
     */
    public function testTheAdapterResolvesTheSinglePairing(): void
    {
        update_option('aiya_core_membership', [
            'tiers' => [
                ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
            ],
        ]);
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
            'afdian_plan_id' => self::BOUND_PLAN,
            'afdian_tier' => 'gold',
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame('afdian', $adapter->id());
        self::assertTrue($adapter->enabled());
        self::assertSame([], $adapter->channels(), 'Afdian never rides the cashier');
        self::assertSame('afd_pending_AB', $adapter->orderId('pending_AB'));
        self::assertSame('gold', $adapter->boundTier()['key'] ?? null);

        // The deep link carries the single bound plan and the user binding,
        // with the month pre-select riding through.
        $url = $adapter->orderUrl(42, 3);
        self::assertStringContainsString('plan_id=' . self::BOUND_PLAN, $url);
        self::assertStringContainsString('custom_order_id=' . (new IdSlugEncoder(8))->encodeId(42), $url);
        self::assertStringContainsString('month=3', $url);
    }

    public function testWithoutThePairingTheChannelGoesDark(): void
    {
        update_option('aiya_core_membership_payments', [
            'afdian_enable' => true,
            'afdian_user_id' => 'user-1',
            'afdian_token' => self::TOKEN,
            // A plan without a tier — the pairing is half-configured.
            'afdian_plan_id' => self::BOUND_PLAN,
        ]);

        $adapter = AfdianGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertNull($adapter->boundTier(), 'no tier bound: the plans() channel goes dark');
        self::assertSame('', $adapter->orderUrl(42));
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
}
