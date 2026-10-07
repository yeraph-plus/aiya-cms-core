<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Client;
use Aiya\Infra\Telegram\Error;

/**
 * The bot's one client factory and the one error-reporting funnel. Every
 * caller that talks to the platform builds its client here, and every
 * failure lands on the `aiya_core_telegram_error` action —
 * (route, package error code, message, context) — for Operations and the
 * log to read, the same shape the FileServe adapters report through.
 */
final class TelegramBot
{
    public const ERROR_ACTION = 'aiya_core_telegram_error';

    /** The client from the stored settings, or null when unconfigured. */
    public static function client(): ?Client
    {
        $token = TelegramSettings::botToken();
        if ($token === '') {
            return null;
        }

        return new Client($token, TelegramTransport::make());
    }

    /**
     * @param string                     $route   The reporting operation ('poll',
     *                                            'set-webhook', 'send-test', 'push', …).
     * @param array<string, mixed>       $context Whatever the caller can add
     *                                            (post id, chat id, verdict).
     */
    public static function report(string $route, Error $error, array $context = []): void
    {
        do_action(self::ERROR_ACTION, $route, $error->code, $error->message, $context);
    }
}
