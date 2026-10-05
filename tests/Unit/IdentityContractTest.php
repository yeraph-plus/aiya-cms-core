<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\AuthSession;
use Aiya\Core\Api\Contract\AvatarImage;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Membership;
use Aiya\Core\Api\Contract\Profile;
use Aiya\Core\Api\Contract\ProfileStats;
use Aiya\Core\Api\Contract\UserProfile;
use PHPUnit\Framework\TestCase;

/**
 * The identity-facing contract DTOs, consolidated from
 * AuthSessionContractTest / ProfileContractTest / UserProfileContractTest
 * (2026-10-05): the public profile projection, the full owner view, the
 * avatar pair and the session envelope.
 */
final class IdentityContractTest extends TestCase
{
    private function avatar(): AvatarImage
    {
        return new AvatarImage(
            'https://wp.example.com/wp-content/avatars/12/128.jpg?v=1757000000',
            'https://wp.example.com/wp-content/avatars/12/64.jpg?v=1757000000'
        );
    }

    private function profile(): UserProfile
    {
        return new UserProfile(
            12,
            '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
            'zhan-zhang',
            '站长',
            'owner@example.com',
            'https://example.com',
            'About me',
            'zh_CN',
            '2026-09-06T08:00:00+00:00',
            'subscriber',
            false,
            false,
            $this->avatar(),
            new ProfileStats(5, 9, 2),
        );
    }

    public function testContractVersionAndNamespaceArePinned(): void
    {
        self::assertSame('1', Contract::VERSION);
        self::assertSame('aiya/core/v1', Contract::API_NAMESPACE);
    }

    public function testAvatarImageSerializesToCamelCaseShape(): void
    {
        self::assertSame([
            'url' => 'https://wp.example.com/wp-content/avatars/12/128.jpg?v=1757000000',
            'thumbUrl' => 'https://wp.example.com/wp-content/avatars/12/64.jpg?v=1757000000',
        ], $this->avatar()->toArray());
    }

    public function testUserProfileSerializesTheFullOwnerView(): void
    {
        self::assertSame([
            'id' => 12,
            'username' => '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
            'slug' => 'zhan-zhang',
            'nickname' => '站长',
            'email' => 'owner@example.com',
            'url' => 'https://example.com',
            'description' => 'About me',
            'locale' => 'zh_CN',
            'registeredAt' => '2026-09-06T08:00:00+00:00',
            'role' => 'subscriber',
            'banned' => false,
            'showNsfw' => false,
            'avatar' => [
                'url' => 'https://wp.example.com/wp-content/avatars/12/128.jpg?v=1757000000',
                'thumbUrl' => 'https://wp.example.com/wp-content/avatars/12/64.jpg?v=1757000000',
            ],
            'stats' => [
                'favorites' => 5,
                'contributions' => 9,
                'followers' => 2,
            ],
        ], $this->profile()->toArray());
    }

    public function testSerializesThePublicProjectionInCamelCase(): void
    {
        $profile = new Profile(
            7,
            '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
            '夜行玩家',
            'subscriber',
            new Image('https://wp.example/avatars/7/128.jpg?v=1', '夜行玩家', null, null),
            'About me',
            '2026-09-06T08:00:00+08:00',
            new ProfileStats(3, 4, 2),
            new Membership('active', '2026-10-05T00:00:00+08:00'),
            []
        );

        self::assertSame([
            'id' => 7,
            'slug' => '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
            'name' => '夜行玩家',
            'role' => 'subscriber',
            'avatar' => ['url' => 'https://wp.example/avatars/7/128.jpg?v=1', 'alt' => '夜行玩家', 'width' => null, 'height' => null],
            'bio' => 'About me',
            'joinedAt' => '2026-09-06T08:00:00+08:00',
            'stats' => ['favorites' => 3, 'contributions' => 4, 'followers' => 2],
            'membership' => ['status' => 'active', 'renewsAt' => '2026-10-05T00:00:00+08:00'],
            'favorites' => [],
        ], $profile->toArray());
    }

    public function testMembershipCarriesStateAndExpiryOnly(): void
    {
        // The 2026-09-19 membership design (credits per cycle, no benefit
        // tiers) leaves no display copy on the backend — the front end's
        // i18n owns the badge wording entirely.
        $membership = new Membership('inactive', null);

        self::assertSame(
            ['status' => 'inactive', 'renewsAt' => null],
            $membership->toArray()
        );
    }

    public function testSessionSerializesTokenAndNestedUser(): void
    {
        $now = time();
        $session = new AuthSession(
            '12.abcdef0123456789',
            $now + 600,
            new UserProfile(
                12,
                '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
                'zhan-zhang',
                '站长',
                'owner@example.com',
                '',
                '',
                'en_US',
                '2026-09-06T08:00:00+00:00',
                'subscriber',
                false,
                false,
                new AvatarImage('https://wp.example.com/a.jpg', 'https://wp.example.com/a.jpg')
            )
        );

        $shape = $session->toArray();

        self::assertSame('12.abcdef0123456789', $shape['token']);
        self::assertSame('Bearer', $shape['tokenType']);
        self::assertSame($now + 600, $shape['expiresAt']);
        // expiresIn derives from time() inside toArray(); allow one tick.
        self::assertGreaterThanOrEqual(599, $shape['expiresIn']);
        self::assertLessThanOrEqual(600, $shape['expiresIn']);
        self::assertSame('站长', $shape['user']['nickname']);
        self::assertSame('owner@example.com', $shape['user']['email']);
    }

    public function testExpiredSessionNeverReportsNegativeTtl(): void
    {
        $now = time();
        $session = new AuthSession('12.x', $now - 100, $this->sessionProfile($now));

        self::assertSame(0, $session->toArray()['expiresIn']);
    }

    private function sessionProfile(int $now): UserProfile
    {
        return new UserProfile(
            12,
            'u',
            'u',
            'n',
            'e@example.com',
            '',
            '',
            'en_US',
            gmdate('c', $now),
            'subscriber',
            false,
            false,
            new AvatarImage('', '')
        );
    }
}
