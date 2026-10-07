<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * The Telegram Bot domain module: the settings page the three routes
 * configure against, the push listeners, and the two post-1.0 table
 * migrations. The webhook intake route is assembled by the Api layer
 * (RestController), the operator CLI by the entry file; the development
 * site-side intake is driven by a workspace mu-plugin — the domain owns
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
            $migrations[] = ['version' => FeedIngestor::ENTITIES_MIGRATION_VERSION, 'callback' => [FeedIngestor::class, 'addEntitiesColumn']];
            $migrations[] = ['version' => FeedIngestor::CHAT_IDENTITY_MIGRATION_VERSION, 'callback' => [FeedIngestor::class, 'addChatIdentityColumns']];

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
            'menu_position' => 6, // takes the uninstall page's old rail slot; the uninstall page itself drops to 99, the rail's permanent end
            'option_name' => 'aiya_core_telegram',
            'tabs' => false, // one short page: the section headings stay inline, no tab rail
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
                    'id' => 'tg_heading_push',
                    'type' => 'heading',
                    'label' => __('Publish push', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tg_push_enabled',
                    'type' => 'switch',
                    'label' => __('Enabled', 'aiya-core'),
                    'checkbox_label' => __('Push published posts and resources to the target chat, editing the sent message in place on update', 'aiya-core'),
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
                    'id' => 'tg_push_template',
                    'type' => 'textarea',
                    'label' => __('Message template', 'aiya-core'),
                    'description' => __('The push message body in Telegram HTML. Placeholders: {front} the front-end origin, {type} and {slug} the post\'s public type and slug, {id}, {title}, {excerpt}, {tags} and {categories} display names with a # prefix, {date}, {author}. Links are yours to compose from {front}, {type} and {slug}; the backend keeps no front-end route shapes. Every substitution is escaped, so the markup you write here is the only markup; leaving a placeholder out drops that part. Empty restores the default. Bounds: the title caps at 250 characters, the excerpt at 400 (hand-filled excerpt first, else the stripped content), and the whole message clamps to the platform limit of 4096 characters with the excerpt yielding first.', 'aiya-core'),
                    'default' => '{title}' . "\n\n" . '{excerpt}',
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
                    'label' => __('Enabled', 'aiya-core'),
                    'checkbox_label' => __('Ingest the source channels\' posts into the mirror feed (images transfer into the image pool; videos and files surface as t.me links)', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_mirror_source_chat_ids',
                    'type' => 'repeater',
                    'label' => __('Source channels', 'aiya-core'),
                    'description' => __('The channels mirrored into the web feed, one row each: an optional display title (the feed prefers it over the channel\'s own name), the channel\'s numeric id or @username, and the NSFW mark the row carries into the feed. Posts from any other chat are dropped. A private channel\'s t.me links only open for its members.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'title',
                            'type' => 'text',
                            'label' => __('Display title', 'aiya-core'),
                            'default' => '',
                        ],
                        [
                            'id' => 'chat',
                            'type' => 'text',
                            'label' => __('Channel id or @username', 'aiya-core'),
                            'default' => '',
                            'required' => true,
                        ],
                        [
                            'id' => 'nsfw',
                            'type' => 'switch',
                            'label' => __('NSFW', 'aiya-core'),
                            'default' => false,
                        ],
                    ],
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
                    'label' => __('Enabled', 'aiya-core'),
                    'checkbox_label' => __('Relay the web support chat into your private chat with the bot, and your replies back to the web', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'tg_relay_owner_chat_id',
                    'type' => 'text',
                    'label' => __('Owner chat id', 'aiya-core'),
                    'description' => __('Your private chat with the bot. Send /status there and the bot replies with the id; only replies to relayed visitor messages flow back to the web.', 'aiya-core'),
                    'default' => '',
                ],
            ],
        ]);
    }
}
