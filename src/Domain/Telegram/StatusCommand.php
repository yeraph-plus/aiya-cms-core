<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Client;
use Aiya\Infra\Telegram\Error;

/**
 * The private-chat operator command, the funnel's one bootstrap
 * exception: `/status` answers the asking chat's id plus, in private
 * chats, the configuration state and the chats the bot actually holds
 * administrator rights in — discovered from traffic and verified through
 * getChatMember, the closest the platform allows to a chat directory.
 * Everything answers in the asking chat itself; the config summary only
 * ever lands in private chats, never groups. There is no `/id` alias.
 *
 * Not final on purpose: the update-funnel tests ride a recording
 * subclass.
 */
class StatusCommand
{
    /**
     * @param array<string, mixed> $message
     */
    public static function matches(array $message): bool
    {
        $text = $message['text'] ?? null;
        if (!is_string($text)) {
            return false;
        }

        // In private chats the command arrives bare; group-scope commands
        // carry the bot username suffix — both are the same ask.
        return preg_match('#^/status(@[A-Za-z0-9_]+)?\s*$#', trim($text)) === 1;
    }

    /**
     * @param array<string, mixed> $message The command's message payload.
     */
    public function answer(int $chatId, bool $isChannel, array $message): void
    {
        $client = TelegramBot::client();
        if ($client === null) {
            return;
        }

        if (!$isChannel && $this->isPrivate($message)) {
            $reply = $this->statusReport($chatId, $client);
        } else {
            $reply = sprintf('%s: %d', $isChannel ? 'channel id' : 'chat id', $chatId);
        }

        $result = $client->sendMessage($chatId, $reply);
        if ($result instanceof Error) {
            TelegramBot::report('status', $result, ['chat_id' => $chatId]);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function isPrivate(array $message): bool
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        return ($chat['type'] ?? '') === 'private';
    }

    private function statusReport(int $chatId, Client $client): string
    {
        $this->verifyStale($client);

        $lines = ['AIYA Telegram status', '', 'this chat: ' . $chatId, ''];

        $config = [];
        if (TelegramSettings::pushEnabled() && TelegramSettings::pushChatId() !== '') {
            $config[] = 'push -> ' . TelegramSettings::pushChatId();
        }
        if (TelegramSettings::mirrorEnabled()) {
            $config[] = 'mirror -> ' . count(TelegramSettings::mirrorChannels()) . ' source(s)';
        }
        if (TelegramSettings::relayEnabled()) {
            $owner = TelegramSettings::ownerChatId();
            $config[] = 'relay -> ' . ($owner === $chatId ? 'on (this chat)' : 'on');
        }
        $lines[] = $config === []
            ? 'configured: nothing yet — fill the Telegram Bot settings page'
            : 'configured: ' . implode(' | ', $config);
        $lines[] = '';

        $administered = [];
        foreach (KnownChats::all() as $chat) {
            if (($chat['status'] ?? '') === 'administrator') {
                $administered[] = $chat;
            }
        }
        if ($administered === []) {
            $lines[] = 'no administered chats discovered yet — add me to a channel as admin and post something';
        } else {
            $lines[] = 'chats I administer (discovered from traffic):';
            foreach ($administered as $chat) {
                $lines[] = sprintf(
                    '- %d %s%s [%s]',
                    (int) ($chat['id'] ?? 0),
                    is_string($chat['title'] ?? null) && $chat['title'] !== '' ? $chat['title'] : '(untitled)',
                    is_string($chat['username'] ?? null) && $chat['username'] !== '' ? ' @' . $chat['username'] : '',
                    'administrator'
                );
            }
            $lines[] = 'put the ids into the mirror sources / push target';
        }

        return implode("\n", $lines);
    }

    /** Chats whose bot status is missing or older than a week get re-asked. */
    private function verifyStale(Client $client): void
    {
        $me = $client->getMe();
        if ($me instanceof Error || !is_int($me['id'] ?? null)) {
            return;
        }

        foreach (KnownChats::all() as $chat) {
            $chatId = (int) ($chat['id'] ?? 0);
            if ($chatId === 0 || !KnownChats::needsVerification($chatId)) {
                continue;
            }
            $member = $client->getChatMember($chatId, (int) $me['id']);
            if ($member instanceof Error) {
                TelegramBot::report('status', $member, ['chat_id' => $chatId]);
                continue;
            }
            $status = $member['status'] ?? null;
            if (is_string($status) && $status !== '') {
                KnownChats::recordStatus($chatId, $status);
            }
        }
    }
}
