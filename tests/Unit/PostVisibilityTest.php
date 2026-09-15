<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\PostVisibility;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The post-level visibility gate: level reading off the scalar meta
 * (whitelisted), the satisfaction matrix per viewer class, and the list
 * meta_query exclusion each viewer class receives. The member check is
 * the injected closure; get_current_user_id() rides the bootstrap shim.
 */
final class PostVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    private function service(bool $viewerIsMember = false): PostVisibility
    {
        return new PostVisibility(static fn (int $userId): bool => $viewerIsMember);
    }

    private function post(int $id): WP_Post
    {
        $post = new WP_Post((object) ['ID' => $id]);
        $post->ID = $id;

        return $post;
    }

    public function testLevelWhitelistsTheMetaValue(): void
    {
        update_post_meta(1, PostVisibility::META_KEY, 'login');
        update_post_meta(2, PostVisibility::META_KEY, 'member');
        update_post_meta(3, PostVisibility::META_KEY, 'bogus');

        self::assertSame('login', $this->service()->level($this->post(1)));
        self::assertSame('member', $this->service()->level($this->post(2)));
        self::assertSame('', $this->service()->level($this->post(3)), 'unknown values fold back to public');
        self::assertSame('', $this->service()->level($this->post(4)), 'missing meta is public');
    }

    public function testPublicLevelAlwaysPasses(): void
    {
        self::assertTrue($this->service()->satisfied(PostVisibility::PUBLIC, 0));
        self::assertTrue($this->service()->satisfied(PostVisibility::PUBLIC, 7));
    }

    public function testLoginGateNeedsAnyUser(): void
    {
        self::assertFalse($this->service()->satisfied(PostVisibility::LOGIN, 0));
        self::assertTrue($this->service()->satisfied(PostVisibility::LOGIN, 7));
    }

    public function testMemberGateDelegatesToTheMemberCheck(): void
    {
        self::assertFalse($this->service(false)->satisfied(PostVisibility::MEMBER, 7));
        self::assertTrue($this->service(true)->satisfied(PostVisibility::MEMBER, 7));
    }

    public function testGatedMirrorsTheCurrentViewer(): void
    {
        update_post_meta(9, PostVisibility::META_KEY, 'login');
        $service = $this->service();

        $GLOBALS['__aiya_test_current_user_id'] = 0;
        self::assertTrue($service->gated($this->post(9)));

        $GLOBALS['__aiya_test_current_user_id'] = 5;
        self::assertFalse($service->gated($this->post(9)));
    }

    public function testListExclusionsPerViewerClass(): void
    {
        $key = PostVisibility::META_KEY;

        $guest = $this->service(false)->listExclusions();
        self::assertSame('OR', $guest['relation']);
        self::assertSame(
            [
                ['key' => $key, 'compare' => 'NOT EXISTS'],
                ['key' => $key, 'value' => '', 'compare' => '='],
                ['key' => $key, 'value' => ['login', 'member'], 'compare' => 'NOT IN'],
            ],
            array_values(array_slice($guest, 1)),
            'guests lose both gates; empty-value rows stay public'
        );

        $GLOBALS['__aiya_test_current_user_id'] = 7;
        $loggedIn = $this->service(false)->listExclusions();
        self::assertSame(['member'], $loggedIn[2]['value'], 'logged-in non-members only lose member rows');

        self::assertSame([], $this->service(true)->listExclusions(), 'members see everything');
    }
}
