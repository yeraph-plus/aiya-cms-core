<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Site;
use Aiya\Core\Api\Contract\SiteDefaults;
use Aiya\Core\Api\Contract\SiteFooter;
use Aiya\Core\Api\Contract\SiteTheme;
use PHPUnit\Framework\TestCase;

final class SiteContractTest extends TestCase
{
    public function testSerializesToTheContractShape(): void
    {
        $site = new Site(
            'AIYA',
            'A headless CMS',
            'zh_CN',
            'Asia/Shanghai',
            new Image('https://cdn.example.test/favicon.png', 'AIYA', 64, 64),
            new Image('https://cdn.example.test/banner.webp', 'AIYA', 1920, 320),
            true,
            new SiteDefaults('dark', null, new SiteTheme('#E94F69')),
            new SiteFooter('京ICP备2026000001号-1', '京公网安备11010000000001号', '11010000000001', '© 2026 AIYA'),
        );

        $shape = $site->toArray();

        self::assertSame('AIYA', $shape['name']);
        self::assertSame('A headless CMS', $shape['description']);
        self::assertSame('zh_CN', $shape['language']);
        self::assertSame('Asia/Shanghai', $shape['timezone']);
        self::assertSame(
            ['url' => 'https://cdn.example.test/favicon.png', 'alt' => 'AIYA', 'width' => 64, 'height' => 64],
            $shape['favicon']
        );
        self::assertSame(
            ['url' => 'https://cdn.example.test/banner.webp', 'alt' => 'AIYA', 'width' => 1920, 'height' => 320],
            $shape['banner']
        );
        self::assertTrue($shape['registrationOpen']);
        self::assertSame(['colorMode' => 'dark', 'thumb' => null, 'theme' => ['primary' => '#E94F69']], $shape['defaults']);
        self::assertSame([
            'icp' => '京ICP备2026000001号-1',
            'mps' => '京公网安备11010000000001号',
            'mpsCode' => '11010000000001',
            'note' => '© 2026 AIYA',
        ], $shape['footer']);
    }

    public function testUnconfiguredSectionsSerializeEmpty(): void
    {
        $site = new Site(
            'AIYA',
            '',
            'zh_CN',
            'UTC',
            null,
            null,
            false,
            new SiteDefaults('system', null, new SiteTheme('#E94F69')),
            new SiteFooter('', '', '', ''),
        );

        $shape = $site->toArray();

        self::assertNull($shape['favicon']);
        self::assertNull($shape['banner']);
        self::assertFalse($shape['registrationOpen']);
        self::assertSame(['colorMode' => 'system', 'thumb' => null, 'theme' => ['primary' => '#E94F69']], $shape['defaults']);
        self::assertSame(['icp' => '', 'mps' => '', 'mpsCode' => '', 'note' => ''], $shape['footer']);
    }
}
