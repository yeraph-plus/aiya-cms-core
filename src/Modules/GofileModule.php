<?php

declare(strict_types=1);

namespace Aiya\Core\Modules;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Adapters\GofileAdapter;
use Aiya\Core\Domain\FileServe\FileServeModule;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\Gofile\Client;
use Aiya\Infra\Gofile\Gateway;
use Closure;

/**
 * Adapter for the aiya/gofile-api package: the one WordPress touchpoint the
 * GoFile request exit needs — its token field on the file downloads page, read
 * back per request — plus the adapter it registers into the domain.
 *
 * The package never sees WordPress: it answers rows as plain arrays and
 * failures as its own Errors, and the adapter class the domain owns maps both.
 * Nothing here writes to GoFile; the package is read-only by construction.
 */
final class GofileModule implements Module
{
    public const TOKEN_FIELD = 'fileserve_gofile_token';

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

        // The account token rides the adapter's site config, so changing it
        // starts a new cache generation instead of serving lists read under
        // the old account until the TTL runs out.
        $this->adapters->register(new GofileAdapter(
            fn (): Gateway => $this->gateway(),
            fn (): array => ['token' => (string) aiya_core_opt(FileServeModule::PAGE_SLUG, self::TOKEN_FIELD, '')]
        ));
    }

    /**
     * The GoFile section of the file downloads page. The token is a write-once
     * field like the other credentials: leaving it empty on save keeps what is
     * stored.
     */
    private function settingsFields(): void
    {
        $this->settings->addFields(FileServeModule::PAGE_SLUG, [
            [
                'id' => 'fileserve_heading_gofile',
                'type' => 'heading',
                'label' => __('GoFile', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => self::TOKEN_FIELD,
                'type' => 'password',
                'label' => __('Account token', 'aiya-core'),
                'description' => __('From the GoFile profile page. The account lists, folders and search endpoints work for any tier, but reading a folder needs Premium — without it every GoFile list reports that it needs Premium.', 'aiya-core'),
                'default' => '',
            ],
        ]);
    }

    private function gateway(): Gateway
    {
        return new Gateway(new Client(
            Client::DEFAULT_BASE,
            (string) aiya_core_opt(FileServeModule::PAGE_SLUG, self::TOKEN_FIELD, ''),
            $this->transport()
        ));
    }

    /** @return Closure(string, string): (array{status:int, body:string}|null) */
    private function transport(): Closure
    {
        return static function (string $url, string $token): ?array {
            $headers = ['Accept' => 'application/json'];
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_get($url, ['timeout' => 15, 'headers' => $headers]);
            if (is_wp_error($response)) {
                return null;
            }

            return [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'body' => (string) wp_remote_retrieve_body($response),
            ];
        };
    }
}
