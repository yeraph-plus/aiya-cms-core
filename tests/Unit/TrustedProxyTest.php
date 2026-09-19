<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Infrastructure\Http\TrustedProxy;
use PHPUnit\Framework\TestCase;

final class TrustedProxyTest extends TestCase
{
    private const SECRET = 'dev-bridge-secret';

    protected function tearDown(): void
    {
        unset($_SERVER[TrustedProxy::SECRET_HEADER], $_SERVER[TrustedProxy::FORWARDED_HEADER]);
    }

    private function proxy(?string $secret = self::SECRET): TrustedProxy
    {
        return new TrustedProxy($secret);
    }

    public function testUnconfiguredBridgeKeepsRemoteAddr(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7';

        self::assertSame('192.0.2.1', $this->proxy(null)->resolve('192.0.2.1'));
        self::assertFalse($this->proxy(null)->enabled());
    }

    public function testEmptySecretConstantStaysInert(): void
    {
        self::assertFalse($this->proxy('')->enabled());
        self::assertSame('192.0.2.1', $this->proxy('')->resolve('192.0.2.1'));
    }

    public function testMissingSecretHeaderKeepsRemoteAddr(): void
    {
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7';

        self::assertSame('192.0.2.1', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testWrongSecretKeepsRemoteAddr(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = 'forged';
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7';

        self::assertSame('192.0.2.1', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testAuthenticatedProxyYieldsForwardedIp(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7';

        self::assertSame('203.0.113.7', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testForwardedChainTakesFirstEntry(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7, 10.0.0.9';

        self::assertSame('203.0.113.7', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testNonIpForwardedValueFallsBackToRemoteAddr(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = 'not-an-ip';

        self::assertSame('192.0.2.1', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testEmptyForwardedHeaderFallsBackToRemoteAddr(): void
    {
        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;

        self::assertSame('192.0.2.1', $this->proxy()->resolve('192.0.2.1'));
    }

    public function testRegisterWiresTheClientIpFilter(): void
    {
        $proxy = $this->proxy();
        $proxy->register();

        $_SERVER[TrustedProxy::SECRET_HEADER] = self::SECRET;
        $_SERVER[TrustedProxy::FORWARDED_HEADER] = '203.0.113.7';

        self::assertSame('203.0.113.7', apply_filters('aiya_core_client_ip', '192.0.2.1'));
    }
}
