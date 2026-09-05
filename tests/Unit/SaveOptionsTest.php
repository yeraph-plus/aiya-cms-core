<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\SaveOptions;
use PHPUnit\Framework\TestCase;

final class SaveOptionsTest extends TestCase
{
    public function testJpegQualityIsPassedThrough(): void
    {
        self::assertSame(['jpeg_quality' => 82], SaveOptions::for('jpg', 82));
        self::assertSame(['jpeg_quality' => 82], SaveOptions::for('JPEG', 82));
    }

    public function testQualityIsClampedToValidRange(): void
    {
        self::assertSame(['jpeg_quality' => 100], SaveOptions::for('jpg', 250));
        self::assertSame(['jpeg_quality' => 0], SaveOptions::for('jpg', -5));
    }

    public function testPngQualityMapsToCompressionLevelInverted(): void
    {
        // Quality 100 -> compression 0; quality 0 -> compression 9.
        self::assertSame(['png_compression_level' => 0], SaveOptions::for('png', 100));
        self::assertSame(['png_compression_level' => 9], SaveOptions::for('png', 0));
        self::assertSame(['png_compression_level' => 4], SaveOptions::for('png', 50));
    }

    public function testWebpAndAvifCarryTheirOwnKeys(): void
    {
        self::assertSame(['webp_quality' => 90], SaveOptions::for('webp', 90));
        self::assertSame(['avif_quality' => 70], SaveOptions::for('avif', 70));
    }

    public function testBmpAndUnknownFormats(): void
    {
        self::assertSame(['bmp_quality' => 50], SaveOptions::for('bmp', 50));
        self::assertSame([], SaveOptions::for('gif', 80));
    }

    public function testWithDefaultsFillsMissingKeysOnly(): void
    {
        self::assertSame(['jpeg_quality' => 96], SaveOptions::withDefaults('jpg', []));
        self::assertSame(['jpeg_quality' => 42], SaveOptions::withDefaults('jpg', ['jpeg_quality' => 42]));
        self::assertSame(['flatten' => false], SaveOptions::withDefaults('gif', []));
        self::assertSame([], SaveOptions::withDefaults('xyz', []));
    }
}
