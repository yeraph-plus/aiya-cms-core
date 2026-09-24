<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Adapters\OpenListAdapter;
use Aiya\Core\Domain\FileServe\Config;
use Aiya\Core\Domain\FileServe\Failure;
use Aiya\Core\Domain\FileServe\FileServeModule;
use Aiya\Core\Domain\FileServe\FileService;
use Aiya\Core\Domain\FileServe\SourceLog;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\OpenList\Client;
use Aiya\Infra\OpenList\Error;
use Aiya\Infra\OpenList\Gateway;
use Closure;

/**
 * Adapter for the aiya/openlist package: every WordPress touchpoint the
 * OpenList request exit needs — its settings section on the file downloads
 * page, the wp_remote transport, the object-cached login token — plus the two
 * adapters (a directory listing, a search) it registers into the domain.
 *
 * The package never sees WordPress: it answers rows as plain arrays and
 * failures as its own Errors, and the adapter classes the domain owns map
 * both.
 */
final class OpenListModule implements Module
{
    private const TOKEN_CACHE_KEY = 'fileserve_oplist_token';

    /** How long one source failure stays written-out of the debug log. */
    private const LOG_DEDUPE_SECONDS = 300;

    public function __construct(
        private Registry $settings,
        private AdapterRegistry $adapters,
    ) {
    }

    public function register(): void
    {
        add_action('aiya_core_register', function (): void {
            $this->settingsFields();
        }, 10, 0);

        // One gateway factory for both adapters: same service, same link mode,
        // same token cache.
        $gateway = fn (): Gateway => $this->gateway();
        $siteConfig = fn (): array => $this->settings();
        $this->adapters->register(new OpenListAdapter(OpenListAdapter::LIST_ID, $gateway, $siteConfig));
        $this->adapters->register(new OpenListAdapter(OpenListAdapter::SEARCH_ID, $gateway, $siteConfig));

        // The package stays WordPress-free, so the caller logs its failures:
        // one throttled debug line per source failure, beside the error hook
        // every consumer already listens to.
        add_action('aiya_core_fileserve_error', [$this, 'logSourceFailure'], 10, 3);
    }

    /**
     * The debug-log side of the error hook: OpenList-related failures (a
     * failed login, or a group whose adapter is one of this module's) land in
     * the source log once per dedupe window, so a listing read by every
     * visitor of a broken backend writes one line, not one per request.
     * The hook itself stays untouched — throttling only quiets the log.
     */
    public function logSourceFailure(int $postId, string $groupId, Failure $failure): void
    {
        if (!SourceLog::active()) {
            return;
        }

        if ($groupId !== '' && !$this->isOwnGroup($postId, $groupId)) {
            return;
        }

        SourceLog::writeOnce(
            SourceLog::key($postId, $groupId, $failure->code),
            self::LOG_DEDUPE_SECONDS,
            sprintf('openlist failure post=%d group=%s code=%s', $postId, $groupId, $failure->code),
            $failure->message
        );
    }

    /** Whether the failing group is one of this module's adapters. */
    private function isOwnGroup(int $postId, string $groupId): bool
    {
        $group = Config::read($postId, $this->adapters)[$groupId] ?? null;
        $adapter = is_array($group) ? (string) ($group['adapter'] ?? '') : '';

        return in_array($adapter, [OpenListAdapter::LIST_ID, OpenListAdapter::SEARCH_ID], true);
    }

