<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_set_current_user')) {
        function wp_set_current_user(int $userId): WP_User
        {
            $GLOBALS['__aiya_test_current_user_id'] = $userId;
            do_action('set_current_user');

            return get_userdata($userId) ?: new WP_User();
        }
    }
}

namespace Aiya\Core\Tests\Unit {
    use Aiya\Core\Api\Rest\TokenAuthentication;
    use Aiya\Core\Domain\Identity\TokenStore;
    use PHPUnit\Framework\TestCase;
    use WP_Error;

    final class TokenAuthenticationTest extends TestCase
    {
        private array $savedFilters;
        private array $savedServer;
        private mixed $savedWp;
        private TokenAuthentication $auth;

        protected function setUp(): void
        {
            $this->savedFilters = $GLOBALS['__aiya_test_filters'] ?? [];
            $this->savedServer = $_SERVER;
            $this->savedWp = $GLOBALS['wp'] ?? null;
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['wpdb'] = new \wpdb();
            $GLOBALS['__aiya_test_users'] = [72 => true];
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            wp_cache_flush();
            $tokens = new TokenStore();
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens->issue(72)->token;
            $this->auth = new TokenAuthentication($tokens);
            $this->auth->register();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->savedServer;
            $GLOBALS['wp'] = $this->savedWp;
            $GLOBALS['__aiya_test_filters'] = $this->savedFilters;
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_user_meta'] = [];
            unset($GLOBALS['wpdb']);
            wp_cache_flush();
        }

        public function testNativeOverrideCannotBorrowTheVisibleContractPath(): void
        {
            $_SERVER['REQUEST_URI'] = '/wp-json/aiya/core/v1/users/me?rest_route=/wp/v2/users';
            $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/wp/v2/users']];

            self::assertNull(apply_filters('rest_authentication_errors', null));
            self::assertSame(0, get_current_user_id());
            self::assertSame(0, apply_filters('determine_current_user', 0));
        }

        public function testOnlyResolvedNamespaceBoundariesAuthenticate(): void
        {
            foreach (['/wp/v2/users', '/else/aiya/core/v1/users/me', '/aiya/core/v10/users', null] as $route) {
                $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $route]];
                apply_filters('rest_authentication_errors', null);
                self::assertSame(0, get_current_user_id(), (string) $route);
            }
            // Pretty URL, plain permalink or POST route override all dispatch
            // this resolved query variable; raw request hints are irrelevant.
            foreach (['/aiya/core/v1/users/me', '/aiya/core/v1/', '/aiya/integrations/v1/tickets', '/aiya/membership/v1/callback'] as $route) {
                $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $route]];
                $GLOBALS['__aiya_test_current_user_id'] = 0;
                apply_filters('rest_authentication_errors', null);
                self::assertSame(72, get_current_user_id(), $route);
            }
        }

        public function testEarlierErrorsAndExistingIdentitiesWin(): void
        {
            $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/aiya/core/v1/users/me']];
            $error = new WP_Error('blocked', 'blocked');
            self::assertSame($error, $this->auth->authenticate($error));
            self::assertSame(0, get_current_user_id());
            $GLOBALS['__aiya_test_current_user_id'] = 9;
            self::assertNull($this->auth->authenticate(null));
            self::assertSame(9, get_current_user_id());
        }
    }
}
