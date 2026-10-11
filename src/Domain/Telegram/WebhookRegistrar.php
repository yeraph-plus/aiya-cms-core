<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Client;
use Aiya\Infra\Telegram\Error;

/**
 * The platform-side webhook registration, in one place: the update
 * whitelist the intake consumes, the secret token the controller compares
 * pushes against, and the two verbs that move the registration. The
 * operator CLI owned this outright until the settings page needed the same
 * moves, which is why both now ride this service.
 *
 * The client is injectable so the wire stays out of the domain's tests;
 * left out, it is the configured bot's client.
 */
final class WebhookRegistrar
{
    /**
     * The update types the intake consumes. Explicit on purpose: once any
     * explicit list was set, the platform reuses it for param-less polls,
     * and the discovery registry needs my_chat_member, which the route
     * whitelists never consume.
     *
     * @var list<string>
     */
    public const ALLOWED_UPDATES = ['message', 'channel_post', 'edited_channel_post', 'my_chat_member'];

    public function __construct(private ?Client $client = null)
    {
    }

    /**
     * The platform's own record of this bot's webhook, read straight
     * through. Null when no bot token is configured.
     *
     * @return array<int|string, mixed>|Error|null
     */
    public function info(): array|Error|null
    {
        $client = $this->resolveClient();
        if ($client === null) {
            return null;
        }

        $info = $client->getWebhookInfo();
        if ($info instanceof Error) {
            TelegramBot::report('webhook-info', $info);
        }

        return $info;
    }

    /**
     * Registers $url with the platform, minting the secret token on first
     * use. $dropPending is the caller's call: the CLI drops the backlog,
     * the settings button leaves it for the intake to consume.
     *
     * @return Error|null Null on success.
     */
    public function register(string $url, bool $dropPending = false): ?Error
    {
        $client = $this->resolveClient();
        if ($client === null) {
            return new Error(Error::UNREACHABLE, 'No bot token is configured.');
        }

        $secret = TelegramSettings::webhookSecret();
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            TelegramSettings::storeSecret($secret);
        }

        $result = $client->setWebhook([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'drop_pending_updates' => $dropPending,
        ]);

        return $result instanceof Error ? $this->failed('set-webhook', $result) : null;
    }

    /**
     * Tears the registration down — the poll intake's platform-side twin.
     *
     * @return Error|null Null on success.
     */
    public function unregister(bool $dropPending = false): ?Error
    {
        $client = $this->resolveClient();
        if ($client === null) {
            return new Error(Error::UNREACHABLE, 'No bot token is configured.');
        }

        $result = $client->deleteWebhook(['drop_pending_updates' => $dropPending]);

        return $result instanceof Error ? $this->failed('delete-webhook', $result) : null;
    }

    private function resolveClient(): ?Client
    {
        return $this->client ?? TelegramBot::client();
    }

    /** The domain's one error funnel, so a failed registration is never silent. */
    private function failed(string $route, Error $error): Error
    {
        TelegramBot::report($route, $error);

        return $error;
    }
}
