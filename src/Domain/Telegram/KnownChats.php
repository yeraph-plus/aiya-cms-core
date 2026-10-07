<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

/**
 * The discovered-chats registry: the platform offers no "list the chats
 * I'm in" API, so the funnel registers every chat its updates name —
 * including those the whitelists drop, since discovery must see
 * not-yet-configured chats. The bot's own status (administrator, …)
 * comes from two sources: `my_chat_member` pushes and a lazy
 * getChatMember verification the /status command triggers for stale
 * entries. An option on purpose — a private site's chat count is a
 * handful, and the aiya_core_ prefix carries the uninstall sweep.
 */
final class KnownChats
{
    private const OPTION = 'aiya_core_tg_known_chats';

    private const VERIFY_INTERVAL = 7 * DAY_IN_SECONDS;

    /** @return array<string, array<string, mixed>> Chat id keyed rows, most recent first. */
    public static function all(): array
    {
        $chats = get_option(self::OPTION, []);
        if (!is_array($chats)) {
            return [];
        }
        uasort($chats, static fn (array $a, array $b): int => (int) ($b['last_seen'] ?? 0) <=> (int) ($a['last_seen'] ?? 0));

        return $chats;
    }

    /** Upserts one chat's identity from a payload chat object.
     *
     * @param array<string, mixed> $chat
     */
    public static function observe(array $chat): void
    {
        $id = $chat['id'] ?? null;
        if (!is_int($id) || $id === 0) {
            return;
        }

        $all = self::all();
        $key = (string) $id;
        $known = $all[$key] ?? [];
        $known = array_merge($known, [
            'id' => $id,
            'type' => is_string($chat['type'] ?? null) ? $chat['type'] : '',
            'title' => is_string($chat['title'] ?? null) ? trim($chat['title']) : '',
            'username' => is_string($chat['username'] ?? null) ? $chat['username'] : '',
            'last_seen' => time(),
        ]);
        if (!isset($known['first_seen'])) {
            $known['first_seen'] = time();
        }
        $all[$key] = $known;
        update_option(self::OPTION, $all, false);
    }

    /** Records the bot's own status in one known chat (administrator, member, left, …). */
    public static function recordStatus(int $chatId, string $status): void
    {
        $all = self::all();
        $key = (string) $chatId;
        if (!isset($all[$key]) || !is_array($all[$key])) {
            return;
        }

        $all[$key]['status'] = $status;
        $all[$key]['verified_at'] = time();
        update_option(self::OPTION, $all, false);
    }

    /** Whether the bot status of one known chat is missing or stale. */
    public static function needsVerification(int $chatId): bool
    {
        $known = self::all()[(string) $chatId] ?? null;
        if (!is_array($known)) {
            return false;
        }

        return time() - (int) ($known['verified_at'] ?? 0) > self::VERIFY_INTERVAL;
    }
}
