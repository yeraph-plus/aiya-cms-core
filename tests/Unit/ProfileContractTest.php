<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Membership;
use Aiya\Core\Api\Contract\Profile;
use Aiya\Core\Api\Contract\ProfileStats;
use PHPUnit\Framework\TestCase;

final class ProfileContractTest extends TestCase
{
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
            new ProfileStats(0, 3, 4, 2),
            new Membership('', 'active', '2026-10-05T00:00:00+08:00', []),
            [],
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
            'stats' => ['activities' => 0, 'favorites' => 3, 'contributions' => 4, 'followers' => 2],
            'membership' => ['label' => '', 'status' => 'active', 'renewsAt' => '2026-10-05T00:00:00+08:00', 'benefits' => []],
            'activities' => [],
            'favorites' => [],
        ], $profile->toArray());
    }

    public function testMembershipCarriesNoDisplayCopy(): void
    {
        // D4/D8: label/benefits stay empty — the front end owns the wording.
        $membership = new Membership('', 'inactive', null, []);

        self::assertSame(
            ['label' => '', 'status' => 'inactive', 'renewsAt' => null, 'benefits' => []],
            $membership->toArray()
        );
    }
}
