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

    private FeedIngestor $feed;

    public function __construct(?FeedIngestor $feed = null)
    {
        $this->feed = $feed ?? new FeedIngestor();
    }

    /**
     * @param array<string, mixed> $update One platform Update object.
     */
    public function process(array $update): string
    {
        $channelPost = $update['channel_post'] ?? null;
        if (is_array($channelPost)) {
            return $this->channelPost(self::chatId($channelPost), $channelPost, false);
        }

        $editedChannelPost = $update['edited_channel_post'] ?? null;
        if (is_array($editedChannelPost)) {
            // An edit rides the same gate and rewrites its row.
            return $this->channelPost(self::chatId($editedChannelPost), $editedChannelPost, true);
        }

        $message = $update['message'] ?? null;
        if (is_array($message)) {
            return $this->ownerMessage(self::chatId($message), $message);
        }

        return self::DROPPED; // unsupported update types (callback_query, my_chat_member, …)
    }

    /**
     * @param array<string, mixed> $post
     */
    private function channelPost(int $chatId, array $post, bool $isEdit): string
    {
        if ($chatId === 0 || !TelegramSettings::mirrorEnabled() || !in_array($chatId, TelegramSettings::sourceChatIds(), true)) {
            return self::DROPPED;
        }

        $this->feed->ingest($chatId, $post, $isEdit);

        return self::ROUTED;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function ownerMessage(int $chatId, array $message): string
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
