<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianClient;
use PHPUnit\Framework\TestCase;

final class AfdianClientTest extends TestCase
{
    /** RSA test pair standing in for the platform keypair (the private half never ships).
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

    private function client(): AfdianClient
    {
        return new AfdianClient('user-1', 'secret-token', null, self::keyPair()['public']);
    }

    public function testWebhookSignatureRoundTrips(): void
    {
        // Official doc: sign_str = out_trade_no . user_id . plan_id . total_amount.
        $order = ['out_trade_no' => '202106232138371083454010626', 'user_id' => 'adf397fe8374811eaacee52540025c377', 'plan_id' => 'a45353328af911eb973052540025c377', 'total_amount' => '5.00', 'month' => 1, 'status' => 2];
        openssl_sign($order['out_trade_no'] . $order['user_id'] . $order['plan_id'] . $order['total_amount'], $signature, self::keyPair()['private'], 'sha256');
        $body = [
            'ec' => 200,
            'em' => 'ok',
            'data' => ['type' => 'order', 'order' => $order, 'sign' => base64_encode($signature)], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        ];

        self::assertTrue($this->client()->verifyWebhook($body));
    }

    public function testTamperedWebhookFailsVerification(): void
    {
        $client = $this->client();
        $order = ['out_trade_no' => 'x', 'user_id' => 'buyer-hash', 'plan_id' => 'plan-1', 'total_amount' => '5.00'];
        openssl_sign($order['out_trade_no'] . $order['user_id'] . $order['plan_id'] . $order['total_amount'], $signature, self::keyPair()['private'], 'sha256');
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding the test's RSA signature exactly as the platform does
        $body = ['ec' => 200, 'data' => ['type' => 'order', 'order' => $order, 'sign' => base64_encode($signature)]];

        self::assertTrue($client->verifyWebhook($body));

        $body['data']['order']['total_amount'] = '0.01'; // any amount tampering breaks it
        self::assertFalse($client->verifyWebhook($body));

        $body['data']['sign'] = 'not-base64!!';
        self::assertFalse($client->verifyWebhook($body));

        unset($body['data']['sign']); // unsigned pushes never verify
        self::assertFalse($client->verifyWebhook($body));

        $body['data'] = 'not-an-array';
        self::assertFalse($client->verifyWebhook($body));
    }

    public function testUserBindingRoundTripsThroughTheFrozenAlphabet(): void
    {
        $client = new AfdianClient('user-1', 't');

        self::assertSame(123456, $client->resolveUser($client->bindUser(123456)));
        self::assertSame(0, $client->resolveUser('!!!not-a-code'));
    }

    public function testPingSurfacesThePlatformErrorCode(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client test transport, matching the client's raw-JSON bytes
        $client = new AfdianClient('user-1', 't', fn (): string => (string) json_encode(['ec' => 200, 'em' => 'pong']));
        self::assertSame(200, $client->ping());

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $expired = new AfdianClient('user-1', 't', fn (): string => (string) json_encode(['ec' => 400002, 'em' => 'time was expired']));
        self::assertSame(400002, $expired->ping());

        $dead = new AfdianClient('user-1', 't', fn (): ?string => null);
        self::assertSame(0, $dead->ping());
    }

    public function testQueryOrdersParsesThePagedEnvelopeAndClampsPerPage(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $client = new AfdianClient('user-1', 't', fn (): string => (string) json_encode([
            'ec' => 200,
            'data' => ['list' => [['out_trade_no' => 'a'], ['out_trade_no' => 'b'], 'garbage'], 'total_count' => 167, 'total_page' => 11],
        ]));

        $page = $client->queryOrders(2, 500, '222225555,2222222666');

        self::assertNotNull($page);
        self::assertSame(['a', 'b'], array_column($page['list'], 'out_trade_no'));
        self::assertSame(167, $page['total_count']);
        self::assertSame(11, $page['total_page']);
    }

    public function testQueryOrdersClampsPerPageAndPassesTradeNumbers(): void
    {
        $params = null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $client = new AfdianClient('user-1', 't', function (string $url, string $payload) use (&$params): string {
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
            return (string) json_encode(['ec' => 200, 'data' => ['list' => [], 'total_count' => 0, 'total_page' => 0]]);
        });

        $client->queryOrders(2, 500, '222225555,2222222666');
        self::assertSame(['page' => 2, 'per_page' => 100, 'out_trade_no' => '222225555,2222222666'], $params);

        $client->queryOrders(1, 0, '');
        self::assertSame(['page' => 1, 'per_page' => 50], $params);
    }

    public function testQuerySponsorsPassesUserIdsThrough(): void
    {
        $params = null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $client = new AfdianClient('user-1', 't', function (string $url, string $payload) use (&$params): string {
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
            return (string) json_encode(['ec' => 200, 'data' => ['list' => [['user' => ['user_id' => 'sfff']]], 'total_count' => 1, 'total_page' => 1]]);
        });

        $page = $client->querySponsors(1, 20, 'sfff,other');

        self::assertNotNull($page);
        self::assertSame('sfff', $page['list'][0]['user']['user_id']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'user_id' => 'sfff,other'], $params);
    }

    public function testQueryPlanReturnsThePlanNode(): void
    {
        $params = null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $client = new AfdianClient('user-1', 't', function (string $url, string $payload) use (&$params): string {
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
            return (string) json_encode(['ec' => 200, 'data' => ['plan' => ['plan_id' => 'p1', 'product_type' => 0, 'pay_month' => 1]]]);
        });

        $plan = $client->queryPlan('p1');

        self::assertNotNull($plan);
        self::assertSame('p1', $plan['plan_id']);
        self::assertSame(['plan_id' => 'p1'], $params);
    }

    public function testSendMsgAnswersTrueOnlyOnEc200(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $ok = new AfdianClient('user-1', 't', fn (): string => (string) json_encode(['ec' => 200]));
        self::assertTrue($ok->sendMsg('recipient', 'hello'));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $refused = new AfdianClient('user-1', 't', fn (): string => (string) json_encode(['ec' => 400001]));
        self::assertFalse($refused->sendMsg('recipient', 'hello'));
    }

    public function testQueryOrderParsesFirstListEntry(): void
    {
        $captured = null;
        $client = new AfdianClient('user-1', 't', function (string $url, string $payload) use (&$captured): string {
            $captured = ['url' => $url, 'sign' => (string) (json_decode($payload, true)['sign'] ?? '')];

            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client test transport, matching the client's raw-JSON bytes
            return (string) json_encode([
                'ec' => 200,
                'data' => ['list' => [['out_trade_no' => '20260909123', 'month' => 3]]],
            ]);
        });

        $order = $client->queryOrder('20260909123');

        self::assertSame('20260909123', $order['out_trade_no'] ?? null);
        self::assertStringEndsWith('/api/open/query-order', $captured['url'] ?? '');
        self::assertSame(32, strlen($captured['sign'] ?? ''));
    }
}
