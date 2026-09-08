<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\ExternalFiles;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Settings\Registry;
use WP_Error;

/**
 * OpenList integration adapter (legacy `inc/func-openlist.php` + global
 * options): owns the domain settings page, the `oplist_client` post box on
 * the resource screen (protocol group key `aya_box_oplist_client` — same
 * key, moved scope post → resource per the 2026-09-08 decision), and the
 * authenticated client factory with the transient token cache.
 *
 * Legacy pieces deliberately not ported: the `[oplist_cli]` shortcode
 * meta-persist layer (shortcodes follow the template-parts plan) and the
 * per-view sponsorship trigger counter (the 2026-09-09 gate redesign
 * trimmed links per viewer instead).
 */
final class OplistModule implements Module
{
    public const CACHE_GROUP = 'aiya_core_oplist';

    public function __construct(private Registry $settings, private MetadataRegistry $metadata)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', function (): void {
            $this->settingsPage();
            $this->postBox();
        }, 10, 0);
    }

    private function settingsPage(): void
    {
        $this->settings->addPage([
            'slug' => OplistSettings::PAGE_SLUG,
            'title' => __('OpenList', 'aiya-core'),
            'menu_title' => __('OpenList', 'aiya-core'),
            'parent' => 'aiya-core-sample',
            'option_name' => OplistSettings::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'oplist_server_url',
                    'type' => 'url',
                    'label' => __('Server URL', 'aiya-core'),
                    'description' => __('Base address of the OpenList instance that hosts resource attachments.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'oplist_server_user',
                    'type' => 'text',
                    'label' => __('API username', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'oplist_server_password',
                    'type' => 'password',
                    'label' => __('API password', 'aiya-core'),
                    'description' => __('Used to fetch fresh bearer tokens; stored server-side and never exposed.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'oplist_token_hours',
                    'type' => 'number',
                    'label' => __('Token cache (hours)', 'aiya-core'),
                    'description' => __('0 refetches the token on every request. Uses the object cache, so a Redis/memcached drop-in makes it persist.', 'aiya-core'),
                    'default' => 24,
                    'min' => 0,
                    'max' => 720,
                    'step' => 1,
                ],
                [
                    'id' => 'oplist_list_cache_minutes',
                    'type' => 'number',
                    'label' => __('Attachment list cache (minutes)', 'aiya-core'),
                    'description' => __('How long an attachment listing is served from the object cache before OpenList is asked again; 0 disables caching. The box\u0027s force-refresh switch always bypasses it.', 'aiya-core'),
                    'default' => 5,
                    'min' => 0,
                    'max' => 1440,
                    'step' => 1,
                ],
                [
                    'id' => 'oplist_link_type',
                    'type' => 'radio',
                    'label' => __('Download link mode', 'aiya-core'),
                    'default' => 'f',
                    'options' => [
                        'f' => __('Plain path', 'aiya-core'),
                        'd' => __('Direct (/d/)', 'aiya-core'),
                        'p' => __('Proxied (/p/)', 'aiya-core'),
                        'r' => __('raw_url from the API', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'oplist_icons',
                    'type' => 'switch',
                    'label' => __('File type icons', 'aiya-core'),
                    'description' => __('Classify attachments by extension for the front end.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'oplist_file_desc',
                    'type' => 'textarea',
                    'label' => __('Default panel description', 'aiya-core'),
                    'description' => __('Shown when a resource does not describe its attachments.', 'aiya-core'),
                    'default' => '',
                ],
            ],
        ]);
    }

    /** The legacy box, verbatim fields, scoped to the resource screen. */
    private function postBox(): void
    {
        $this->metadata->addPostBox([
            'id' => 'oplist_client',
            'title' => __('OpenList attachments', 'aiya-core'),
            'screens' => ['resource'],
            'context' => 'normal',
            'priority' => 'low',
            'fields' => [
                [
                    'id' => 'sponsor_can',
                    'type' => 'switch',
                    'label' => __('Sponsor-only downloads', 'aiya-core'),
                    'description' => __('On: only sponsors see download links (listing stays public). Off: any signed-in user sees them.', 'aiya-core'),
                    'default' => true,
                ],
                [
                    'id' => 'fs_method',
                    'type' => 'select',
                    'label' => __('Surfacing mode', 'aiya-core'),
                    'default' => 'off',
                    'options' => [
                        'off' => __('Off', 'aiya-core'),
                        'list' => __('List a directory', 'aiya-core'),
                        'get' => __('Single file or directory', 'aiya-core'),
                        'dirs' => __('Directory tree', 'aiya-core'),
                        'search' => __('Search', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'path',
                    'type' => 'text',
                    'label' => __('Path', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'desc',
                    'type' => 'textarea',
                    'label' => __('Panel description', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'parent',
                    'type' => 'text',
                    'label' => __('Search root', 'aiya-core'),
                    'description' => __('Search mode only.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'keywords',
                    'type' => 'text',
                    'label' => __('Search keywords', 'aiya-core'),
                    'description' => __('Search mode only.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'per_page',
                    'type' => 'number',
                    'label' => __('Per page', 'aiya-core'),
                    'description' => __('0 lists everything.', 'aiya-core'),
                    'default' => 0,
                    'min' => 0,
                    'step' => 1,
                ],
                [
                    'id' => 'password',
                    'type' => 'text',
                    'label' => __('OpenList path password', 'aiya-core'),
                    'description' => __('The password of the configured path on the OpenList side, if any.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'refresh',
                    'type' => 'switch',
                    'label' => __('Force refresh', 'aiya-core'),
                    'description' => __('Skip the OpenList cache on every listing request.', 'aiya-core'),
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * The attachment service wired with a token-caching client factory.
     */
    public function attachments(): AttachmentService
    {
        return new AttachmentService(fn (): OpenListClient => $this->client(), new MembershipService());
    }

    private function client(): OpenListClient
    {
        $settings = OplistSettings::read();
        $token = '';
        $hours = $settings['tokenHours'];

        // Object cache on purpose (2026-09-09): with a Redis/memcached
        // drop-in the token persists across requests; without one it lives
        // for the request only and every request re-logins — that trade is
        // accepted in exchange for not pinning credentials in the options
        // table.
        if ($hours > 0) {
            $cached = wp_cache_get('token', 'aiya_core_oplist');
            $token = is_string($cached) ? $cached : '';
        }

        if ($token === '' && $settings['user'] !== '' && $settings['password'] !== '') {
            $login = $this->transport();
            $client = new OpenListClient($settings['server'], '', $login);
            $token = $client->login($settings['user'], $settings['password']);
            if ($token instanceof WP_Error) {
                // Surface via the error hook; requests will answer auth
                // errors until the credentials are fixed.
                do_action('aiya_core_oplist_error', 0, $token);
                $token = '';
            } elseif ($hours > 0) {
                wp_cache_set('token', $token, 'aiya_core_oplist', $hours * HOUR_IN_SECONDS);
            }
        }

        return new OpenListClient($settings['server'], $token, $this->transport());
    }

    /** The wp_remote transport shape the client expects. */
    private function transport(): callable
    {
        return static function (string $method, string $url, ?string $body, string $token): ?array {
            $headers = ['Content-Type' => 'application/json'];
            if ($token !== '') {
                $headers['Authorization'] = $token;
            }

            $response = 'GET' === $method
                ? wp_remote_get($url, ['timeout' => 15])
                : wp_remote_post($url, ['timeout' => 15, 'headers' => $headers, 'body' => (string) $body]);
            if (is_wp_error($response)) {
                return null;
            }

            return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
        };
    }
}
