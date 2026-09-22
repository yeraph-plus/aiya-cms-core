<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Infra\PaymentEpay\Client;
use Aiya\Infra\PaymentEpay\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The Epay path: the package gateway's callback normalization (the money
 * entry point of the cashier flow, previously untested) and the core
 * adapter's WordPress-side mapping on top of it.
 *
 * Callbacks are built the way the platform builds them: the package
 * client signs the parameter set, and the query string it emits is parsed
 * back into the array the callback route receives.
 */
final class EpayGatewayTest extends TestCase
{
    private const PID = '1001';
    private const KEY = 'merchant-key';
    private const BASE = 'https://pay.example.test';

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
        delete_option('aiya_core_sponsorship_payments');
        delete_option('aiya_core_sponsorship');
    }

    /** @param array<string, mixed> $overrides */
    private function signedCallback(array $overrides = []): array
    {
        $client = new Client(self::PID, self::KEY, self::BASE);
        $binding = (new IdSlugEncoder(8))->encodeId(42) . '|gold|3';

        $query = array_merge([
            'out_trade_no' => '20260915001',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '90.00',
            'param' => $binding,
            'type' => 'alipay',
        ], $overrides);

        // Signed exactly as the cashier signs, then read back the way the
        // callback route receives it.
        parse_str($client->buildSubmitQuery($query), $callback);

        return $callback;
    }

    /** @param list<string> $tierKeys */
    private function gateway(array $tierKeys = ['gold']): Gateway
    {
        return new Gateway(
            new Client(self::PID, self::KEY, self::BASE),
            'https://aiya.test/wp-json/aiya/sponsorship/v1/epay/callback',
            'https://aiya.test/return',
            $tierKeys
        );
    }

    public function testNormalizesANormalCallbackIntoThePaymentDescription(): void
    {
        $payment = $this->gateway()->verifyCallback($this->signedCallback());

        self::assertNotNull($payment);
        self::assertSame('epc_20260915001', $payment['orderId']);
        self::assertSame(42, $payment['userId']);
        self::assertSame('gold', $payment['tierKey']);
        self::assertSame(3, $payment['cycles']);
        self::assertSame(90.0, $payment['amount'], 'the amount the platform reported, not a settings recompute');
    }

    public function testTamperedSignatureIsTheOneFailureThePlatformHearsAbout(): void
    {
        $query = $this->signedCallback();
        $query['sign'] = 'deadbeef';

        self::assertNull($this->gateway()->verifyCallback($query));
        self::assertTrue($this->gateway()->callbackFailed($query), '400 so the platform retries');
    }

    public function testNonSuccessTradesAreIgnoredNotFailed(): void
    {
        $query = $this->signedCallback(['trade_status' => 'WAIT_BUYER_PAY']);

        self::assertNull($this->gateway()->verifyCallback($query));
        self::assertFalse($this->gateway()->callbackFailed($query), 'signed and unusable: answer success');
    }

    public function testBrokenBindingsAreRefused(): void
    {
        self::assertNull($this->gateway()->verifyCallback($this->signedCallback(['param' => ''])));
        self::assertNull($this->gateway()->verifyCallback($this->signedCallback(['param' => 'not-a-code|gold|3'])));
        self::assertNull($this->gateway()->verifyCallback($this->signedCallback(['param' => (new IdSlugEncoder(8))->encodeId(42) . '||3'])));
        self::assertNull($this->gateway()->verifyCallback($this->signedCallback(['param' => (new IdSlugEncoder(8))->encodeId(42) . '|gold|0'])));
        self::assertNull($this->gateway()->verifyCallback($this->signedCallback(['out_trade_no' => ''])));
    }

    /** The tier-existence rule the site chose to keep inside the gateway. */
    public function testATierThisSiteNoLongerSellsIsRefused(): void
    {
        self::assertNull($this->gateway(['silver'])->verifyCallback($this->signedCallback()));
        self::assertNotNull($this->gateway(['gold'])->verifyCallback($this->signedCallback()));
    }

    public function testCreatePaymentSignsTheCashierUrl(): void
    {
        $url = $this->gateway()->createPayment([
            'orderId' => '20260915001',
            'title' => 'Gold*3',
            'amount' => 90.0,
            'channel' => 'alipay',
            'binding' => (new IdSlugEncoder(8))->encodeId(42) . '|gold|3',
        ]);

        self::assertStringStartsWith(self::BASE . '/submit.php?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(self::PID, $query['pid']);
        self::assertSame('90.00', $query['money']);
        self::assertSame('alipay', $query['type']);
        self::assertSame('MD5', $query['sign_type']);
        self::assertSame(
            'https://aiya.test/wp-json/aiya/sponsorship/v1/epay/callback',
            $query['notify_url'],
            'the adapter hands the package the route the platform must push to'
        );
        self::assertTrue((new Client(self::PID, self::KEY, self::BASE))->verifyCallback($query));
    }

    // ------------------------------------------------- the core-side adapter

    public function testTheAdapterMapsConfigurationIntoThePackageGateway(): void
    {
        update_option('aiya_core_sponsorship_payments', [
            'epay_enable' => true,
            'epay_pid' => self::PID,
            'epay_key' => self::KEY,
            'epay_gateway' => self::BASE,
            'epay_methods' => ['alipay', 'wxpay'],
            'epay_return_url' => 'https://aiya.test/return',
        ]);

        $adapter = EpayGateway::fromSettings();
        self::assertNotNull($adapter);
        self::assertSame('epay', $adapter->id());
        self::assertTrue($adapter->enabled());
        self::assertSame(['alipay', 'wxpay'], $adapter->channels());

        $url = $adapter->createPayment([
            'orderId' => '20260915002',
            'title' => 'Gold',
            'amount' => 30.0,
            'channel' => 'alipay',
            'binding' => (new IdSlugEncoder(8))->encodeId(42) . '|gold|1',
        ]);
        self::assertIsString($url);
        self::assertStringStartsWith(self::BASE . '/submit.php?', $url);
    }

    /**
     * The id a checkout row is written under must be the id the same
     * order's push resolves — the settle path matches one against the
     * other. They diverged once (the row kept the bare wire id while the
     * push resolved the prefixed one), and every settled payment quietly
     * became "not a checkout of this site" while the platform still got
     * its success answer: nothing but the HTTP round trip caught it.
     */
    public function testTheCheckoutIdMatchesTheIdItsCallbackResolves(): void
    {
        update_option('aiya_core_sponsorship', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);
        update_option('aiya_core_sponsorship_payments', [
            'epay_enable' => true,
            'epay_pid' => self::PID,
            'epay_key' => self::KEY,
            'epay_gateway' => self::BASE,
            'epay_methods' => ['alipay'],
        ]);

        $adapter = EpayGateway::fromSettings();
        self::assertNotNull($adapter);

        $payment = $adapter->verifyCallback($this->signedCallback(['out_trade_no' => '20260915009']));
        self::assertNotNull($payment);
        self::assertSame('epc_20260915009', $adapter->orderId('20260915009'));
        self::assertSame($adapter->orderId('20260915009'), $payment['orderId']);
    }

    public function testTheAdapterRefusesDisabledChannelsAndUnknownOnes(): void
    {
        update_option('aiya_core_sponsorship_payments', [
            'epay_enable' => true,
            'epay_pid' => self::PID,
            'epay_key' => self::KEY,
            'epay_gateway' => self::BASE,
            'epay_methods' => ['alipay'],
        ]);

        $adapter = EpayGateway::fromSettings();
        self::assertNotNull($adapter);

        $refused = $adapter->createPayment([
            'orderId' => 'x',
            'title' => 'Gold',
            'amount' => 30.0,
            'channel' => 'wxpay',
            'binding' => '',
        ]);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_channel_unavailable', $refused->get_error_code());
        self::assertSame(502, $refused->get_error_data()['status']);
    }

    public function testTheAdapterIsAbsentUntilCredentialsExist(): void
    {
        self::assertNull(EpayGateway::fromSettings(), 'disabled by default');

        update_option('aiya_core_sponsorship_payments', ['epay_enable' => true, 'epay_pid' => self::PID]);
        self::assertNull(EpayGateway::fromSettings(), 'enabled but unconfigured');
    }
}
