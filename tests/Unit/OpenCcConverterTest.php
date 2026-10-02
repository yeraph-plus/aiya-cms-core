<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\OpenCc\Converter;
use PHPUnit\Framework\TestCase;

/**
 * The opencc-convert package's locale → strategy mapping (the seam the
 * exit filter drives) and a real-engine smoke of both shipped strategies.
 */
final class OpenCcConverterTest extends TestCase
{
    private Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Converter();
    }

    public function testTraditionalLocalesMapToTheirStrategies(): void
    {
        self::assertSame(Converter::STRATEGY_S2TW, $this->converter->strategyForLocale('zh_TW'));
        self::assertSame(Converter::STRATEGY_S2TW, $this->converter->strategyForLocale('zh-Hant-TW'));
        self::assertSame(Converter::STRATEGY_S2TW, $this->converter->strategyForLocale('zh-Hant'));
        self::assertSame(Converter::STRATEGY_S2HK, $this->converter->strategyForLocale('zh_HK'));
        self::assertSame(Converter::STRATEGY_S2HK, $this->converter->strategyForLocale('zh-HK'));
    }

    public function testSimplifiedAndWesternLocalesMapToNull(): void
    {
        self::assertNull($this->converter->strategyForLocale('zh_CN'));
        self::assertNull($this->converter->strategyForLocale('zh'));
        self::assertNull($this->converter->strategyForLocale('en_US'));
        self::assertNull($this->converter->strategyForLocale(''));
        self::assertNull($this->converter->strategyForLocale('de_DE_formal'));
    }

    public function testBothStrategiesConvertRealText(): void
    {
        $source = '简体内容的站点页面';

        self::assertSame('簡體內容的站點頁面', $this->converter->convert($source, Converter::STRATEGY_S2TW));
        self::assertSame('簡體內容的站點頁面', $this->converter->convert($source, Converter::STRATEGY_S2HK));
    }

    public function testEmptyInputConvertsToItself(): void
    {
        self::assertSame('', $this->converter->convert('', Converter::STRATEGY_S2TW));
    }
}
