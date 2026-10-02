<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Rest\ScriptVariant;
use Aiya\Infra\OpenCc\Converter;
use PHPUnit\Framework\TestCase;

/**
 * The exit conversion's decision core: string leaves convert through the
 * mapped strategy, protected keys stay byte-stable, markup converts
 * tag-aware (text nodes and visible attributes, never code/pre, never
 * URLs), and anything without a CJK ideograph passes through untouched —
 * which is also what keeps the zh_CN/en_US site on the zero-cost path.
 * Assertions pin unambiguous single-character mappings only; phrase-level
 * dictionary rewrites are the engine's prerogative, not this contract's.
 */
final class ScriptVariantTest extends TestCase
{
    private ScriptVariant $filter;

    protected function setUp(): void
    {
        $this->filter = new ScriptVariant(new Converter());
    }

    public function testPlainStringsConvertThroughTheStrategy(): void
    {
        self::assertSame('簡體內容的站點頁面', $this->filter->walk('简体内容的站点页面', Converter::STRATEGY_S2TW));
    }

    public function testScalarsAndEmptyStringsPassThrough(): void
    {
        self::assertSame(7, $this->filter->walk(7, Converter::STRATEGY_S2TW));
        self::assertSame(0.5, $this->filter->walk(0.5, Converter::STRATEGY_S2TW));
        self::assertTrue($this->filter->walk(true, Converter::STRATEGY_S2TW));
        self::assertNull($this->filter->walk(null, Converter::STRATEGY_S2TW));
        self::assertSame('', $this->filter->walk('', Converter::STRATEGY_S2TW));
    }

    public function testProtectedKeysStayByteStableWhileDisplayTextConverts(): void
    {
        $payload = [
            'title' => '文章标题',
            'slug' => 'wen-zhang-biao-ti',
            'url' => '/resources/123/',
            'author' => [
                'name' => '小简',
                'email' => 'user@example.com',
                'login' => 'user_login',
            ],
            'meta' => [
                'apiVersion' => '1',
                'requestId' => 'a1b2c3d4',
                'timezone' => 'Asia/Shanghai',
                'locale' => 'zh_CN',
            ],
        ];

        $out = $this->filter->walk($payload, Converter::STRATEGY_S2TW);

        self::assertSame('文章標題', $out['title']);
        self::assertSame('小簡', $out['author']['name'], 'display names are display text — only the protected key itself is exempt');
        self::assertSame('wen-zhang-biao-ti', $out['slug']);
        self::assertSame('/resources/123/', $out['url']);
        self::assertSame('user@example.com', $out['author']['email']);
        self::assertSame('user_login', $out['author']['login']);
        self::assertSame('1', $out['meta']['apiVersion']);
        self::assertSame('a1b2c3d4', $out['meta']['requestId']);
        self::assertSame('Asia/Shanghai', $out['meta']['timezone']);
        self::assertSame('zh_CN', $out['meta']['locale']);
    }

    public function testListsConvertTheirStringMembers(): void
    {
        $out = $this->filter->walk(['简体标题', 42, ['嵌套内容']], Converter::STRATEGY_S2TW);

        self::assertSame('簡體標題', $out[0]);
        self::assertSame(42, $out[1]);
        self::assertSame('嵌套內容', $out[2][0]);
    }

    public function testMarkupConvertsTextNodesButNotTagsUrlsOrCode(): void
    {
        $html = '<alert level="warning" title="站点公告">正文内容'
            . '<code>echo $简体变量;</code>'
            . '<a href="/tag/中文目录">文字内容</a></alert>';

        $out = $this->filter->markup($html, Converter::STRATEGY_S2TW);

        self::assertSame('站點公告', $this->attribute($out, 'title'));
        self::assertStringContainsString('正文內容', $out);
        self::assertStringContainsString('<code>echo $简体变量;</code>', $out, 'code content passes through untouched');
        self::assertStringContainsString('href="/tag/中文目录"', $out, 'URLs stay byte-stable');
        self::assertStringContainsString('文字內容', $out);
        self::assertSame('warning', $this->attribute($out, 'level'));
    }

    public function testPreBlocksAreProtectedToo(): void
    {
        $html = "<pre>简体保留\n简体第二行</pre><p>简体外</p>";

        $out = $this->filter->markup($html, Converter::STRATEGY_S2TW);

        self::assertStringContainsString('<pre>简体保留' . "\n" . '简体第二行</pre>', $out);
        self::assertStringContainsString('<p>簡體外</p>', $out);
    }

    public function testVisibleAttributesConvertButPlainOnesDoNot(): void
    {
        $html = '<img src="/cover.webp" alt="封面图片" width="300" data-raw="简体数据">';

        $out = $this->filter->markup($html, Converter::STRATEGY_S2TW);

        self::assertSame('封面圖片', $this->attribute($out, 'alt'));
        self::assertSame('简体数据', $this->attribute($out, 'data-raw'));
        self::assertStringContainsString('src="/cover.webp"', $out);
    }

    public function testAsciiAndNonCjkStringsPassThroughUntouched(): void
    {
        self::assertSame('plain ascii title', $this->filter->walk('plain ascii title', Converter::STRATEGY_S2TW));
        self::assertSame('<p>english only markup</p>', $this->filter->markup('<p>english only markup</p>', Converter::STRATEGY_S2TW));
        self::assertSame('……、「」', $this->filter->walk('……、「」', Converter::STRATEGY_S2TW), 'punctuation without ideographs stays');
    }

    /** Pulls one attribute value back out of a rendered tag. */
    private function attribute(string $tag, string $name): string
    {
        if (preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/' . preg_quote($name, '/') . '=\'([^\']*)\'/', $tag, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
