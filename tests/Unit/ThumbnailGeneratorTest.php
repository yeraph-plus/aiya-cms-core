<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\ImageProcessor\SaveOptions;
use Aiya\Infra\ImageProcessor\ThumbnailGenerator;
use Imagine\Gd\Imagine;
use PHPUnit\Framework\TestCase;

/**
 * The generators run inside cron/admin requests with a hard execution
 * budget; the source-bound step (first-frame coalesce plus a proportional
 * pre-shrink to twice the target's long edge) is what keeps huge or
 * animated inputs from burning the whole budget before the blur frame
 * (caught live on production: a card generation died at 60s in blur after
 * decoding, copying and resizing a full-size original twice). These tests
 * run real pixels through the GD driver.
 */
final class ThumbnailGeneratorTest extends TestCase
{
    private const WIDTH = 640;
    private const HEIGHT = 360;

    private string $tmpDir;

    private ThumbnailGenerator $generator;

    private Imagine $imagine;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->tmpDir = (string) sys_get_temp_dir();
        $this->imagine = new Imagine();
        $this->generator = new ThumbnailGenerator($this->imagine);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup
            }
        }
    }

    public function testOversizedLandscapeSourceCoversToTargetSize(): void
    {
        $out = $this->generateFromSource(2400, 1600);

        self::assertNotNull($out);
        self::assertSame([self::WIDTH, self::HEIGHT], $this->sizeOf($out));
    }

    public function testOversizedPortraitSourceTakesTheBlurCompositePath(): void
    {
        // Ratio 0.667 vs target 1.78 — far past the 0.35 log gap, so the
        // blurred-background composite renders; the pre-shrink must apply
        // to both of its full-size inputs.
        $out = $this->generateFromSource(1600, 2400);

        self::assertNotNull($out);
        self::assertSame([self::WIDTH, self::HEIGHT], $this->sizeOf($out));
    }

    public function testSmallSourceSkipsTheShrinkAndStillCovers(): void
    {
        $out = $this->generateFromSource(800, 600);

        self::assertNotNull($out);
        self::assertSame([self::WIDTH, self::HEIGHT], $this->sizeOf($out));
    }

    private function generateFromSource(int $width, int $height): ?string
    {
        $source = $this->tmpDir . '/aiya-thumb-src-' . uniqid() . '.jpg';
        $image = $this->imagine->create(new \Imagine\Image\Box($width, $height));
        // A little variance so the blur/crop paths operate on real pixels.
        $image->draw()->rectangle(
            new \Imagine\Image\Point((int) ($width / 4), (int) ($height / 4)),
            new \Imagine\Image\Point((int) ($width / 2), (int) ($height / 2)),
            $image->palette()->color('#336699', 100),
            true
        );
        $image->save($source);
        $this->files[] = $source;

        $dest = $this->tmpDir . '/aiya-thumb-dest-' . uniqid() . '.jpg';
        $this->files[] = $dest;

        return $this->generator->generate($source, $dest, self::WIDTH, self::HEIGHT, SaveOptions::for('jpg', 82));
    }

    /** @return array{0: int, 1: int} */
    private function sizeOf(string $path): array
    {
        $size = $this->imagine->open($path)->getSize();

        return [$size->getWidth(), $size->getHeight()];
    }
}
