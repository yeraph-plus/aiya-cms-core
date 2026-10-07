<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

/**
 * The one update funnel both intakes ride (the webhook controller and the
 * CLI long poll): an Update is routed to the domain route that owns its
 * chat, and the chat-id whitelists are enforced here — anything not
 * addressed to a configured chat drops before any route logic sees it.
 * That gate is the bot-has-no-user-features stance turned into code: the
 * bot answers exactly the chats the site configured, nothing else.
 *
 * Verdicts are strings so intake logging (and the tests) can assert what
 * happened without side effects.
 */
final class UpdateProcessor
{
    public const ROUTED = 'routed';
    public const DROPPED = 'dropped';

    /**
     * @param array<string, mixed> $update One platform Update object.
     */
    public function process(array $update): string
    {
        $channelPost = $update['channel_post'] ?? null;
        if (is_array($channelPost)) {
            return $this->channelPost(self::chatId($channelPost));
        }

        $editedChannelPost = $update['edited_channel_post'] ?? null;
        if (is_array($editedChannelPost)) {
            // Edits ride the same gate; the route's rewrite lands with the
            // feed ingestor.
            return $this->channelPost(self::chatId($editedChannelPost));
        }

        $message = $update['message'] ?? null;
        if (is_array($message)) {
            return $this->ownerMessage(self::chatId($message));
        }

        return self::DROPPED; // unsupported update types (callback_query, my_chat_member, …)
    }

    private function channelPost(int $chatId): string
    {
        if ($chatId === 0 || !TelegramSettings::mirrorEnabled() || !in_array($chatId, TelegramSettings::sourceChatIds(), true)) {
            return self::DROPPED;
        }

        // The feed ingestor (instant-public rows, edited rewrites, image
        // transfer) takes the payload from here in the mirror batch.
        return self::ROUTED;
    }

    private function ownerMessage(int $chatId): string
    {
        if ($chatId === 0 || $chatId !== TelegramSettings::ownerChatId() || !TelegramSettings::relayEnabled()) {
            return self::DROPPED;
        }

        // The relay takes the message from here in the support batch: only
        // replies to relayed visitor messages flow back to the web.
        return self::ROUTED;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function chatId(array $message): int
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $id = $chat['id'] ?? null;

        return is_int($id) ? $id : 0;
    }
}
