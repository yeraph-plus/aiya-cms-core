<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\PaymentAfdian\Client;
use PHPUnit\Framework\TestCase;

final class AfdianClientTest extends TestCase
{
    private function client(): Client
    {
        return new Client('user-1', 'secret-token', null);
    }

    public function testUserBindingRoundTripsThroughTheFrozenAlphabet(): void
    {
        $client = new Client('user-1', 't');

        self::assertSame(123456, $client->resolveUser($client->bindUser(123456)));
        self::assertSame(0, $client->resolveUser('!!!not-a-code'));
    }

    public function testPingSurfacesThePlatformErrorCode(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client test transport, matching the client's raw-JSON bytes
        $client = new Client('user-1', 't', fn (): string => (string) json_encode(['ec' => 200, 'em' => 'pong']));
        self::assertSame(200, $client->ping());

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $expired = new Client('user-1', 't', fn (): string => (string) json_encode(['ec' => 400002, 'em' => 'time was expired']));
        self::assertSame(400002, $expired->ping());

        $dead = new Client('user-1', 't', fn (): ?string => null);
        self::assertSame(0, $dead->ping());
    }

    public function testQueryOrdersParsesThePagedEnvelopeAndClampsPerPage(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $client = new Client('user-1', 't', fn (): string => (string) json_encode([
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
        $client = new Client('user-1', 't', function (string $url, string $payload) use (&$params): string {
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
        $client = new Client('user-1', 't', function (string $url, string $payload) use (&$params): string {
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
        $client = new Client('user-1', 't', function (string $url, string $payload) use (&$params): string {
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
        $ok = new Client('user-1', 't', fn (): string => (string) json_encode(['ec' => 200]));
        self::assertTrue($ok->sendMsg('recipient', 'hello'));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ditto
        $refused = new Client('user-1', 't', fn (): string => (string) json_encode(['ec' => 400001]));
        self::assertFalse($refused->sendMsg('recipient', 'hello'));
    }

    public function testQueryOrderParsesFirstListEntry(): void
    {
        $captured = null;
        $client = new Client('user-1', 't', function (string $url, string $payload) use (&$captured): string {
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
