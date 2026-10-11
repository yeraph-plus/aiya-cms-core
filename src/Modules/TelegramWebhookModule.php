<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Api\Rest\TelegramWebhookController;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Telegram\TelegramSettings;
use Aiya\Core\Domain\Telegram\WebhookRegistrar;
use Aiya\Infra\Telegram\Error;

/**
 * The admin-ajax face of the webhook registration: the one button the
 * Telegram settings page hangs off its token row. Transport knowledge —
 * the capability and nonce gates, the JSON envelope, reading the request —
 * lives here; the moves themselves are the domain's WebhookRegistrar, so
 * this endpoint and the CLI register through the same code.
 *
 * One click probes the platform (a live token, and whatever currently
 * holds the bot's single webhook slot) and then flips the registration: an
 * empty slot is registered with this site's REST URL, an occupied one is
 * torn down. The answer carries both the verdict and the fresh state.
 */
final class TelegramWebhookModule implements Module
{
    public function register(): void
    {
        add_action('wp_ajax_' . TelegramSettings::WEBHOOK_ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to manage the Telegram webhook.', 'aiya-core')], 403);
        }
        check_ajax_referer(TelegramSettings::WEBHOOK_ACTION, 'nonce');

        $registrar = new WebhookRegistrar();
        $info = $registrar->info();
        if (!is_array($info)) {
            wp_send_json_error([
                'message' => $info instanceof Error
                    ? sprintf(
                        /* translators: 1: package error code, 2: the platform's message. */
                        __('getWebhookInfo failed (%1$s): %2$s', 'aiya-core'),
                        $info->code,
                        $info->message
                    )
                    : __('No bot token is configured.', 'aiya-core'),
            ]);
        }

        $expected = TelegramWebhookController::url();
        $current = is_string($info['url'] ?? null) ? $info['url'] : '';

        if ($current === '') {
            $error = $registrar->register($expected);
            $action = 'register';
            $message = $error === null
                ? sprintf(
                    /* translators: %s: the webhook URL now registered. */
                    __('Webhook registered: %s', 'aiya-core'),
                    $expected
                )
                : sprintf(
                    /* translators: %s: the platform's error message. */
                    __('setWebhook failed: %s', 'aiya-core'),
                    $error->message
                );
        } else {
            $error = $registrar->unregister();
            $action = 'unregister';
            $message = $error === null
                ? sprintf(
                    /* translators: %s: the webhook URL that was removed. */
                    __('Webhook removed (was %s). The pending updates stay on the platform until an intake reads them.', 'aiya-core'),
                    $current
                )
                : sprintf(
                    /* translators: %s: the platform's error message. */
                    __('deleteWebhook failed: %s', 'aiya-core'),
                    $error->message
                );
        }

        wp_send_json_success([
            'action' => $action,
            'ok' => $error === null,
            'message' => $message,
            'url_before' => $current,
            'url_expected' => $expected,
            'pending' => is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : null,
            'last_error' => is_string($info['last_error_message'] ?? null) ? $info['last_error_message'] : '',
        ]);
    }
}
