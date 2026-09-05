<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\Colors;
use PHPUnit\Framework\TestCase;

final class ColorsTest extends TestCase
{
    public function testHexToRgbExpandsThreeDigitNotation(): void
    {
        self::assertSame(['r' => 0xaa, 'g' => 0xbb, 'b' => 0xcc], Colors::hexToRgb('#abc'));
        self::assertSame(['r' => 0xaa, 'g' => 0xbb, 'b' => 0xcc], Colors::hexToRgb('abc'));
    }

    public function testHexToRgbParsesSixDigitNotation(): void
    {
        self::assertSame(['r' => 0x1f, 'g' => 0x29, 'b' => 0x37], Colors::hexToRgb('#1F2937'));
    }

    public function testHexToRgbFallsBackToBlackOnMalformedInput(): void
    {
        $black = ['r' => 0, 'g' => 0, 'b' => 0];
        self::assertSame($black, Colors::hexToRgb(''));
        self::assertSame($black, Colors::hexToRgb('#12345'));
        self::assertSame($black, Colors::hexToRgb('#zzzzzz'));
        self::assertSame($black, Colors::hexToRgb('not-a-color'));
    }

    public function testNormalizeHexProducesLowercaseRrggbb(): void
    {
        self::assertSame('#ff00aa', Colors::normalizeHex('#FF00AA'));
        self::assertSame('#ffaaff', Colors::normalizeHex('#faf'));
    }

    public function testLuminanceWeightsChannelsPerRec601(): void
    {
        self::assertSame(0, Colors::luminance(['r' => 0, 'g' => 0, 'b' => 0]));
        self::assertSame(255, Colors::luminance(['r' => 255, 'g' => 255, 'b' => 255]));
        // 0.299 * 255 = 76 (floor)
        self::assertSame(76, Colors::luminance(['r' => 255, 'g' => 0, 'b' => 0]));
    }
}
