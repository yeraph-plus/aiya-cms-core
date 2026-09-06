<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\AuthSession;
use Aiya\Core\Api\Contract\AvatarImage;
use Aiya\Core\Api\Contract\UserProfile;
use PHPUnit\Framework\TestCase;

final class AuthSessionContractTest extends TestCase
{
    public function testSessionSerializesTokenAndNestedUser(): void
    {
        $now = time();
        $session = new AuthSession(
            '12.abcdef0123456789',
            $now + 600,
            new UserProfile(
                12,
                '9f0e5a1c-6f7a-4d3e-8b2c-1a2b3c4d5e6f',
                '站长',
                'owner@example.com',
                '',
                '',
                'en_US',
                '2026-09-06T08:00:00+00:00',
                'subscriber',
                new AvatarImage('https://wp.example.com/a.jpg', 'https://wp.example.com/a.jpg')
            )
        );

        $shape = $session->toArray();

        self::assertSame('12.abcdef0123456789', $shape['token']);
        self::assertSame('Bearer', $shape['tokenType']);
        self::assertSame($now + 600, $shape['expiresAt']);
        self::assertSame(600, $shape['expiresIn']);
        self::assertSame('站长', $shape['user']['nickname']);
        self::assertSame('owner@example.com', $shape['user']['email']);
    }

    public function testExpiredSessionNeverReportsNegativeTtl(): void
    {
        $now = time();
        $session = new AuthSession('12.x', $now - 100, $this->profile($now));

        self::assertSame(0, $session->toArray()['expiresIn']);
    }

    private function profile(int $now): UserProfile
    {
        return new UserProfile(
            12,
            'u',
            'n',
            'e@example.com',
            '',
            '',
            'en_US',
            gmdate('c', $now),
            'subscriber',
            new AvatarImage('', '')
        );
    }
}
