<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mail\CoreMailRewrites;
use Aiya\Core\Domain\Mail\MailTemplate;
use PHPUnit\Framework\TestCase;
use WP_User;

/**
 * The per-mail rewrite layer: the four WP-native mails whose copy points
 * back at wp-login/profile pages re-speak brand copy with front-end
 * links. Reset and welcome keys land on the front end's reset deep link
 * (login/key contract), the security notices carry the request-form CTA,
 * every product is a shell-marked brand document with text/html headers,
 * and the caller-sprinted subjects keep their blogname placeholder.
 */
final class MailRewritesTest extends TestCase
{
    private const FRONT = 'https://front.test';

    private CoreMailRewrites $rewrites;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [
            'frontend' => ['frontend_domain' => self::FRONT],
            'blogname' => 'AIYA 测试站',
            'blog_charset' => 'UTF-8',
        ];
        $this->rewrites = new CoreMailRewrites(new MailTemplate('#e94f69', 'AIYA 测试站', self::FRONT));
    }

    private function user(int $id = 7, string $login = 'uuid-seven', string $email = 'seven@example.test'): WP_User
    {
        return new WP_User((object) [
            'ID' => $id,
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => 'User ' . $id,
        ]);
    }

    public function testRetrievePasswordRewritesToTheFrontEndResetLink(): void
    {
        $title = $this->rewrites->retrievePasswordTitle('ignored', 'uuid-seven', $this->user());
        $html = $this->rewrites->retrievePasswordMessage(
            'native plain text',
            'key123abc',
            'uuid-seven',
            $this->user(),
        );

        self::assertSame('[AIYA 测试站] Password reset', $title);
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, $html);
        self::assertStringContainsString('href="https://front.test/reset-password?login=uuid-seven', $html, 'esc_url encodes the ampersand');
        self::assertStringContainsString('key=key123abc"', $html);
        self::assertStringContainsString('seven@example.test', $html);
        self::assertStringContainsString('within 24 hours', $html);
        self::assertStringNotContainsString('native plain text', $html);
        self::assertStringNotContainsString('wp-login.php', $html);
    }

    public function testAnUnusableResetContextKeepsTheNativeMessage(): void
    {
        // No key → the rewrite cannot build the deep link; the native
        // message travels on (the takeover still shells it).
        self::assertSame(
            'native plain text',
            $this->rewrites->retrievePasswordMessage('native plain text', null, 'uuid-seven', $this->user()),
        );
    }

    public function testNewUserWelcomeResolvesTheEmbeddedKey(): void
    {
        $email = [
            'to' => 'seven@example.test',
            'subject' => '[AIYA 测试站] New User Registration',
            'message' => "Welcome!\n\nhttps://aiya.test/wp-login.php?action=rp&key=embeddedkey99&login=uuid-seven",
            'headers' => [],
        ];

        $out = $this->rewrites->newUserEmail($email, $this->user(), 'AIYA 测试站');

        self::assertSame('[%s] Your account is ready.', $out['subject'], 'the caller appends the blog name');
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, (string) $out['message']);
        self::assertStringContainsString('reset-password?login=uuid-seven', (string) $out['message']);
        self::assertStringContainsString('key=embeddedkey99', (string) $out['message']);
        self::assertStringContainsString('Set password', (string) $out['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
    }

    public function testNewUserWelcomeWithoutAKeyStaysNative(): void
    {
        $email = ['to' => 'a@example.test', 'subject' => 's', 'message' => 'no key here', 'headers' => []];

        self::assertSame($email, $this->rewrites->newUserEmail($email, $this->user(), 'AIYA 测试站'));
    }

    public function testNewUserAdminNoticeKeepsTheFactsDropsTheLinks(): void
    {
        $email = ['to' => 'admin@example.test', 'subject' => 's', 'message' => 'native', 'headers' => []];

        $out = $this->rewrites->newUserEmailAdmin($email, $this->user(), 'AIYA 测试站');

        self::assertSame('[AIYA 测试站] New user registration', $out['subject']);
        self::assertStringContainsString('uuid-seven', (string) $out['message']);
        self::assertStringContainsString('seven@example.test', (string) $out['message']);
        self::assertStringNotContainsString('wp-login.php', (string) $out['message'], 'no link may fall back to a WP surface');
    }

    public function testPasswordChangedNoticeKeepsTheCallerSprint(): void
    {
        $email = ['to' => 'seven@example.test', 'subject' => 'native', 'message' => 'native', 'headers' => []];

        $out = $this->rewrites->passwordChanged($email, ['user_email' => 'seven@example.test'], []);

        self::assertSame('[%s] Password changed', $out['subject'], 'the caller appends the blog name');
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, (string) $out['message']);
        self::assertStringContainsString('href="https://front.test/reset-password"', (string) $out['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $out['headers']);
    }

    public function testEmailChangedNoticeNamesOldAndNew(): void
    {
        $email = ['to' => 'old@example.test', 'subject' => 'native', 'message' => 'native', 'headers' => []];

        $out = $this->rewrites->emailChanged($email, ['user_email' => 'old@example.test'], ['user_email' => 'new@example.test']);

        self::assertSame('[%s] Your email address was changed', $out['subject']);
        self::assertStringContainsString('old@example.test', (string) $out['message']);
        self::assertStringContainsString('new@example.test', (string) $out['message']);
        self::assertStringContainsString('href="https://front.test/reset-password"', (string) $out['message']);
    }
}
