<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Core\Settings\Storage\OptionStore;

/**
 * Typed reads over the Telegram settings page (one place owns the field
 * ids); every reader answers the field default on an unsaved option, so a
 * fresh install is inert by construction.
 */
final class TelegramSettings
{
    public const PAGE_SLUG = 'telegram';

    public static function botToken(): string
    {
        return trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_bot_token', ''));
    }

    public static function webhookSecret(): string
    {
        return trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_webhook_secret', ''));
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

    public static function pushLinkTemplate(): string
    {
        $template = trim((string) aiya_core_opt(self::PAGE_SLUG, 'tg_push_link_template', ''));

        return $template !== '' ? $template : '/resources/{slug}/';
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
     * Persists the webhook secret from the CLI path (the page field may
     * sit empty until set-webhook mints one). A direct store write on
     * purpose: the settings form pipeline is not involved in CLI-generated
     * values, and the aiya_core_opt memo clears itself on the write.
     */
    public static function storeSecret(string $secret): void
    {
        $page = aiya_core()->settings()->page(self::PAGE_SLUG);
        if ($page === null) {
            return;
        }

        $store = new OptionStore($page->optionName(), $page->network());
        $values = $store->all();
        $values['tg_webhook_secret'] = $secret;
        $store->replace($values);
    }
}
