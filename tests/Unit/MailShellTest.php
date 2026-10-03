<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mail\MailShell;
use Aiya\Core\Domain\Mail\MailTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The mail takeover (2026-10-03): the `wp_mail` args filter wraps every
 * message in the brand shell and normalises the Content-Type, while
 * delivery itself stays WordPress's own. Plain text escapes into the
 * content slot, HTML fragments ride as-is, a message already carrying
 * the shell marker passes through untouched (no double wrap), the site
 * icon rides as a plain remote URL (2026-10-04 — the CID lane made
 * clients list the logo as an attachment), and the footer names the
 * first parseable recipient.
 */
final class MailShellTest extends TestCase
{
    private const COLOR = '#e94f69';

    private const ICON_SRC = 'https://aiya.test/wp-content/uploads/icon-150.png';

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_mails'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_attachment_images'] = [];
        $GLOBALS['__aiya_test_attachment_files'] = [];
    }

    protected function tearDown(): void
    {
        // A filter registered for one test must not rewrite another
        // file's mail flow (downstream suites read __aiya_test_mails).
        $GLOBALS['__aiya_test_filters'] = [];
    }

    private function shell(?string $iconSrc = null): MailShell
    {
        return new MailShell(new MailTemplate(self::COLOR, '喵喵测试版', 'https://aiya.test', $iconSrc));
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function apply(MailShell $shell, array $args): array
    {
        return $shell->apply($args);
    }

    public function testPlainTextWrapsIntoTheShellAsHtml(): void
    {
        $out = $this->apply($this->shell(), [
            'to' => 'reader@example.test',
            'subject' => 'ignored here',
            'message' => "第一段落。\n\n第二段落。",
            'headers' => [],
        ]);

        self::assertStringContainsString(MailTemplate::SHELL_MARKER, (string) $out['message']);
        self::assertStringContainsString('<p>第一段落。</p>', (string) $out['message']);
        self::assertStringContainsString('<p>第二段落。</p>', (string) $out['message']);
        self::assertStringContainsString('喵喵测试版', (string) $out['message']);
        self::assertStringContainsString('Sent to reader@example.test', (string) $out['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
    }

    public function testTheButtonComponentCarriesTheThemeColor(): void
    {
        $template = new MailTemplate(self::COLOR, '喵喵测试版', 'https://aiya.test');
        $html = $template->button('重设密码', 'https://aiya.test/reset-password/?login=demo&key=demo');

        self::assertStringContainsString('background-color: ' . self::COLOR, $html, 'the theme color tints the content layer');
        self::assertStringContainsString('href="https://aiya.test/reset-password/?login=demo', $html, 'esc_url encodes the ampersand');
        self::assertStringContainsString('key=demo"', $html);
        self::assertStringContainsString('重设密码', $html);
    }

    public function testHtmlFragmentsRideTheSlotAsIs(): void
    {
        $out = $this->apply($this->shell(), [
            'to' => 'reader@example.test',
            'subject' => 'x',
            'message' => '<p>手写 <strong>HTML</strong></p>',
            'headers' => ['Content-Type: text/html; charset=UTF-8'],
        ]);

        self::assertStringContainsString('<p>手写 <strong>HTML</strong></p>', (string) $out['message']);
        self::assertStringNotContainsString('&lt;p&gt;', (string) $out['message'], 'the fragment is not escaped');
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
    }

    public function testAMarkedMessageKeepsItsContentButShipsAsHtml(): void
    {
        // A marked message is finished brand HTML — content stays byte-for-
        // byte, but message-only filters cannot set headers, so the takeover
        // normalises the Content-Type here or the document travels as text.
        $out = $this->apply($this->shell(self::ICON_SRC), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => MailTemplate::SHELL_MARKER . '<html><img src="' . self::ICON_SRC . '"></html>',
            'headers' => [],
        ]);

        self::assertSame(MailTemplate::SHELL_MARKER . '<html><img src="' . self::ICON_SRC . '"></html>', $out['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
        self::assertArrayNotHasKey('embeds', $out, 'the shell injects no embeds — its icon is a remote URL');
    }

    public function testCallerEmbedsPassThroughUntouched(): void
    {
        // The takeover never owns the embeds lane: a caller embedding its
        // own images keeps them exactly as passed, nothing appended.
        $out = $this->apply($this->shell(self::ICON_SRC), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '<p>正文</p>',
            'headers' => ['Content-Type: text/html; charset=UTF-8'],
            'embeds' => ['caller-image' => '/var/www/uploads/caller.png'],
        ]);

        self::assertSame(['caller-image' => '/var/www/uploads/caller.png'], $out['embeds']);
    }

    public function testAMultipartBodyStandsDownUntouched(): void
    {
        // A third party's MIME document is beyond the takeover's single-part
        // competence: hands off entirely, boundary structure intact.
        $args = [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => 'multipart body',
            'headers' => ['Content-Type: multipart/alternative; boundary="xyz"'],
        ];

        self::assertSame($args, $this->apply($this->shell(self::ICON_SRC), $args));
    }

    public function testAnEmptyMessagePassesThroughUntouched(): void
    {
        $args = ['to' => 'a@example.test', 'subject' => 'x', 'message' => '', 'headers' => []];

        self::assertSame($args, $this->apply($this->shell(), $args));
    }

    public function testTheSiteIconRidesAsARemoteUrl(): void
    {
        $out = $this->apply($this->shell(self::ICON_SRC), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringContainsString('src="' . self::ICON_SRC . '"', (string) $out['message']);
        self::assertStringNotContainsString('cid:', (string) $out['message'], 'no MIME part, no attachment in any client');
        self::assertArrayNotHasKey('embeds', $out);
    }

    public function testWithoutAnIconTheHeaderIsTextOnly(): void
    {
        $out = $this->apply($this->shell(), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringNotContainsString('<img', (string) $out['message']);
        self::assertArrayNotHasKey('embeds', $out);
    }

    public function testTheFooterNamesTheFirstParseableRecipient(): void
    {
        $out = $this->apply($this->shell(), [
            'to' => ['bogus', 'real@example.test'],
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringContainsString('Sent to real@example.test', (string) $out['message']);
        self::assertStringNotContainsString('bogus', (string) $out['message']);
    }

    public function testWpMailRunsTheArgsFilterBeforeRecording(): void
    {
        // The shim must mirror production: wp_mail() applies the 'wp_mail'
        // args filter before transport, so a caller that merely calls
        // wp_mail() while MailShell is registered still ships the wrapped
        // document. Recording raw text instead is the hole the shell/CID
        // regression slipped through (ledger R7).
        \add_filter('wp_mail', $this->shell(self::ICON_SRC)->apply(...), 999);

        \wp_mail('reader@example.test', '主题', '正文');

        $mail = $GLOBALS['__aiya_test_mails'][0];
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, (string) $mail['message']);
        self::assertStringContainsString('<p>正文</p>', (string) $mail['message']);
        self::assertStringContainsString('src="' . self::ICON_SRC . '"', (string) $mail['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $mail['headers']);
    }

    public function testWpMailHonoursThePreWpMailShortCircuit(): void
    {
        \add_filter('pre_wp_mail', static fn ($return): bool => false);

        self::assertFalse(\wp_mail('reader@example.test', '主题', '正文'));
        self::assertSame([], $GLOBALS['__aiya_test_mails'], 'a short-circuited mail never reaches the recorded transport');
    }

    public function testFromSiteReadsTheSiteIconAndKeepsStoredEntities(): void
    {
        // The icon must resolve to a URL: an attachment without a
        // resolvable image never fills the header slot (thumbnail size
        // first, the original file's URL as the fallback).
        $GLOBALS['__aiya_test_options'] = [
            'frontend' => ['color_primary' => '#2271b1'],
            'site_icon' => 55,
            'blogname' => '站名 &amp; 符号',
            'blog_charset' => 'UTF-8',
        ];
        $GLOBALS['__aiya_test_attachment_images'][55] = [
            'url' => self::ICON_SRC,
            'width' => 150,
            'height' => 150,
        ];

        $shell = MailShell::fromSite();
        $out = $shell->apply([
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringContainsString('站名 &amp; 符号', (string) $out['message'], 'the stored entity decodes once, then re-escapes on render');
        self::assertStringContainsString('src="' . self::ICON_SRC . '"', (string) $out['message']);

        // A missing attachment resolves to nothing: text-only header, no
        // broken image riding into every mail.
        unset($GLOBALS['__aiya_test_attachment_images'][55]);
        $textOnly = MailShell::fromSite()->apply([
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);
        self::assertStringNotContainsString('<img', (string) $textOnly['message']);
    }

    public function testFromSiteFallsBackToTheAttachmentUrl(): void
    {
        // No thumbnail-size metadata — the original file's URL carries the
        // header slot instead of dropping the logo.
        $GLOBALS['__aiya_test_options'] = ['site_icon' => 55];
        $GLOBALS['__aiya_test_attachment_files'][55] = self::ICON_SRC;

        $out = MailShell::fromSite()->apply([
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringContainsString('src="' . self::ICON_SRC . '"', (string) $out['message']);
    }
}
