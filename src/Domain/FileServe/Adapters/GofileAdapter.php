<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe\Adapters;

use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\Failure;
use Aiya\Infra\Gofile\Error;
use Aiya\Infra\Gofile\Gateway;
use Closure;

/**
 * GoFile as a data group: one folder — a content UUID or its share code — read
 * through the token set on the file downloads settings page.
 *
 * Reading a folder through the API needs a Premium account; that is a
 * configuration fact rather than a broken source, so it is reported in those
 * words (the editor's preview shows them) instead of as an unreachable
 * service. Reading is all this adapter does: no uploads, no folder management.
 *
 * This is the only place the site's vocabulary meets the package's: the
 * gateway answers rows as plain arrays (and failures as package Errors), and
 * everything is mapped here.
 */
final class GofileAdapter implements Adapter
{
    public const ID = 'gofile_api';

    /**
     * @param Closure(): Gateway $gatewayFactory
     * @param Closure(): array<string, mixed>|null $siteConfig the site-level
     *        settings the rows depend on (the account token), folded into the
     *        listing's cache key; null when the caller has none
     */
    public function __construct(
        private readonly Closure $gatewayFactory,
        private readonly ?Closure $siteConfig = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'GoFile';
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return [
            [
                'id' => 'folder_id',
                'type' => 'text',
                'label' => __('Folder id or share code', 'aiya-core'),
                'description' => __('The GoFile folder this list shows. Reading it through the API needs a Premium account on the token from the file downloads settings page.', 'aiya-core'),
                'default' => '',
            ],
        ];
    }

    /** @param array<string, mixed> $config */
    public function configured(array $config): bool
    {
        return trim((string) ($config['folder_id'] ?? '')) !== '';
    }

    public function siteConfig(): array
    {
        $resolve = $this->siteConfig;
        $config = $resolve === null ? [] : $resolve();

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<Entry>|Failure
     */
    public function entries(array $config): array|Failure
    {
        $gateway = ($this->gatewayFactory)();
        if (!$gateway instanceof Gateway) {
            return new Failure(Failure::UNREACHABLE, 'The GoFile gateway is unavailable.');
        }

        $rows = $gateway->contents(trim((string) ($config['folder_id'] ?? '')));
        if ($rows instanceof Error) {
            return self::failure($rows);
        }

        $entries = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            $url = $row['url'] ?? null;
            $code = $row['code'] ?? null;

            $entries[] = new Entry(
                name: $name,
                kind: Entry::FILE,
                size: max(0, (int) ($row['size'] ?? 0)),
                modified: isset($row['modified']) ? (int) $row['modified'] : null,
                id: $id !== '' ? $id : null,
                url: is_string($url) && $url !== '' ? $url : null,
                code: is_string($code) && $code !== '' ? $code : null,
            );
        }

        return $entries;
    }

    /** The package's own taxonomy, read into the domain's. */
    private static function failure(Error $error): Failure
    {
        if ($error->code === Error::PREMIUM) {
            return new Failure(
                Failure::DENIED,
                __('The GoFile token is not a Premium account, and the API only lists folders for Premium.', 'aiya-core'),
                $error->status
            );
        }

        $code = match ($error->code) {
            Error::UNAUTHORIZED => Failure::UNAUTHORIZED,
            Error::DENIED => Failure::DENIED,
            Error::NOT_FOUND => Failure::NOT_FOUND,
            Error::RATE_LIMITED, Error::INVALID => Failure::INVALID,
            default => Failure::UNREACHABLE,
        };

        return new Failure($code, $error->message, $error->status);
    }
}
