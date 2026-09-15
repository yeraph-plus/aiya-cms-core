<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianClient;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use WP_Error;
use PHPUnit\Framework\TestCase;

final class AfdianGatewayTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const BOUND_PLAN = 'a45353328af911eb973052540025c377';

    /** @var array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int} */
    private array $boundTier = ['key' => 'gold', 'name' => 'Gold', 'price' => 30.0, 'cycleDays' => 30, 'creditsPerCycle' => 100];

    private function gateway(): AfdianGateway
    {
        return new AfdianGateway(new AfdianClient('user-1', self::TOKEN, null, self::keyPair()['public']), true, self::BOUND_PLAN, $this->boundTier, '测试站');
    }

    /** RSA test pair standing in for the platform keypair (never ships).
     *
     * @return array{private: string, public: string}
     */
    private static function keyPair(): array
    {
        static $pair = null;
        if ($pair === null) {
            $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($res);
            openssl_pkey_export($res, $private);
            $details = openssl_pkey_get_details($res);
            self::assertIsArray($details);
            $pair = ['private' => $private, 'public' => (string) $details['key']];
        }

        return $pair;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function signedBody(array $order, int $userId = 42): array
    {
        $client = new AfdianClient('user-1', self::TOKEN, null, self::keyPair()['public']);
        $order['custom_order_id'] = $client->bindUser($userId);
        $order['status'] = $order['status'] ?? 2; // official doc: 2 = trade success
        $order['user_id'] = 'adf397fe8374811eaacee52540025c377'; // official sample: the buyer's platform hash
        $order['total_amount'] = $order['total_amount'] ?? '5.00'; // signed field — always present on real pushes
        // Official doc: sign_str = out_trade_no . user_id . plan_id . total_amount.
        openssl_sign(
            $order['out_trade_no'] . $order['user_id'] . $order['plan_id'] . $order['total_amount'],
            $signature,
            self::keyPair()['private'],
            'sha256'
        );

        return [
            'ec' => 200,
            'em' => 'ok',
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding the test's RSA signature exactly as the platform does
            'data' => ['type' => 'order', 'order' => $order, 'sign' => base64_encode($signature)],
        ];
    }

    public function testVerifyCallbackResolvesTheBoundPaymentDescription(): void
    {
        $body = $this->signedBody(['out_trade_no' => '20260915123', 'plan_id' => self::BOUND_PLAN, 'month' => 3, 'total_amount' => '90.0']);

        $payment = $this->gateway()->verifyCallback($body);

        self::assertNotNull($payment);
        self::assertSame('afd_20260915123', $payment['orderId']);
        self::assertSame(42, $payment['userId']);
        self::assertSame('gold', $payment['tierKey']);
        self::assertSame(3, $payment['cycles']);
        self::assertSame(90.0, $payment['amount']);
        self::assertFalse($this->gateway()->callbackFailed($body));
    }

    public function testClampsAbsurdMonthCounts(): void
    {
        $payment = $this->gateway()->verifyCallback(
            $this->signedBody(['out_trade_no' => 'x1', 'plan_id' => self::BOUND_PLAN, 'month' => 999, 'total_amount' => '1'])
        );

        self::assertNotNull($payment);
        self::assertSame(36, $payment['cycles']);
    }

    public function testTamperedSignatureIsACallbackFailure(): void
    {
        $gateway = $this->gateway();
        $body = $this->signedBody(['out_trade_no' => 'x2', 'plan_id' => self::BOUND_PLAN, 'month' => 1]);
        $body['data']['sign'] = 'deadbeef';

        self::assertNull($gateway->verifyCallback($body));
        self::assertTrue($gateway->callbackFailed($body));
    }

    public function testSignedButUnusablePushesAreIgnoredNotFailed(): void
    {
        $gateway = $this->gateway();

        // Unbound plan (e.g. the optional-amount plan).
        $unbound = $gateway->verifyCallback($this->signedBody(['out_trade_no' => 'x3', 'plan_id' => 'some-other-plan', 'month' => 1, 'total_amount' => '5.00']));
        self::assertNull($unbound);
        self::assertFalse($gateway->callbackFailed($this->signedBody(['out_trade_no' => 'x3', 'plan_id' => 'some-other-plan', 'month' => 1, 'total_amount' => '5.00'])));

        // No resolvable user binding.
        $body = $this->signedBody(['out_trade_no' => 'x4', 'plan_id' => self::BOUND_PLAN, 'month' => 1, 'total_amount' => '5.00']);
        $body['data']['order']['custom_order_id'] = '!!!not-a-code';
        self::assertNull($gateway->verifyCallback($body));
    }

    public function testNonSuccessfulTradesAreIgnored(): void
    {
        $gateway = $this->gateway();
        // Official doc: status 2 = trade success; pending/refunded never book.
        $unpaid = $gateway->verifyCallback($this->signedBody(['out_trade_no' => 'x5', 'plan_id' => self::BOUND_PLAN, 'month' => 1, 'status' => 1, 'total_amount' => '5.00']));
        self::assertNull($unpaid);
        self::assertFalse($gateway->callbackFailed($this->signedBody(['out_trade_no' => 'x5', 'plan_id' => self::BOUND_PLAN, 'month' => 1, 'status' => 1, 'total_amount' => '5.00'])));
    }

    public function testNonOrderPushTypesAreIgnored(): void
    {
        $gateway = $this->gateway();
        $body = $this->signedBody(['out_trade_no' => 'x6', 'plan_id' => self::BOUND_PLAN, 'month' => 1, 'total_amount' => '5.00']);
        $body['data']['type'] = 'goods';
        self::assertNull($gateway->verifyCallback($body));
    }

    public function testCreatePaymentBuildsTheOrderCreateDeepLink(): void
    {
        $client = new AfdianClient('user-1', self::TOKEN);
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
        $gateway = new AfdianGateway(new AfdianClient('user-1', self::TOKEN, null, self::keyPair()['public']), true, '', null, '测试站');

        self::assertSame('', $gateway->orderUrl(42));
    }
}
