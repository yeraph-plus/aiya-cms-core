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
}
