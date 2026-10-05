<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\PostTypeSwitcher;
use Aiya\Core\Domain\Shared\PublicTypes;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class PostTypeSwitcherTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        $GLOBALS['__aiya_test_current_user_id'] = 1;
        $GLOBALS['__aiya_test_sticky'] = [];
    }

    protected function tearDown(): void
    {
        // 能力/粘性位是共享全局，姿态改完必须回到默认
        $GLOBALS['__aiya_test_caps'] = true;
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_sticky'] = [];
    }

    private function post(int $id, string $type): WP_Post
    {
        return new WP_Post((object) ['ID' => $id, 'post_type' => $type, 'post_status' => 'publish']);
    }

    public function testBulkSwitchAnswersAllSkippedForAnUnknownTargetType(): void
    {
        self::assertSame(['switched' => 0, 'skipped' => 2], (new PostTypeSwitcher())->switchPosts([4, 9], 'attachment'));
    }

    public function testSwitchPostRefusesARowOutsideThePublicTypes(): void
    {
        self::assertFalse((new PostTypeSwitcher())->switchPost($this->post(4, 'attachment'), PublicTypes::get('resource')));
    }

    public function testSwitchPostRefusesARowAlreadyAtTheTargetType(): void
    {
        self::assertFalse((new PostTypeSwitcher())->switchPost($this->post(4, 'resource'), PublicTypes::get('resource')));
    }

    public function testSwitchPostRefusesAViewerWithoutEditCapability(): void
    {
        $GLOBALS['__aiya_test_caps'] = false;

        self::assertFalse((new PostTypeSwitcher())->switchPost($this->post(4, 'post'), PublicTypes::get('resource')));
    }

    public function testBulkSwitchMovesEveryEligibleRowAndCountsTheRest(): void
    {
        if (!function_exists('wp_update_post') || !function_exists('get_post_type_object')) {
            $this->markTestSkipped('tests/bootstrap.php has no wp_update_post()/get_post_type_object() shims; the switch leg needs them');
        }

        $GLOBALS['__aiya_test_posts'] = [
            4 => $this->post(4, 'post'),
            9 => $this->post(9, 'post'),
        ];

        self::assertSame(['switched' => 2, 'skipped' => 0], (new PostTypeSwitcher())->switchPosts([4, 9], 'resource'));
    }

    public function testBulkSwitchDeniesTheWholeBatchWithoutTypeCapability(): void
    {
        if (!function_exists('get_post_type_object')) {
            $this->markTestSkipped('tests/bootstrap.php has no get_post_type_object() shim; the type-capability gate needs it');
        }

        $GLOBALS['__aiya_test_caps'] = false;

        self::assertSame(['switched' => 0, 'skipped' => 1], (new PostTypeSwitcher())->switchPosts([4], 'post'));
    }

    public function testStickyRowLeavingPostGetsUnstuck(): void
    {
        if (!function_exists('unstick_post') || !function_exists('wp_update_post')) {
            $this->markTestSkipped('tests/bootstrap.php has no unstick_post()/wp_update_post() shims; the sticky release leg needs them');
        }

        $GLOBALS['__aiya_test_posts'] = [4 => $this->post(4, 'post')];
        $GLOBALS['__aiya_test_sticky'] = [4];

        self::assertTrue((new PostTypeSwitcher())->switchPost($this->post(4, 'post'), PublicTypes::get('resource')));
        self::assertNotContains(4, $GLOBALS['__aiya_test_sticky']);
    }
}
