<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianClient;
use PHPUnit\Framework\TestCase;

final class AfdianClientTest extends TestCase
{
    public function testWebhookSignatureRoundTrips(): void
    {
        $client = new AfdianClient('user-1', 'secret-token');
        $data = ['order' => ['out_trade_no' => '20260909123', 'month' => 3, 'status' => 'trade_success']];
        $body = [
            'ec' => 200,
            'data' => $data,
            'ts' => 1757400000,
            'sign' => md5('secret-tokenparams' . json_encode($data) . 'ts1757400000'),
        ];

        self::assertTrue($client->verifyWebhook($body));
    }

    public function testTamperedWebhookFailsVerification(): void
    {
        $client = new AfdianClient('user-1', 'secret-token');
        $data = ['order' => ['out_trade_no' => 'x', 'month' => 1]];
        $body = [
            'data' => $data,
            'ts' => 1757400000,
            'sign' => md5('secret-tokenparams' . json_encode($data) . 'ts1757400000'),
        ];
        $body['month'] = 99; // any extra top-level field must not matter…
        self::assertTrue($client->verifyWebhook($body));

        $body['data']['order']['month'] = 99; // …but payload changes do
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

    public function testQueryOrderParsesFirstListEntry(): void
    {
        $captured = null;
        $client = new AfdianClient('user-1', 't', function (string $url, string $payload) use (&$captured): ?string {
            $captured = ['url' => $url, 'sign' => (string) (json_decode($payload, true)['sign'] ?? '')];

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

    public function testQueryOrderWithoutTransportAnswersNull(): void
    {
        self::assertNull((new AfdianClient('u', 't'))->queryOrder('1'));
        self::assertFalse((new AfdianClient('u', 't'))->ping());
    }
}
