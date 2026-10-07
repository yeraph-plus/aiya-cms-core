<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Error;

/**
 * The support-relay orchestration: a visitor message stores on the web
 * first (the source of truth) and then lands in the owner chat as the
 * bot's own text, prefixed with the sender's identity; the stored binding
 * of that copy is what routes an owner reply back to the right session.
 * The owner side speaks replies only — a bare owner message has no
 * destination — and media (stickers, photos) are not relayed in this
 * iteration. A failed delivery reports through the funnel and leaves the
 * row web-visible but unbound (a retry schedule is a registered later
 * item, the pusher's backoff shape reused when it matters).
 *
 * Not final on purpose: the update-funnel tests ride a recording
 * subclass.
 */
class Relay
{
    public const BODY_MAX_CHARS = 2000;

    public function __construct(private readonly ChatStore $store = new ChatStore())
    {
    }

    /**
     * @return array<string, mixed> The stored visitor row.
     */
    public function submitVisitorMessage(int $userId, string $body): array
    {
        $row = $this->store->post($userId, $this->clean($body));
        $this->deliver($row, $userId);

        return $row;
    }

    /**
     * @param array<string, mixed> $message One owner-chat message payload.
     */
    public function onOwnerMessage(int $chatId, array $message): void
    {
        $replyTo = $message['reply_to_message']['message_id'] ?? null;
        if (!is_int($replyTo)) {
            return; // Not a reply: nothing to route it with.
        }
        $text = $this->clean(is_string($message['text'] ?? null) ? $message['text'] : '');
        if ($text === '') {
            return; // Media-only replies are not relayed in this iteration.
        }

        $this->store->storeOwnerReply($chatId, $replyTo, $text);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function deliver(array $row, int $userId): void
    {
        $client = TelegramBot::client();
        if ($client === null) {
            return; // Unconfigured: the web thread is the source of truth.
        }
        $chat = TelegramSettings::ownerChatId();
        if ($chat === 0) {
            return;
        }

        $name = trim((string) get_the_author_meta('display_name', $userId));
        $text = sprintf("From %s (#%d):\n%s", $name !== '' ? $name : 'user', $userId, (string) $row['body']);

        $result = $client->sendMessage($chat, $text);
        if ($result instanceof Error) {
            TelegramBot::report('relay', $result, ['session' => $row['session_id'], 'row' => $row['id']]);

            return;
        }

        $messageId = $result['message_id'] ?? 0;
        if (is_int($messageId) && $messageId > 0) {
            $this->store->bindTelegram((int) $row['id'], $chat, $messageId);
        }
    }

    private function clean(string $body): string
    {
        return mb_substr(trim(wp_strip_all_tags($body)), 0, self::BODY_MAX_CHARS);
    }
}
