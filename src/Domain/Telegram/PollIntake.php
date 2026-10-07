<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Error;

/**
 * The site-side poll intake: one minute bucket of short polling that
 * rides every update through the same funnel the webhook serves. The
 * domain owns the engine; the scheduler is a workspace mu-plugin
 * (aiya-telegram-dev-intake.php, a development-only dependency) — on a
 * production host that file does not exist, nothing schedules this hook,
 * and the platform pushes the webhook directly. The shared offset
 * (`aiya_core_tg_poll_offset`) also serves the CLI long poll — two
 * concurrent intakes make the platform answer 409 to the second, which
 * both sides surface and survive; the storage-side unique keys keep a
 * raced double-delivery idempotent regardless.
 */
class PollIntake
{
    public const CRON_HOOK = 'aiya_core_tg_poll_cron';

    private UpdateProcessor $processor;

    public function __construct(?UpdateProcessor $processor = null)
    {
        $this->processor = $processor ?? new UpdateProcessor();
    }

    /**
     * One cron tick: a gate, one short poll, every update through the
     * funnel, the offset persisted only when it moved.
     */
    public function tick(): void
    {
        $client = TelegramBot::client();
        if ($client === null) {
            return;
        }

        $offset = (int) get_option('aiya_core_tg_poll_offset', 0);
        $updates = $client->getUpdates([
            'offset' => $offset,
            'timeout' => 0,
            'limit' => 10,
            // Explicit on purpose: once any explicit list was set (a
            // webhook, the CLI poll), the platform reuses that list for
            // param-less polls — and the discovery registry needs
            // my_chat_member, which the route whitelists never consume.
            'allowed_updates' => ['message', 'channel_post', 'edited_channel_post', 'my_chat_member'],
        ]);
        if ($updates instanceof Error) {
            // A 409 here means another intake (a webhook registration or
            // the CLI long poll) holds the platform's single slot; the
            // next tick tries again and the funnel says so.
            TelegramBot::report('poll-cron', $updates);

            return;
        }

        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $updateId = $update['update_id'] ?? null;
            if (is_int($updateId)) {
                $offset = max($offset, $updateId + 1);
            }
            $this->processor->process($update);
        }
        if ($offset !== (int) get_option('aiya_core_tg_poll_offset', 0)) {
            update_option('aiya_core_tg_poll_offset', $offset, false);
        }
    }
}
