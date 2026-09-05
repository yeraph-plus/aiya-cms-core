<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\CoverSpec;
use PHPUnit\Framework\TestCase;

final class CoverSpecTest extends TestCase
{
    public function testDefaults(): void
    {
        $spec = new CoverSpec();

        self::assertSame('photo', $spec->model);
        self::assertSame(800, $spec->width);
        self::assertSame(600, $spec->height);
        self::assertSame(15, $spec->maxChars);
    }

    public function testFromArrayClampsAndNormalizes(): void
    {
        $spec = CoverSpec::fromArray([
            'model' => 'something-else',
            'width' => 0,
            'height' => -5,
            'overlay_opacity' => 900,
            'max_chars' => 0,
            'line_spacing' => -2,
            'label_padding_x' => -1,
        ]);

        self::assertSame('photo', $spec->model);
        self::assertSame(1, $spec->width);
        self::assertSame(1, $spec->height);
        self::assertSame(100, $spec->overlayOpacity);
        self::assertSame(1, $spec->maxChars);
        self::assertSame(0, $spec->lineSpacing);
        self::assertSame(0, $spec->labelPaddingX);
    }

    public function testEmptyBackgroundColorPicksARandomMutedTone(): void
    {
        $spec = CoverSpec::fromArray([]);

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $spec->backgroundColor);
    }

    public function testExplicitBackgroundColorIsKept(): void
    {
        $spec = CoverSpec::fromArray(['background_color' => '#123456']);

        self::assertSame('#123456', $spec->backgroundColor);
    }

    public function testPatternMaterialPathIsAcceptedUnderBothKeys(): void
    {
        self::assertSame('/tmp/material', CoverSpec::fromArray(['pattern_material_path' => '/tmp/material'])->patternMaterialDir);
        self::assertSame('/tmp/material', CoverSpec::fromArray(['pattern_material_dir' => '/tmp/material'])->patternMaterialDir);
    }
}
