<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

/**
 * Typed reads over the Telegram settings page (one place owns the field
 * ids); every reader answers the field default on an unsaved option, so a
 * fresh install is inert by construction.
 */
final class TelegramSettings
{
    public const PAGE_SLUG = 'telegram';

    /**
     * The webhook secret is machine state, not a setting: minted by the
     * CLI's set-webhook and never rendered. A dedicated option (not a page
     * field) keeps the settings save pipeline — which replaces the page's
     * option wholesale with its registered fields — from ever wiping it.
     */
    public const SECRET_OPTION = 'aiya_core_tg_webhook_secret';

    public static function botToken(): string
    {
        return trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_bot_token', ''));
    }

    public static function webhookSecret(): string
    {
        return trim((string) get_option(self::SECRET_OPTION, ''));
    }

    public static function pushEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'tg_push_enabled', false);
    }

    /** Numeric chat id or @channelusername, passed to the API verbatim. */
    public static function pushChatId(): string
    {
        return trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_push_chat_id', ''));
    }

    /**
     * The push message body template: post-object placeholders on the
     * wire ({front}, {type}, {slug}, {id}, {title}, {excerpt}, {tags},
     * {categories}, {date}, {author}); links are the operator's own
     * composition from those — the backend keeps no front-end route
     * shapes. Empty restores the shipped two-line shape.
     */
    public static function pushTemplate(): string
    {
        $template = trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_push_template', ''));

        return $template !== '' ? $template : '{title}' . "\n\n" . '{excerpt}';
    }

    public static function mirrorEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'tg_mirror_enabled', false);
    }

    /**
     * The mirror's source rows as the repeater saves them, `chat`
     * normalized (leading @ stripped, usernames lowercased, numeric ids
     * kept as digit strings). A legacy string value (the old textarea,
     * ids one per line) reads as bare rows until the page saves the
     * repeater shape over it.
     *
     * @return list<array{title: string, chat: string, nsfw: bool}>
     */
    public static function mirrorChannels(): array
    {
        $raw = aiya_core_opt(self::PAGE_SLUG, 'tg_mirror_source_chat_ids', '');
        if (is_string($raw)) {
            // The legacy textarea carried numeric ids only; noise lines
            // drop exactly as they always did.
            $rows = [];
            $lines = preg_split('/[\s,;]+/u', trim($raw));
            foreach (is_array($lines) ? $lines : [] as $line) {
                $id = filter_var((string) $line, FILTER_VALIDATE_INT);
                if (is_int($id) && $id !== 0) {
                    $rows[] = ['title' => '', 'chat' => (string) $id, 'nsfw' => false];
                }
            }

            return $rows;
        }

        $rows = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $chat = self::normalizeChat((string) ($row['chat'] ?? ''));
            if ($chat === '') {
                continue;
            }
            $rows[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'chat' => $chat,
                'nsfw' => (bool) ($row['nsfw'] ?? false),
            ];
        }

        return $rows;
    }

    /** Rows speak numeric ids or @usernames; both normalize here. */
    private static function normalizeChat(string $chat): string
    {
        $chat = ltrim(trim($chat), '@');

        return $chat === '' ? '' : (is_numeric($chat) ? $chat : strtolower($chat));
    }

    /**
     * The numeric ids among the source rows — the shape the funnel's
     * legacy gate spoke.
     *
     * @return list<int>
     */
    public static function sourceChatIds(): array
    {
        $ids = [];
        foreach (self::mirrorChannels() as $row) {
            if (is_numeric($row['chat']) && (int) $row['chat'] !== 0) {
                $ids[] = (int) $row['chat'];
            }
        }

        return $ids;
    }

    /** The mirror gate: a channel post rides when its chat id or (on public channels) its username matches a source row. */
    public static function mirrorAccepts(int $chatId, ?string $username): bool
    {
        return self::mirrorRow($chatId, $username) !== null;
    }

    /**
     * The configured row a feed row's channel rides — the display-title
     * override and the NSFW mark live there. Null when the channel left
     * the config (its already-stored rows keep serving, unmarked).
     *
     * @return array{title: string, chat: string, nsfw: bool}|null
     */
    public static function mirrorRow(int $chatId, ?string $username): ?array
    {
        $username = self::normalizeChat((string) $username);
        foreach (self::mirrorChannels() as $row) {
            if (is_numeric($row['chat'])) {
                if ((int) $row['chat'] === $chatId) {
                    return $row;
                }
                continue;
            }
            if ($username !== '' && $username === $row['chat']) {
                return $row;
            }
        }

        return null;
    }

    public static function relayEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'tg_relay_enabled', false);
    }

    /** The owner's private chat with the bot, 0 when unset or unparsable. */
    public static function ownerChatId(): int
    {
        $id = filter_var(trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_relay_owner_chat_id', '')), FILTER_VALIDATE_INT);

        return is_int($id) ? $id : 0;
    }

    /**
     * Persists the webhook secret minted on the CLI path. A direct option
     * write on purpose: the settings form pipeline is not involved in
     * CLI-generated values.
     */
    public static function storeSecret(string $secret): void
    {
        update_option(self::SECRET_OPTION, $secret, false);
    }
}
