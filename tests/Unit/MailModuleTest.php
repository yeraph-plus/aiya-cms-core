<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mail\MailModule;
use Aiya\Core\Domain\Mail\MailTemplate;
use PHPUnit\Framework\TestCase;

final class MailModuleTest extends TestCase
{
    private MailModule $module;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_mails'] = [];
        $this->module = new MailModule();
        $this->module->register();
    }

    protected function tearDown(): void
    {
        // register() hooks live WP surfaces: a leaked wp_mail filter would
        // rebrand another file's mail flow (downstream suites read
        // __aiya_test_mails), so the registry leaves with each test.
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_mails'] = [];
    }

    public function testSilencesTheAuthorNotification(): void
    {
        // Core passes a bool here: (bool) comments_notify, or false for held comments.
        self::assertFalse(apply_filters('notify_post_author', true, 1));
        self::assertFalse(apply_filters('notify_post_author', false, 1));
    }

    public function testSilencesTheModeratorNotification(): void
    {
        // Core passes the raw moderation_notify option value here — a string
        // as stored, not a bool — and the gate must hold for every shape.
        self::assertFalse(apply_filters('notify_moderator', '1', 2));
        self::assertFalse(apply_filters('notify_moderator', '0', 2));
        self::assertFalse(apply_filters('notify_moderator', 1, 2));
    }

    public function testRegistrationWiresTheShellRewritesAndReceiptLegs(): void
    {
        // MailShellTest attaches its own shell by hand, so only this file
        // sees register() itself: a dropped registration line would leave
        // every other suite green while production mail ships unbranded.
        \wp_mail('reader@example.test', '主题', '正文');

        $mail = $GLOBALS['__aiya_test_mails'][0] ?? null;
        self::assertNotNull($mail, 'a registered module keeps wp_mail delivering');
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, (string) $mail['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $mail['headers']);
        self::assertNotSame([], $GLOBALS['__aiya_test_filters']['wp_mail'] ?? [], 'the shell rides the wp_mail args filter');
        self::assertNotSame([], $GLOBALS['__aiya_test_filters']['retrieve_password_message'] ?? [], 'the native reset mail rides the rewrites');
        self::assertNotFalse(has_action('aiya_core_membership_activated'), 'the membership receipt leg is hooked');

        // A second register() (double boot) must neither double-wrap the
        // message nor lose the veto gates — the composition root registers
        // once per request, and re-registration is the behaviour callers
        // fall back to.
        (new MailModule())->register();

        \wp_mail('again@example.test', '主题', '正文');
        $second = $GLOBALS['__aiya_test_mails'][1] ?? null;
        self::assertNotNull($second);
        self::assertSame(1, substr_count((string) $second['message'], MailTemplate::SHELL_MARKER), 'the marker stands the second shell down — no double wrap');

        self::assertFalse(apply_filters('notify_post_author', true, 1));
    }

    public function testSilencesTheNewRegistrationAdminNotice(): void
    {
        // 2026-10-03 ruling: the registration notice is pure admin noise —
        // the user's own branded welcome mail carries the reset link.
        self::assertFalse(apply_filters('wp_send_new_user_notification_to_admin', true, ['ID' => 7]));
        self::assertFalse(apply_filters('wp_send_new_user_notification_to_admin', true, $this->module, 7));
    }

    public function testUnhooksTheResetCompletedAdminNotice(): void
    {
        // 2026-10-03 ruling: removed from after_password_reset (the lightest
        // touch — no pluggable override), the user's branded security mail
        // is the thing that matters. Core's default-filters.php hooks the
        // callback on every real boot; the shim registry starts empty, so
        // the seed is what makes the assertion able to fail — without it
        // has_action() answers false no matter what register() does.
        \add_action('after_password_reset', 'wp_password_change_notification', 10);
        self::assertTrue(has_action('after_password_reset', 'wp_password_change_notification'));

        (new MailModule())->register();

        self::assertFalse(has_action('after_password_reset', 'wp_password_change_notification'));
    }
}
