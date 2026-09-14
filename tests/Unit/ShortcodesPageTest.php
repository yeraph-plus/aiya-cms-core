<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\DevTools\ShortcodesPage;
use PHPUnit\Framework\TestCase;

final class ShortcodesPageTest extends TestCase
{
    public function testLabelsPlainFunctionsAndStatics(): void
    {
        self::assertSame('wp_video_shortcode', ShortcodesPage::callbackLabel('wp_video_shortcode'));
        self::assertSame('WPJAM_Shortcode::callback', ShortcodesPage::callbackLabel(['WPJAM_Shortcode', 'callback']));
    }

    public function testLabelsObjectMethodsAndClosures(): void
    {
        self::assertSame(self::class . '::sampleMethod', ShortcodesPage::callbackLabel([$this, 'sampleMethod']));
        self::assertSame('Closure', ShortcodesPage::callbackLabel(static function (): void {
        }));
    }

    public function testLabelsFallbacksForUnexpectedCallbacks(): void
    {
        self::assertSame('ArrayObject', ShortcodesPage::callbackLabel(new \ArrayObject()));
        self::assertSame('int', ShortcodesPage::callbackLabel(42));
        self::assertSame('float', ShortcodesPage::callbackLabel(1.5));
    }

    private function sampleMethod(): void
    {
    }
}
