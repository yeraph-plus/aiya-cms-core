<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Notification\RoleLevel;
use PHPUnit\Framework\TestCase;

final class RoleLevelTest extends TestCase
{
    public function testLadderIsOrderedLowestToHighest(): void
    {
        self::assertSame(
            ['guest', 'subscriber', 'sponsor', 'author', 'administrator'],
            RoleLevel::all()
        );
        self::assertSame(0, RoleLevel::rank('guest'));
        self::assertSame(4, RoleLevel::rank('administrator'));
        self::assertTrue(RoleLevel::rank('sponsor') > RoleLevel::rank('subscriber'));
    }

    public function testUnknownLevelsCollapseToGuest(): void
    {
        self::assertSame(0, RoleLevel::rank('editor'));
        self::assertSame(0, RoleLevel::rank(''));
        self::assertFalse(RoleLevel::isValid('editor'));
        self::assertTrue(RoleLevel::isValid('guest'));
    }

    public function testUpToReturnsEveryLevelTheViewerCanSee(): void
    {
        self::assertSame(['guest'], RoleLevel::upTo(0));
        self::assertSame(['guest', 'subscriber'], RoleLevel::upTo(1));
        self::assertSame(
            ['guest', 'subscriber', 'sponsor', 'author', 'administrator'],
            RoleLevel::upTo(4)
        );
    }
}
