<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\LightboxModule;
use PHPUnit\Framework\TestCase;

final class LightboxModuleTest extends TestCase
{
    private LightboxModule $module;

    protected function setUp(): void
    {
        $this->module = new LightboxModule();
    }

    public function testStampsBareContentImages(): void
    {
        $in = '<p>看图</p><img src="https://cdn.test/a.jpg" alt="a">';
        $out = $this->module->inject($in);

        self::assertStringContainsString('class="' . LightboxModule::CLASS_NAME . '"', $out);
        self::assertStringContainsString('src="https://cdn.test/a.jpg"', $out);
    }

    public function testAppendsToAnExistingClass(): void
    {
        $in = '<img class="wp-image-9 size-full" src="https://cdn.test/a.jpg">';
        $out = $this->module->inject($in);

        self::assertStringContainsString('class="wp-image-9 size-full ' . LightboxModule::CLASS_NAME . '"', $out);
    }

    public function testLeavesRenderedSmiliesUntouched(): void
    {
        $in = '<img class="aiya-smilie" src="https://cdn.test/s.webp" alt="::01::">';
        self::assertSame($in, $this->module->inject($in));
    }

    public function testIsIdempotent(): void
    {
        $in = '<img src="https://cdn.test/a.jpg">';
        $once = $this->module->inject($in);

        self::assertSame($once, $this->module->inject($once));
    }

    public function testIgnoresBodyWithoutImages(): void
    {
        self::assertSame('', $this->module->inject(''));
        $text = '<p>纯文字与 <code>a < b</code></p>';
        self::assertSame($text, $this->module->inject($text));
    }

    public function testSkipsNonImageMarkup(): void
    {
        $in = '<figure><img src="https://cdn.test/a.jpg"><figcaption>图注</figcaption></figure>';
        $out = $this->module->inject($in);

        self::assertSame(1, substr_count($out, LightboxModule::CLASS_NAME));
    }
}