    /**
     * The OpenList section of the file downloads page. The link mode decides
     * how a delivery link is built and never where a reader lands: every mode
     * is a download path on the OpenList host, not a browsing page.
     */
    private function settingsFields(): void
    {
        $this->settings->addFields(FileServeModule::PAGE_SLUG, [
            [
                'id' => 'fileserve_heading_oplist',
                'type' => 'heading',
                'label' => __('OpenList service', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'fileserve_oplist_server_url',
                'type' => 'url',
                'label' => __('Server URL', 'aiya-core'),
                'description' => __('Base address of the OpenList instance that hosts the listed files.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'fileserve_oplist_public_url',
                'type' => 'url',
                'label' => __('Link base URL', 'aiya-core'),
                'description' => __('Base address the delivery links are built on — the one the visitor\'s browser must reach. Leave empty to reuse the server URL; split them when WordPress reaches the file service on an internal host.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'fileserve_oplist_server_user',
                'type' => 'text',
                'label' => __('API username', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'fileserve_oplist_server_password',
                'type' => 'password',
                'label' => __('API password', 'aiya-core'),
                'description' => __('Used to fetch fresh bearer tokens; stored server-side and never exposed.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'fileserve_oplist_token_hours',
                'type' => 'number',
                'label' => __('Token cache (hours)', 'aiya-core'),
                'description' => __('0 refetches the token on every request. Uses the object cache, so a Redis/memcached drop-in makes it persist.', 'aiya-core'),
                'default' => 24,
                'min' => 0,
                'max' => 720,
                'step' => 1,
            ],
            [
                'id' => 'fileserve_oplist_link_mode',
                'type' => 'radio',
                'label' => __('Download link mode', 'aiya-core'),
                'description' => __('How a delivery link is built. All three are download paths on the OpenList host — none of them sends a reader to its browsing pages.', 'aiya-core'),
                'default' => 'd',
                'options' => [
                    'd' => __('Direct (/d/)', 'aiya-core'),
                    'p' => __('Proxied (/p/)', 'aiya-core'),
                    'f' => __('Plain path', 'aiya-core'),
                ],
            ],
        ]);
    }

    private function gateway(): Gateway
    {
        $settings = $this->settings();

        return new Gateway(
            $settings['server'],
            $settings['linkMode'],
            fn (): Client => $this->client(),
            $settings['publicUrl'],
        );
    }

    private function client(): Client
    {
        $settings = $this->settings();
        $token = '';
        $hours = $settings['tokenHours'];

        // Object cache on purpose: with a Redis/memcached drop-in the token
        // persists across requests; without one it lives for the request only
        // and every request re-logins — the trade accepted in exchange for not
        // pinning credentials in the options table.
        if ($hours > 0) {
            $cached = wp_cache_get(self::TOKEN_CACHE_KEY, FileService::CACHE_GROUP);
            $token = is_string($cached) ? $cached : '';
        }

        if ($token === '' && $settings['user'] !== '' && $settings['password'] !== '') {
            $login = (new Client($settings['server'], '', $this->transport()))
                ->login($settings['user'], $settings['password']);
            if ($login instanceof Error) {
                // Surface through the error hook (no group id: the credentials
                // are not one group's business); every list answers with an
                // unreachable source until they are fixed.
                do_action('aiya_core_fileserve_error', 0, '', new Failure(Failure::UNAUTHORIZED, $login->message, $login->status));
            } else {
                $token = $login;
                if ($hours > 0) {
                    wp_cache_set(self::TOKEN_CACHE_KEY, $token, FileService::CACHE_GROUP, $hours * HOUR_IN_SECONDS);
                }
            }
        }

        return new Client($settings['server'], $token, $this->transport());
    }

    /**
     * The page's stored values, read through the settings facade so a fresh
     * install answers the field defaults.
     *
     * @return array{server: string, publicUrl: string, user: string, password: string, tokenHours: int, linkMode: string}
     */
    private function settings(): array
    {
        $mode = (string) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_link_mode', 'd');

        return [
            'server' => rtrim((string) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_server_url', ''), '/'),
            'publicUrl' => rtrim((string) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_public_url', ''), '/'),
            'user' => (string) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_server_user', ''),
            'password' => (string) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_server_password', ''),
            'tokenHours' => max(0, (int) aiya_core_opt(FileServeModule::PAGE_SLUG, 'fileserve_oplist_token_hours', 24)),
            'linkMode' => in_array($mode, ['d', 'p', 'f'], true) ? $mode : 'd',
        ];
    }

    /**
     * The pure HTTP exit: one request in, one answer out. No hooks, no
     * logging, no retries and no login side effects live here — login and
     * its error reporting are the module's business above, and a failure is
     * reported once per listing read, never per wire call.
     *
     * GET carries the Authorization header too: harmless today, and it keeps
     * a future read that needs the token from silently missing it.
     *
     * @return Closure(string, string, ?string, string): (array{status:int, body:string}|null)
     */
    private function transport(): Closure
    {
        return static function (string $method, string $url, ?string $body, string $token): ?array {
            $headers = ['Content-Type' => 'application/json'];
            if ($token !== '') {
                $headers['Authorization'] = $token;
            }

            $response = 'GET' === $method
                ? wp_remote_get($url, ['timeout' => 15, 'headers' => $headers])
                : wp_remote_post($url, ['timeout' => 15, 'headers' => $headers, 'body' => (string) $body]);
            if (is_wp_error($response)) {
                return null;
            }

            return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
        };
    }
}
