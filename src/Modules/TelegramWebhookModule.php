<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Admin\Ui;
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
 * torn down. The verdict travels as a rendered page notice — the same
 * Ui::notice banner the settings page prints its own save feedback with —
 * so the caller only has to place it.
 */
final class TelegramWebhookModule implements Module
{
    public function __construct(private ?WebhookRegistrar $registrar = null)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . TelegramSettings::WEBHOOK_ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            $this->answer(false, __('You are not allowed to manage the Telegram webhook.', 'aiya-core'), 403);

            return;
        }
        check_ajax_referer(TelegramSettings::WEBHOOK_ACTION, 'nonce');

        $registrar = $this->registrar ?? new WebhookRegistrar();
        $info = $registrar->info();
        if (!is_array($info)) {
            $this->answer(false, $info instanceof Error
                ? sprintf(
                    /* translators: %s: the platform's error message. */
                    __('Bot check failed: %s', 'aiya-core'),
                    $info->message
                )
                : __('No bot token is configured.', 'aiya-core'));

            return;
        }

        $current = is_string($info['url'] ?? null) ? $info['url'] : '';

        if ($current === '') {
            $error = $registrar->register(TelegramWebhookController::url());
            $text = $error === null
                ? __('Bot connected.', 'aiya-core')
                : sprintf(
                    /* translators: %s: the platform's error message. */
                    __('Connect failed: %s', 'aiya-core'),
                    $error->message
                );
        } else {
            $error = $registrar->unregister();
            $text = $error === null
                ? __('Bot disconnected.', 'aiya-core')
                : sprintf(
                    /* translators: %s: the platform's error message. */
                    __('Disconnect failed: %s', 'aiya-core'),
                    $error->message
                );
        }

        $this->answer($error === null, $text);
    }

    /**
     * The one way out: the verdict is the page notice the script places,
     * plus the boolean, so nothing downstream phrases an outcome itself.
     */
    private function answer(bool $ok, string $text, int $status = 200): void
    {
        ob_start();
        Ui::notice($text, ['variant' => $ok ? 'success' : 'error']);
        $notice = (string) ob_get_clean();

        $envelope = ['ok' => $ok, 'notice' => $notice];

        if ($ok) {
            wp_send_json_success($envelope, $status);
        }

        wp_send_json_error($envelope, $status);
    }
}
