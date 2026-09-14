<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\AvatarImage;
use Aiya\Core\Api\Contract\ProfileStats;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\UserProfile;
use PHPUnit\Framework\TestCase;

final class UserProfileContractTest extends TestCase
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
            $this->avatar(),
            new ProfileStats(0, 5, 9, 2),
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
            'avatar' => [
                'url' => 'https://wp.example.com/wp-content/avatars/12/128.jpg?v=1757000000',
                'thumbUrl' => 'https://wp.example.com/wp-content/avatars/12/64.jpg?v=1757000000',
            ],
            'stats' => [
                'activities' => 0,
                'favorites' => 5,
                'contributions' => 9,
                'followers' => 2,
            ],
        ], $this->profile()->toArray());
    }
}
