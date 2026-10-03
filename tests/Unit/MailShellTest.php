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
 * icon joins the embeds under the fixed Content-ID, and the footer names
 * the first parseable recipient.
 */
final class MailShellTest extends TestCase
{
    private const COLOR = '#e94f69';

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_attached_files'] = [];
    }

    private function shell(?string $iconPath = null): MailShell
    {
        return new MailShell(
            new MailTemplate(self::COLOR, '喵喵测试版', 'https://aiya.test', $iconPath !== null ? MailShell::ICON_CID : null),
            $iconPath,
        );
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
        // The icon embed rides along: the rewrite layer's document references
        // the CID and notification-style callers cannot pass embeds at all.
        $out = $this->apply($this->shell('/var/www/uploads/icon.png'), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => MailTemplate::SHELL_MARKER . '<html><img src="cid:' . MailShell::ICON_CID . '"></html>',
            'headers' => [],
        ]);

        self::assertSame(MailTemplate::SHELL_MARKER . '<html><img src="cid:' . MailShell::ICON_CID . '"></html>', $out['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
        self::assertSame([MailShell::ICON_CID => '/var/www/uploads/icon.png'], $out['embeds']);
    }

    public function testAMarkedMessageWithoutAnIconReferenceGetsNoEmbeds(): void
    {
        // A text-only header references no CID — nothing to attach.
        $out = $this->apply($this->shell('/var/www/uploads/icon.png'), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => MailTemplate::SHELL_MARKER . '<html>纯文字头</html>',
            'headers' => [],
        ]);

        self::assertArrayNotHasKey('embeds', $out);
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

        self::assertSame($args, $this->apply($this->shell('/var/www/uploads/icon.png'), $args));
    }

    public function testAnEmptyMessagePassesThroughUntouched(): void
    {
        $args = ['to' => 'a@example.test', 'subject' => 'x', 'message' => '', 'headers' => []];

        self::assertSame($args, $this->apply($this->shell(), $args));
    }

    public function testTheSiteIconJoinsTheEmbeds(): void
    {
        $out = $this->apply($this->shell('/var/www/uploads/icon.png'), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertSame([MailShell::ICON_CID => '/var/www/uploads/icon.png'], $out['embeds']);
        self::assertStringContainsString('src="cid:' . MailShell::ICON_CID . '"', (string) $out['message']);
    }

    public function testWithoutAnIconTheHeaderIsTextOnly(): void
    {
        $out = $this->apply($this->shell(), [
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringNotContainsString('cid:', (string) $out['message']);
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

    public function testFromSiteReadsTheSiteIconAndKeepsStoredEntities(): void
    {
        // The icon must exist on disk: a dead attachment path never joins
        // the embeds (the fromSite is_file guard).
        $icon = tempnam(sys_get_temp_dir(), 'aiya-icon');
        self::assertNotFalse($icon);
        $GLOBALS['__aiya_test_options'] = [
            'frontend' => ['color_primary' => '#2271b1'],
            'site_icon' => 55,
            'blogname' => '站名 &amp; 符号',
            'blog_charset' => 'UTF-8',
        ];
        $GLOBALS['__aiya_test_attached_files'][55] = $icon;

        $shell = MailShell::fromSite();
        $out = $shell->apply([
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);

        self::assertStringContainsString('站名 &amp; 符号', (string) $out['message'], 'the stored entity decodes once, then re-escapes on render');
        self::assertSame([MailShell::ICON_CID => $icon], $out['embeds']);

        $GLOBALS['__aiya_test_attached_files'][55] = '/var/www/uploads/dead-site-icon.png';
        $dead = MailShell::fromSite()->apply([
            'to' => 'a@example.test',
            'subject' => 'x',
            'message' => '正文',
            'headers' => [],
        ]);
        self::assertArrayNotHasKey('embeds', $dead, 'a dead attachment path never joins the embeds');
        self::assertStringNotContainsString('cid:', (string) $dead['message']);
        unset($GLOBALS['__aiya_test_attached_files'][55]);
        unlink($icon);
    }
}
