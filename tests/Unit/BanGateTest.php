<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../Fixture/RestDoubles.php';
}

namespace Aiya\Core\Tests\Unit {
    use Aiya\Core\Api\Rest\BanGate;
    use Aiya\Core\Domain\Identity\UserBan;
    use PHPUnit\Framework\TestCase;
    use WP_Error;

    /**
     * The namespace-wide write gate for a disabled account. It exists
     * because the ban used to be honored only by the money surfaces, so a
     * banned account kept posting threads, replies, likes and comments at
     * full speed while the admin switch read "disable this account".
     */
    final class BanGateTest extends TestCase
    {
        private const DISABLED = 7;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = self::DISABLED;
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = 0;
        }

        public function testAWriteFromADisabledAccountIsRefused(): void
        {
            UserBan::set(self::DISABLED, true);

            foreach (['/aiya/core/v1/discussions', '/aiya/core/v1/comments', '/aiya/core/v1/credits/redeem'] as $route) {
                $refused = BanGate::guard(null, null, new FakeRestRequest($route, 'POST'));

                self::assertInstanceOf(WP_Error::class, $refused, $route);
                self::assertSame('aiya_account_banned', $refused->get_error_code());
                self::assertSame(403, $refused->get_error_data()['status']);
            }
        }

        public function testReadsAndSignOutStayReachable(): void
        {
            UserBan::set(self::DISABLED, true);

            self::assertNull(BanGate::guard(null, null, new FakeRestRequest('/aiya/core/v1/discussions', 'GET')));
            self::assertNull(
                BanGate::guard(null, null, new FakeRestRequest('/aiya/core/v1/auth/logout', 'POST')),
                'a disabled holder must still be able to clear the session',
            );
        }

        public function testOtherNamespacesAndHealthyAccountsPass(): void
        {
            UserBan::set(self::DISABLED, true);
            self::assertNull(BanGate::guard(null, null, new FakeRestRequest('/wp/v2/posts', 'POST')));

            UserBan::set(self::DISABLED, false);
            self::assertNull(BanGate::guard(null, null, new FakeRestRequest('/aiya/core/v1/discussions', 'POST')));
        }

        public function testAnAlreadyShortCircuitedRequestIsLeftAlone(): void
        {
            UserBan::set(self::DISABLED, true);
            $earlier = new WP_Error('aiya_earlier_refusal', 'no', ['status' => 400]);

            self::assertSame($earlier, BanGate::guard($earlier, null, new FakeRestRequest('/aiya/core/v1/discussions', 'POST')));
        }
    }
}
