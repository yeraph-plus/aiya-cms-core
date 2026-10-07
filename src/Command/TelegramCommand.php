<?php

declare(strict_types=1);

namespace Aiya\Core\Command;

use Aiya\Core\Api\Rest\TelegramWebhookController;
use Aiya\Core\Domain\Telegram\TelegramBot;
use Aiya\Core\Domain\Telegram\TelegramSettings;
use Aiya\Core\Domain\Telegram\UpdateProcessor;
use Aiya\Infra\Telegram\Client;
use Aiya\Infra\Telegram\Error;

/**
 * WP-CLI: `wp aiya telegram <poll|set-webhook|delete-webhook|send-test>`
 *
 * The operator side of the Telegram integration. `poll` is the
 * development intake — the long-poll loop standing in for the public
 * HTTPS webhook a local host cannot offer; the two webhook verbs move the
 * platform-side registration; `send-test` proves the token and the push
 * target without touching any route logic.
 */
final class TelegramCommand
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('aiya telegram poll', [self::class, 'poll']);
        \WP_CLI::add_command('aiya telegram set-webhook', [self::class, 'set_webhook']);
        \WP_CLI::add_command('aiya telegram delete-webhook', [self::class, 'delete_webhook']);
        \WP_CLI::add_command('aiya telegram send-test', [self::class, 'send_test']);
    }

    /**
     * Long-poll intake: updates are routed through the same processor the
     * webhook uses, so a local run exercises the full whitelist posture.
     * The offset persists across runs; Ctrl+C stops the loop.
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public static function poll(array $args, array $assocArgs): void
    {
        $client = self::requireClient();
        $offset = (int) get_option('aiya_core_tg_poll_offset', 0);
        \WP_CLI::log('Long-polling Telegram; Ctrl+C stops the loop.');

        $processor = new UpdateProcessor();
        // The operator loop: Ctrl+C is the stop, a platform rejection is
        // the loud stop, everything else retries on schedule.
        // @phpstan-ignore-next-line while.alwaysTrue (the loop is the process)
        while (true) {
            $updates = $client->getUpdates([
                'offset' => $offset,
                'timeout' => 25,
                'allowed_updates' => ['message', 'channel_post', 'edited_channel_post'],
            ]);
            if ($updates instanceof Error) {
                TelegramBot::report('poll', $updates);
                if ($updates->code === Error::REJECTED) {
                    // A platform rejection does not heal by retrying — a
                    // 409 (webhook still active) is the usual cause. Stop
                    // loudly instead of spinning.
                    \WP_CLI::error('getUpdates rejected: ' . $updates->message);
                }
                \WP_CLI::warning('Poll failed (' . $updates->code . '), retrying in 5s: ' . $updates->message);
                sleep(5);
                continue;
            }

            foreach ($updates as $update) {
                if (!is_array($update)) {
                    continue;
                }
                $updateId = $update['update_id'] ?? null;
                if (is_int($updateId)) {
                    $offset = max($offset, $updateId + 1);
                }
                \WP_CLI::log(sprintf('update %s: %s', is_int($updateId) ? (string) $updateId : '?', $processor->process($update)));
            }
            if ($offset !== (int) get_option('aiya_core_tg_poll_offset', 0)) {
                update_option('aiya_core_tg_poll_offset', $offset, false);
            }
        }
    }

    /**
     * Registers the webhook on the platform: this REST URL, the stored (or
     * freshly minted) secret token, and the update whitelist the routes
     * consume. Reports the URL so it can be checked against the host the
     * platform will actually reach.
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public static function set_webhook(array $args, array $assocArgs): void
    {
        $secret = TelegramSettings::webhookSecret();
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            TelegramSettings::storeSecret($secret);
            \WP_CLI::log('Generated a new webhook secret token.');
        }

        $client = self::requireClient();
        $url = rest_url(TelegramWebhookController::API_NAMESPACE . '/webhook');
        $result = $client->setWebhook([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'channel_post', 'edited_channel_post'],
            'drop_pending_updates' => true,
        ]);
        if ($result instanceof Error) {
            TelegramBot::report('set-webhook', $result);
            \WP_CLI::error('setWebhook failed: ' . $result->message);

            return;
        }

        \WP_CLI::success('Webhook registered: ' . $url);
    }

    /**
     * Tears the webhook registration down (the poll intake's platform-side
     * twin) and drops the pending updates so a later re-registration does
     * not replay old traffic.
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public static function delete_webhook(array $args, array $assocArgs): void
    {
        $client = self::requireClient();
        $result = $client->deleteWebhook(['drop_pending_updates' => true]);
        if ($result instanceof Error) {
            TelegramBot::report('delete-webhook', $result);
            \WP_CLI::error('deleteWebhook failed: ' . $result->message);

            return;
        }

        \WP_CLI::success('Webhook removed; pending updates dropped.');
    }

    /**
     * Proves the token and the push target with one real message. The
     * fastest whole-chain check after configuring a fresh bot.
     *
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public static function send_test(array $args, array $assocArgs): void
    {
        $chat = TelegramSettings::pushChatId();
        if ($chat === '') {
            \WP_CLI::error('No push target configured — fill it in on the Telegram Bot settings page.');

            return;
        }

        $client = self::requireClient();
        $result = $client->sendMessage($chat, 'AIYA Telegram link test — ' . current_time('mysql'));
        if ($result instanceof Error) {
            TelegramBot::report('send-test', $result);
            \WP_CLI::error('sendMessage failed (' . $result->code . '): ' . $result->message);

            return;
        }

        $messageId = $result['message_id'] ?? 0;
        \WP_CLI::success('Delivered (message_id ' . (is_int($messageId) ? (string) $messageId : '?') . ').');
    }

    /**
     * The configured client, or the CLI's loud stop. WP_CLI::error ends
     * the process by itself; the fallback throw exists only so the static
     * flow types stay honest.
     */
    private static function requireClient(): Client
    {
        $client = TelegramBot::client();
        if ($client === null) {
            \WP_CLI::error('No bot token configured — fill it in on the Telegram Bot settings page.');
            exit(1);
        }

        return $client;
    }
}
