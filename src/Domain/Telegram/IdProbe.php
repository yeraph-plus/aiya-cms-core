<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Error;

/**
 * The /id discovery probe: the bootstrap tool for filling the settings
 * page's chat ids. While the operator switch is on, a bare /id message —
 * private chat or channel post — gets the asking chat's own id sent back
 * into that same chat, ahead of the whitelists (which cannot be filled
 * before the ids are known). It echoes nothing but the asking chat's id;
 * switching the probe off restores the bot-silence stance.
 *
 * Not final on purpose: the update-funnel tests ride a recording
 * subclass.
 */
class IdProbe
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
        return preg_match('#^/id(@[A-Za-z0-9_]+)?\s*$#', trim($text)) === 1;
    }

    public function answer(int $chatId, bool $isChannel): void
    {
        $client = TelegramBot::client();
        if ($client === null) {
            return;
        }

        $result = $client->sendMessage($chatId, sprintf($isChannel ? 'channel id: %d' : 'chat id: %d', $chatId));
        if ($result instanceof Error) {
            TelegramBot::report('probe', $result, ['chat_id' => $chatId]);
        }
    }
}
