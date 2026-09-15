<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;

final class SmiliesRendererTest extends TestCase
{
    private string $base;

    private SmiliesRenderer $renderer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/aiya-smilies-' . uniqid();
        if (!is_dir($this->base . '/aru') && !mkdir($this->base . '/aru', 0777, true) && !is_dir($this->base . '/aru')) {
            self::fail('Unable to create fixture directory');
        }
        touch($this->base . '/aru/滑稽.webp');
        touch($this->base . '/aru/笑.png');
        touch($this->base . '/aru/笑哭.png');

        $this->renderer = new SmiliesRenderer(new SmiliesRegistry($this->base, 'https://cdn.test/smilies'));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->base . '/aru') ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->base . '/aru/' . $entry);
            }
        }
        @rmdir($this->base . '/aru');
        @rmdir($this->base);
    }

    public function testReplacesTokensInPlainParagraphs(): void
    {
        $html = '<p>哈哈::滑稽::哈哈</p>';

        $out = $this->renderer->render($html);

        self::assertStringContainsString('<img src="https://cdn.test/smilies/aru/' . rawurlencode('滑稽.webp') . '"', $out);
        self::assertStringContainsString('alt="滑稽"', $out);
        self::assertStringContainsString('class="aiya-smilie"', $out);
        self::assertStringNotContainsString('::滑稽::', $out);
    }

    public function testLongerCodesWinOverTheirPrefixes(): void
    {
        $out = $this->renderer->render('::笑哭::');

        self::assertStringContainsString('alt="笑哭"', $out);
        self::assertStringNotContainsString('alt="笑"', $out);
    }

    public function testTagAttributesAndMarkupStaysUntouched(): void
    {
        $html = '<a href="/x/" title="::滑稽::">link</a>';

        self::assertSame($html, $this->renderer->render($html));
    }

    public function testCodeAndPreBodiesStayLiteral(): void
    {
        $html = '<pre><code>::滑稽::</code></pre><p style="font-family: monospace">x</p>';

        $out = $this->renderer->render($html);

        self::assertStringContainsString('::滑稽::', $out);
        self::assertStringNotContainsString('<img', $out);
    }

    public function testUnregisteredAndNonTokenTextStaysLiteral(): void
    {
        self::assertSame('::不存在::', $this->renderer->render('::不存在::'));
        self::assertSame('时间 12::30:: 走了', $this->renderer->render('时间 12::30:: 走了'), '"30" is not a registered code here');
        self::assertSame('see https://a.com/x and go', $this->renderer->render('see https://a.com/x and go'));
    }

    public function testAdjacentTokensAllConvert(): void
    {
        $out = $this->renderer->render('::滑稽::::笑::');

        self::assertSame(2, substr_count($out, '<img'), 'the shared colon run must not block either token');
    }

    public function testDegenerateColonClustersKeepTheirStrayColonsLiteral(): void
    {
        $out = $this->renderer->render(':::滑稽::');

        self::assertSame(1, substr_count($out, '<img'));
        self::assertSame(':<img', substr($out, 0, 5), 'only the token itself converts; surplus colons stay text');
    }

    public function testStripRemovesTokensFromPlainText(): void
    {
        self::assertSame('a  b', $this->renderer->strip('a ::滑稽:: b'));
        self::assertSame('nothing here', $this->renderer->strip('nothing here'));
        self::assertSame('keep ::未注册::', $this->renderer->strip('keep ::未注册::'));
    }

    public function testEmptyRegistryIsANoop(): void
    {
        $renderer = new SmiliesRenderer(new SmiliesRegistry($this->base . '/missing', 'https://cdn.test/smilies'));

        self::assertSame('<p>::滑稽::</p>', $renderer->render('<p>::滑稽::</p>'));
        self::assertSame('::滑稽::', $renderer->strip('::滑稽::'));
    }
}
