<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Rest\RestGuard;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class RestGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    public function testGuestErrorCarriesTheCanonical401(): void
    {
        $error = RestGuard::guestError();

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('aiya_not_logged_in', $error->get_error_code());
        self::assertSame('Authentication required.', $error->get_error_message());
        self::assertSame(['status' => 401], $error->get_error_data());
    }

    public function testGuestErrorSparesAuthenticatedSessions(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7;

        self::assertNull(RestGuard::guestError());
    }

    public function testLoggedInAnswersBareTrueWhenAuthenticated(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7;

        self::assertSame(true, RestGuard::loggedIn());
    }

    public function testLoggedInAnswersTheCanonical401ForGuests(): void
    {
        $out = RestGuard::loggedIn();

        self::assertInstanceOf(WP_Error::class, $out);
        self::assertSame('aiya_not_logged_in', $out->get_error_code());
        self::assertSame(401, $out->get_error_data()['status']);
    }

    public function testRateLimitedCarriesTheCanonical429(): void
    {
        $error = RestGuard::rateLimited();

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('aiya_rate_limited', $error->get_error_code());
        self::assertSame('Too many requests, try again later.', $error->get_error_message());
        self::assertSame(['status' => 429], $error->get_error_data());
    }
}
