<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Infrastructure\Http\VisitorFingerprint;
use PHPUnit\Framework\TestCase;

final class VisitorFingerprintTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    public function testLoggedInVisitorsFingerprintAsTheirUserId(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 42;

        self::assertSame('u42', VisitorFingerprint::hash());
    }

    public function testUserPrefixWinsEvenWhenGuestSignalsArePresent(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/1.0';

        self::assertSame('u7', VisitorFingerprint::hash());
    }

    public function testGuestsFingerprintAsAnIpAgentDigest(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/1.0';

        self::assertMatchesRegularExpression('/^g[0-9a-f]{32}$/', VisitorFingerprint::hash());
    }

    public function testSameIpAndAgentReproduceTheSameDigest(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/1.0';
        $first = VisitorFingerprint::hash();

        self::assertSame($first, VisitorFingerprint::hash());
    }

    public function testDifferentIpsNeverShareADigest(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/1.0';
        $first = VisitorFingerprint::hash();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';

        self::assertNotSame($first, VisitorFingerprint::hash());
    }

    public function testDifferentAgentsNeverShareADigest(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/1.0';
        $first = VisitorFingerprint::hash();
        $_SERVER['HTTP_USER_AGENT'] = 'AIYA Probe/2.0';

        self::assertNotSame($first, VisitorFingerprint::hash());
    }

    public function testMissingUserAgentCountsAsAnEmptyOne(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        unset($_SERVER['HTTP_USER_AGENT']);
        $withoutHeader = VisitorFingerprint::hash();
        $_SERVER['HTTP_USER_AGENT'] = '';

        self::assertSame($withoutHeader, VisitorFingerprint::hash());
    }

    public function testNonStringUserAgentCountsAsAnEmptyOne(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = ['spoofed'];
        $nonString = VisitorFingerprint::hash();
        $_SERVER['HTTP_USER_AGENT'] = '';

        self::assertSame($nonString, VisitorFingerprint::hash());
    }

    public function testUserAgentMarkupIsSanitizedBeforeDigesting(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Bot <script>alert(1)</script>';
        $withMarkup = VisitorFingerprint::hash();
        $_SERVER['HTTP_USER_AGENT'] = 'Bot alert(1)';

        self::assertSame($withMarkup, VisitorFingerprint::hash());
    }
}
