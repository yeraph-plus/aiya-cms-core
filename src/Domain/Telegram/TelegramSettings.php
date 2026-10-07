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

    /** The /id discovery probe: on, /id gets the asking chat's id back. */
    public static function idProbeEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'tg_id_probe', false);
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
     * wire ({front}, {link}, {type}, {slug}, {id}, {title}, {excerpt},
     * {tags}, {categories}, {date}, {author}); empty restores the
     * shipped two-line shape.
     */
    public static function pushTemplate(): string
    {
        $template = trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_push_template', ''));

        return $template !== '' ? $template : '<a href="{link}">{title}</a>' . "\n\n" . '{excerpt}';
    }

    public static function mirrorEnabled(): bool
    {
        return (bool) aiya_core_opt(self::PAGE_SLUG, 'tg_mirror_enabled', false);
    }

    /**
     * The source channels of the mirror route, one numeric id per line
     * (commas and semicolons also separate); noise lines are ignored.
     *
     * @return list<int>
     */
    public static function sourceChatIds(): array
    {
        $raw = (string) aiya_core_opt(self::PAGE_SLUG, 'tg_mirror_source_chat_ids', '');
        $lines = preg_split('/[\s,;]+/u', trim($raw));
        $lines = is_array($lines) ? $lines : [];
        $ids = [];
        foreach ($lines as $line) {
            $id = filter_var((string) $line, FILTER_VALIDATE_INT);
            if (is_int($id) && $id !== 0) {
                $ids[] = $id;
            }
        }

        return $ids;
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
