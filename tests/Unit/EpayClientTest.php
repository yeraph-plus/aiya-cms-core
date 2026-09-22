<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\PaymentEpay\Client;
use PHPUnit\Framework\TestCase;

final class EpayClientTest extends TestCase
{
    private const KEY = 'merchant-key-2026';

    public function testSubmitQueryIsSignedAndVerifiable(): void
    {
        $client = new Client('1001', self::KEY, 'https://pay.example.com/');

        $query = $client->buildSubmitQuery([
            'out_trade_no' => '202609090000117570000000',
            'name' => '月度会员',
            'money' => '10.00',
            'param' => 'AB12CD34|month',
            'type' => 'alipay',
        ], 'https://wp.example.com/wp-json/aiya/sponsorship/v1/epay/callback', 'https://front.example.com/pay/done');

        parse_str($query, $params);

        self::assertSame('1001', $params['pid']);
        self::assertSame('MD5', $params['sign_type']);
        self::assertSame(32, strlen((string) ($params['sign'] ?? '')));
        self::assertTrue($client->verifyCallback($params));
    }

    public function testVerifyCallbackRejectsTampering(): void
    {
        $client = new Client('1001', self::KEY, 'https://pay.example.com/');
        $query = $client->buildSubmitQuery(['out_trade_no' => 'T1', 'name' => 'n', 'money' => '5.00', 'param' => 'p', 'type' => 'wxpay']);
        parse_str($query, $params);

        $params['money'] = '0.01';
        self::assertFalse($client->verifyCallback($params));

        unset($params['sign']);
        self::assertFalse($client->verifyCallback($params));
        self::assertFalse($client->verifyCallback([]));
    }

    public function testSignSkipsEmptyAndLiteralZeroValuesLikeTheLegacySdk(): void
    {
        // Recompute the legacy algorithm by hand for one parameter set and
        // compare — ksort, skip sign/sign_type/''/'0', append the key. The
        // pid is part of the submit params and thus signed too.
        $params = ['pid' => '1001', 'money' => '5.00', 'name' => 'plan', 'out_trade_no' => 'T9', 'param' => '', 'cid' => '0', 'type' => 'alipay'];
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === '0') {
                continue;
            }
            $pairs[] = "{$k}={$v}";
        }
        $expected = md5(implode('&', $pairs) . self::KEY);

        $client = new Client('1001', self::KEY, 'https://pay.example.com/');
        $signed = $client->buildSubmitQuery($params);
        parse_str($signed, $out);

        self::assertSame($expected, $out['sign']);
    }

    public function testSubmitUrlNormalizesTrailingSlash(): void
    {
        $withSlash = new Client('1', 'k', 'https://pay.example.com/');
        $withoutSlash = new Client('1', 'k', 'https://pay.example.com');

        self::assertSame('https://pay.example.com/submit.php?a=1', $withSlash->submitUrl('a=1'));
        self::assertSame('https://pay.example.com/submit.php?a=1', $withoutSlash->submitUrl('a=1'));
    }
}
