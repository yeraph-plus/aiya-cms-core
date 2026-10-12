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
 * The button carries the intent its label promised — connect, unbind, or a
 * read-only probe. Both write intents are idempotent: connect leaves an
 * already-ours slot alone, unbind leaves an empty one alone. A label that
 * went stale therefore can never tear down a live registration. The verdict
 * travels as a rendered page notice — the same Ui::notice banner the
 * settings page prints its own save feedback with — and the resulting
 * registration is recorded so the next render labels the button from it.
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
            $this->answer(false, __('You are not allowed to manage the Telegram webhook.', 'aiya-core'), false, 403);

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
                : __('No bot token is configured.', 'aiya-core'), false);

            return;
        }

        $expected = TelegramWebhookController::url();
        $current = is_string($info['url'] ?? null) ? $info['url'] : '';
        $bound = $current === $expected;

        $intent = isset($_POST['intent'])
            ? sanitize_key((string) wp_unslash($_POST['intent']))
            : '';

        if ($intent === 'probe') {
            // Read-only: the page asks what the label should say.
            $this->answer(true, '', $bound);

            return;
        }

        if ($intent === '') {
            // A caller that names no intent keeps the original flip.
            $intent = $current === '' ? 'connect' : 'disconnect';
        }

        if ($intent === 'connect') {
            if ($bound) {
                $this->answer(true, __('Bot connected.', 'aiya-core'), true);

                return;
            }

            $error = $registrar->register($expected);
            TelegramSettings::storeWebhookUrl($error === null ? $expected : $current);
            $this->answer(
                $error === null,
                $error === null
                    ? __('Bot connected.', 'aiya-core')
                    : sprintf(
                        /* translators: %s: the platform's error message. */
                        __('Connect failed: %s', 'aiya-core'),
                        $error->message
                    ),
                $error === null ? true : $bound
            );

            return;
        }

        if ($current === '') {
            $this->answer(true, __('Bot disconnected.', 'aiya-core'), false);

            return;
        }

        $error = $registrar->unregister();
        TelegramSettings::storeWebhookUrl($error === null ? '' : $current);
        $this->answer(
            $error === null,
            $error === null
                ? __('Bot disconnected.', 'aiya-core')
                : sprintf(
                    /* translators: %s: the platform's error message. */
                    __('Disconnect failed: %s', 'aiya-core'),
                    $error->message
                ),
            false
        );
    }

    /**
     * The one way out: the verdict is the page notice the script places,
     * plus the boolean, plus the registration state the label tracks. A
     * probe sends no text and so places no notice.
     */
    private function answer(bool $ok, string $text, bool $bound, int $status = 200): void
    {
        $notice = '';
        if ($text !== '') {
            ob_start();
            Ui::notice($text, ['variant' => $ok ? 'success' : 'error']);
            $notice = (string) ob_get_clean();
        }

        $envelope = ['ok' => $ok, 'notice' => $notice, 'bound' => $bound];

        if ($ok) {
            wp_send_json_success($envelope, $status);
        }

        wp_send_json_error($envelope, $status);
    }
}
