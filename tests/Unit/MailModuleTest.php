<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mail\MailModule;
use PHPUnit\Framework\TestCase;

final class MailModuleTest extends TestCase
{
    private MailModule $module;

    protected function setUp(): void
    {
        $this->module = new MailModule();
        $this->module->register();
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

    public function testRegistrationIsIdempotentEnoughToSurviveDoubleBoots(): void
    {
        // The composition root registers once per request; a second
        // registration still answers false, which is the only behaviour
        // the module promises.
        $this->module->register();

        self::assertFalse(apply_filters('notify_post_author', true, 1));
        self::assertFalse(apply_filters('notify_moderator', '1', 2));
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
        // is the thing that matters.
        self::assertFalse(has_action('after_password_reset', 'wp_password_change_notification'));
    }
}
