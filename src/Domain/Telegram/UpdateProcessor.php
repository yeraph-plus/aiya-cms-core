<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

/**
 * The one update funnel both intakes ride (the webhook controller and
 * the site-side poll intake): an Update is routed to the domain route
 * that owns its chat, and the chat-id whitelists are enforced here —
 * anything not addressed to a configured chat drops before any route
 * logic sees it. That gate is the bot-has-no-user-features stance turned
 * into code: the bot answers exactly the chats the site configured,
 * nothing else. Every update first registers its source chat in the
 * discovery registry, and the /status operator command rides ahead of
 * the whitelists (the bootstrap exception).
 *
 * Verdicts are strings so intake logging (and the tests) can assert what
 * happened without side effects.
 */
final class UpdateProcessor
{
    public const ROUTED = 'routed';
    public const DROPPED = 'dropped';
    public const PROBED = 'probed';

    private FeedIngestor $feed;

    private Relay $relay;

    private StatusCommand $commands;

    public function __construct(?FeedIngestor $feed = null, ?Relay $relay = null, ?StatusCommand $commands = null)
    {
        $this->feed = $feed ?? new FeedIngestor();
        $this->relay = $relay ?? new Relay();
        $this->commands = $commands ?? new StatusCommand();
    }

    /**
     * @param array<string, mixed> $update One platform Update object.
     */
    public function process(array $update): string
    {
        // Discovery rides ahead of every verdict: the funnel registers
        // each update's source chat (including the ones the whitelists
        // drop and the bot-membership pushes), the closest the platform
        // allows to a chat directory.
        $this->discover($update);

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
        // Fresh asks only: editing an answered command post is not a new ask.
        if (!$isEdit && $this->commandAnswers($chatId, $post, true)) {
            return self::PROBED;
        }
        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $username = is_string($chat['username'] ?? null) ? $chat['username'] : null;
        if ($chatId === 0 || !TelegramSettings::mirrorEnabled() || !TelegramSettings::mirrorAccepts($chatId, $username)) {
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
        if ($this->commandAnswers($chatId, $message, false)) {
            return self::PROBED;
        }
        if ($chatId === 0 || $chatId !== TelegramSettings::ownerChatId() || !TelegramSettings::relayEnabled()) {
            return self::DROPPED;
        }

        // The relay routes replies to relayed visitor messages back to the
        // web; a bare owner message has no destination and is a no-op.
        $this->relay->onOwnerMessage($chatId, $message);

        return self::ROUTED;
    }

    /**
     * The operator command rides ahead of the whitelists: filling the ids
     * is the very thing the whitelists wait on, so /status is the one
     * bootstrap exception — always on, replying only into the asking
     * chat itself.
     *
     * @param array<string, mixed> $message
     */
    private function commandAnswers(int $chatId, array $message, bool $isChannel): bool
    {
        if ($chatId === 0 || !StatusCommand::matches($message)) {
            return false;
        }

        $this->commands->answer($chatId, $isChannel, $message);

        return true;
    }

    /**
     * Registers every update's source chat, and the bot-membership
     * pushes' verdict on the bot's own status there.
     *
     * @param array<string, mixed> $update
     */
    private function discover(array $update): void
    {
        $member = $update['my_chat_member'] ?? null;
        if (is_array($member)) {
            $chat = is_array($member['chat'] ?? null) ? $member['chat'] : [];
            KnownChats::observe($chat);
            $new = is_array($member['new_chat_member'] ?? null) ? $member['new_chat_member'] : [];
            $status = $new['status'] ?? null;
            $chatId = is_int($chat['id'] ?? null) ? (int) $chat['id'] : 0;
            if ($chatId !== 0 && is_string($status) && $status !== '') {
                KnownChats::recordStatus($chatId, $status);
            }

            return;
        }

        foreach (['channel_post', 'edited_channel_post', 'message'] as $key) {
            $payload = $update[$key] ?? null;
            if (is_array($payload)) {
                KnownChats::observe(is_array($payload['chat'] ?? null) ? $payload['chat'] : []);
                return;
            }
        }
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
