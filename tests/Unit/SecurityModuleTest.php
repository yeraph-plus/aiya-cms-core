<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Infrastructure\Security\SecurityModule;
use PHPUnit\Framework\TestCase;

/**
 * The request-URI probe guard's decision table. REST requests are exempt
 * because the guard was born as a front-end path filter while a gateway
 * push is an anonymous GET with a long signed query — the Epay callback
 * (~300 bytes) is the shape that proved it: the ceiling rejected the
 * payment, not a probe. Nothing else about the rule changed.
 */
final class SecurityModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
    }

    public function testShortCleanUrisPass(): void
    {
        self::assertFalse(SecurityModule::isBlockedUri('/'));
        self::assertFalse(SecurityModule::isBlockedUri('/community/42?page=2'));
        self::assertFalse(SecurityModule::isBlockedUri('/wp-login.php'));
    }

    public function testGateTokenIsStableInsideAWindowAndRotatesAcross(): void
    {
        $token = SecurityModule::gateToken(5000);

        self::assertSame($token, SecurityModule::gateToken(5000));
        self::assertSame(20, strlen($token));
        self::assertNotSame($token, SecurityModule::gateToken(5001));
    }

    public function testGateCookieDerivationTracksItsWindowToken(): void
    {
        // The cookie is a salted hash of the accepted window token, so a
        // cookie minted late in one window stays bound to that window.
        $tokenA = SecurityModule::gateToken(9000);
        $tokenB = SecurityModule::gateToken(9001);

        self::assertSame(SecurityModule::gateCookieValue($tokenA), SecurityModule::gateCookieValue($tokenA));
        self::assertNotSame(SecurityModule::gateCookieValue($tokenA), SecurityModule::gateCookieValue($tokenB));
        self::assertStringNotContainsString($tokenA, SecurityModule::gateCookieValue($tokenA));
    }

    public function testOversizedFrontEndUrisAreBlocked(): void
    {
        self::assertTrue(SecurityModule::isBlockedUri('/search/' . str_repeat('a', 260)));
        self::assertTrue(SecurityModule::isBlockedUri('/' . str_repeat('x', 255)), 'the ceiling itself is over');
        self::assertFalse(SecurityModule::isBlockedUri('/' . str_repeat('x', 254)));
    }

    public function testProbeShapesAreBlockedOnFrontEndPaths(): void
    {
        self::assertTrue(SecurityModule::isBlockedUri('/index.php?x=eval(1)'));
        self::assertTrue(SecurityModule::isBlockedUri('/?q=base64_decode'));
        self::assertTrue(SecurityModule::isBlockedUri('/a/**/b'));
    }

    /** The shape an Epay push of a real order has: 44-byte path plus the
     * signed query the platform echoes back. */
    public function testTheEpayCallbackUriIsExempt(): void
    {
        $query = http_build_query([
            'out_trade_no' => '20260920000841789943966AF37EF',
            'name' => '体验卡*2',
            'money' => '12.00',
            'param' => 'lzyxiXWj|gold|2',
            'type' => 'alipay',
            'pid' => '2967',
            'notify_url' => 'https://aiya.test/wp-json/aiya/sponsorship/v1/epay/callback',
            'trade_no' => '4200002000202609201234567890',
            'trade_status' => 'TRADE_SUCCESS',
            'sign' => str_repeat('a', 32),
            'sign_type' => 'MD5',
        ]);
        $uri = '/wp-json/aiya/sponsorship/v1/epay/callback?' . $query;

        self::assertGreaterThan(255, strlen($uri), 'the regression: a real push is over the ceiling');
        self::assertFalse(SecurityModule::isBlockedUri($uri));
    }

    public function testRestQueriesAreExemptFromTheShapeRules(): void
    {
        self::assertFalse(SecurityModule::isBlockedUri('/wp-json/aiya/core/v1/content?search=' . str_repeat('b', 300)));
        self::assertFalse(SecurityModule::isBlockedUri('/wp-json/aiya/core/v1/content?filter=base64'));
        self::assertFalse(SecurityModule::isBlockedUri('/wp-json/aiya/core/v1/content?q=/**/'));
    }

    /** Plain-permalink REST (?rest_route=…) carries no /wp-json in the path. */
    public function testThePlainPermalinkRestFormIsExempt(): void
    {
        $_GET['rest_route'] = '/aiya/core/v1/content';
        self::assertFalse(SecurityModule::isBlockedUri('/?rest_route=%2Faiya%2Fcore%2Fv1%2Fcontent&search=' . str_repeat('c', 280)));

        $_GET = ['rest_route' => ''];
        self::assertTrue(SecurityModule::isBlockedUri('/?rest_route=&search=' . str_repeat('c', 280)), 'an empty rest_route is not a REST request');
    }

    /**
     * The unified back-end gate: one setting, one capability — wp-admin
     * (guardBackend) and the native /wp/v2 lock (HeadlessModule) read the
     * same decision. Unknown stored values read as off, never as a role.
     */
    public function testBackendGateCapabilityFollowsTheSecuritySetting(): void
    {
        $expected = [
            'off' => null,
            'subscriber' => 'read',
            'contributor' => 'edit_posts',
            'author' => 'publish_posts',
            'editor' => 'publish_pages',
            'administrator' => 'manage_options',
            'bogus' => null,
        ];

        $stored = &$GLOBALS['__aiya_test_options']['security']['admin_backend_min_role'];
        try {
            foreach ($expected as $value => $capability) {
                $stored = $value;
                self::assertSame($capability, SecurityModule::backendGateCapability(), "gate value '{$value}'");
            }
            unset($GLOBALS['__aiya_test_options']['security']['admin_backend_min_role']);
            self::assertNull(SecurityModule::backendGateCapability(), 'an unset gate reads as off');
        } finally {
            unset($GLOBALS['__aiya_test_options']['security']);
        }
    }
}
