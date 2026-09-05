<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\WatermarkSpec;
use PHPUnit\Framework\TestCase;

final class WatermarkSpecTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'aiya-wm');
        self::assertNotFalse($this->tmpFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    public function testDefaults(): void
    {
        $spec = new WatermarkSpec();

        self::assertSame('off', $spec->modeType);
        self::assertSame('bottom-right', $spec->position);
        self::assertSame(24, $spec->fontSize);
        self::assertSame(80, $spec->opacity);
        self::assertSame(15, $spec->offsetX);
    }

    public function testFromArrayClampsValues(): void
    {
        $spec = WatermarkSpec::fromArray([
            'font_size' => 0,
            'opacity' => 250,
            'offset_x' => -3,
        ]);

        self::assertSame(1, $spec->fontSize);
        self::assertSame(100, $spec->opacity);
        self::assertSame(-3, $spec->offsetX);
    }

    public function testFromArrayRejectsUnknownModeAndPosition(): void
    {
        $spec = WatermarkSpec::fromArray([
            'mode_type' => 'sideways',
            'position' => 'inside-the-matrix',
        ]);

        self::assertSame('off', $spec->modeType);
        self::assertSame('bottom-right', $spec->position);
    }

    public function testTypeDegradesImageModeWithoutExistingFile(): void
    {
        $spec = WatermarkSpec::fromArray(['mode_type' => 'image', 'image_file' => '/nonexistent/wm.png']);
        self::assertSame('off', $spec->type());
    }

    public function testTypeKeepsImageModeWithExistingFile(): void
    {
        $spec = WatermarkSpec::fromArray(['mode_type' => 'image', 'image_file' => $this->tmpFile]);
        self::assertSame('image', $spec->type());
    }

    public function testTypeRequiresTextAndFontForTextMode(): void
    {
        $emptyText = WatermarkSpec::fromArray(['mode_type' => 'text', 'text' => '', 'font_file' => $this->tmpFile]);
        self::assertSame('off', $emptyText->type());

        $missingFont = WatermarkSpec::fromArray(['mode_type' => 'text', 'text' => 'AIYA', 'font_file' => '/nonexistent/font.otf']);
        self::assertSame('off', $missingFont->type());

        $valid = WatermarkSpec::fromArray(['mode_type' => 'text', 'text' => 'AIYA', 'font_file' => $this->tmpFile]);
        self::assertSame('text', $valid->type());
    }
}
