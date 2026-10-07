<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * The Telegram Bot domain module: the settings page the three routes
 * configure against, the push listeners, and the two post-1.0 table
 * migrations. The webhook intake route is assembled by the Api layer
 * (RestController), the operator CLI by the entry file — the domain owns
 * neither transport.
 */
final class TelegramModule implements Module
{
    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        // Route 1 (publish push): the pusher gates on its own settings, so
        // the listeners ride every request cost-free while the route is
        // off. Failures retry through the single-event backoff below.
        $pusher = new Pusher();
        add_action('aiya_core_post_published', [$pusher, 'onPublished']);
        add_action('aiya_core_post_updated', [$pusher, 'onUpdated']);
        add_action(Pusher::RETRY_HOOK, [$pusher, 'onRetry'], 10, 2);
        add_filter('aiya_core_scheduled_events', static function (array $hooks): array {
            $hooks[] = Pusher::RETRY_HOOK;

            return $hooks;
        });

        // The channel feed table lands as the chain's first post-1.0
        // entry (MigrationChainTest pins the count and the version).
        add_filter('aiya_core_schema_migrations', static function (array $migrations): array {
            $migrations[] = ['version' => FeedIngestor::MIGRATION_VERSION, 'callback' => [FeedIngestor::class, 'installTables']];
            $migrations[] = ['version' => ChatStore::MIGRATION_VERSION, 'callback' => [ChatStore::class, 'installTables']];

            return $migrations;
        });
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => TelegramSettings::PAGE_SLUG,
            'title' => __('Telegram Bot', 'aiya-core'),
            'menu_title' => __('Telegram Bot', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            'option_name' => 'aiya_core_telegram',
            'fields' => [
                [
                    'id' => 'tg_heading_connection',
                    'type' => 'heading',
                    'label' => __('Bot connection', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tg_bot_token',
                    'type' => 'password',
                    'label' => __('Bot token', 'aiya-core'),
                    'description' => __('From @BotFather. Stored server-side and never exposed.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'tg_mode',
                    'type' => 'radio',
                    'label' => __('Update intake', 'aiya-core'),
                    'description' => __('The webhook needs a publicly reachable HTTPS host (ports 443, 80, 88 or 8443) and outbound reachability of api.telegram.org. The CLI long poll is its development twin — the platform allows only one intake at a time.', 'aiya-core'),
                    'default' => 'poll',
                    'options' => [
                        'poll' => __('CLI long poll (development)', 'aiya-core'),
                        'webhook' => __('Webhook (production)', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'tg_webhook_secret',
                    'type' => 'password',
                    'label' => __('Webhook secret token', 'aiya-core'),
                    'description' => __('Minted by `wp aiya telegram set-webhook` when none is stored yet; never displayed. Telegram echoes it in a request header on every push; treat it like a password.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'tg_id_probe',
                    'type' => 'switch',
                    'checkbox_label' => __('Answer /id with the asking chat\'s id (discovery helper — send /id to the bot or post it in the channel, then turn this off)', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_heading_push',
                    'type' => 'heading',
                    'label' => __('Publish push', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tg_push_enabled',
                    'type' => 'switch',
                    'checkbox_label' => __('Push resource posts to the target chat when published, edit the sent message in place on update', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_push_chat_id',
                    'type' => 'text',
                    'label' => __('Push target', 'aiya-core'),
                    'description' => __('Numeric chat id or @channelusername the publish/update notices go to.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'tg_push_link_template',
                    'type' => 'text',
                    'label' => __('Front-end link template', 'aiya-core'),
                    'description' => __('Path template for the article link in the notice; {slug} is replaced with the post slug on top of the configured front-end domain.', 'aiya-core'),
                    'default' => '/resources/{slug}/',
                ],
                [
                    'id' => 'tg_heading_mirror',
                    'type' => 'heading',
                    'label' => __('Channel mirror', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tg_mirror_enabled',
                    'type' => 'switch',
                    'checkbox_label' => __('Ingest the source channels\' posts into the mirror feed (images transfer into the image pool; videos and files surface as t.me links)', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_mirror_source_chat_ids',
                    'type' => 'textarea',
                    'label' => __('Source channels', 'aiya-core'),
                    'description' => __('Numeric ids of the channels the bot administers, one per line. Posts from any other chat are dropped. A private channel\'s t.me links only open for its members.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'tg_heading_relay',
                    'type' => 'heading',
                    'label' => __('Support relay', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tg_relay_enabled',
                    'type' => 'switch',
                    'checkbox_label' => __('Relay the web support chat into your private chat with the bot, and your replies back to the web', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_relay_owner_chat_id',
                    'type' => 'text',
                    'label' => __('Owner chat id', 'aiya-core'),
                    'description' => __('Your private chat with the bot. Send it any message and `wp aiya telegram poll` logs the id; only replies to relayed visitor messages flow back to the web.', 'aiya-core'),
                    'default' => '',
                ],
            ],
        ]);
    }
}
