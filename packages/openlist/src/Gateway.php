<?php

declare(strict_types=1);

namespace Aiya\Infra\OpenList;

use Closure;

/**
 * The two surfacing calls the site asks OpenList for: one directory listing,
 * or the files one search matches.
 *
 * Rows come back as plain arrays in the shape the site stores — name, kind,
 * size, modified (unix seconds), path and the download link — so no type of
 * this package's own crosses the boundary; the caller maps them into whatever
 * it keeps.
 *
 * Links are built locally from a row's path and signature (link mode `d` =
 * /d/, `p` = /p/, `f` = the plain path): the listing endpoints already carry
 * everything a URL needs, so nothing is asked per file, and the reader is
 * never sent to an OpenList browsing page — these are download paths.
 *
 * Folders are not rows in either call: a listing answers "what can I download
 * here".
 */
final class Gateway
{
    /**
     * @param string $server    OpenList base URL the API client talks to
     * @param string $linkMode  d, p or f (anything else falls back to plain)
     * @param Closure(): Client $clientFactory
     * @param string $linkBase  Base URL the delivery links are built on; the
     *                          empty string falls back to `$server`. Split
     *                          from `$server` because a containerized WordPress
     *                          reaches the file service on an internal host the
     *                          visitor's browser cannot resolve.
     */
    public function __construct(
        private readonly string $server,
        private readonly string $linkMode,
        private readonly Closure $clientFactory,
        private readonly string $linkBase = '',
    ) {
    }

    /**
     * The files inside the configured path.
     *
     * @param array<string, mixed> $config path, password, per_page
     * @return list<array{name:string, kind:string, size:int, modified:?int, path:string, url:?string}>|Error
     */
    public function list(array $config): array|Error
    {
        $client = $this->client();
        $path = self::normalize($config['path'] ?? '');
        $response = $client->fs('list', [
            'path' => $path,
            'password' => (string) ($config['password'] ?? ''),
            'page' => 1,
            'per_page' => max(0, (int) ($config['per_page'] ?? 0)),
        ]);
        if ($response instanceof Error) {
            return $response;
        }

        $raw = $response['content'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row) || (bool) ($row['is_dir'] ?? false)) {
                continue;
            }

            $entry = $this->row($row, $path, (string) ($row['name'] ?? ''));
            if ($entry !== null) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * The files the keywords match under the search root. The search endpoint
     * answers name and parent only, so every hit is resolved through `get` for
     * its size and signature. A hit that vanished between search and get is
     * dropped; any other failure of a per-hit read is the source's, and it
     * fails the whole search rather than passing for a partial answer.
     *
     * @param array<string, mixed> $config keywords, parent, per_page, password
     * @return list<array{name:string, kind:string, size:int, modified:?int, path:string, url:?string}>|Error
     */
    public function search(array $config): array|Error
    {
        $client = $this->client();
        $password = (string) ($config['password'] ?? '');
        $response = $client->fs('search', [
            'parent' => self::normalize($config['parent'] ?? ''),
            'keywords' => (string) ($config['keywords'] ?? ''),
            'scope' => 2,
            'page' => 1,
            'per_page' => max(0, (int) ($config['per_page'] ?? 0)),
            'password' => $password,
        ]);
        if ($response instanceof Error) {
            return $response;
        }

        $raw = $response['content'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $hit) {
            if (!is_array($hit) || (bool) ($hit['is_dir'] ?? false)) {
                continue;
            }

            $name = (string) ($hit['name'] ?? '');
            $parent = self::normalize($hit['parent'] ?? '');
            if (trim($name) === '') {
                continue;
            }

            $detail = $client->fs('get', ['path' => self::join($parent, $name), 'password' => $password]);
            if ($detail instanceof Error) {
                if ($detail->code !== Error::NOT_FOUND) {
                    return $detail;
                }
                continue;
            }
            if ((bool) ($detail['is_dir'] ?? false)) {
                continue;
            }

            $entry = $this->row($detail, $parent, $name);
            if ($entry !== null) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /** The client the factory builds; it is always one, so nothing here guards it. */
    private function client(): Client
    {
        return ($this->clientFactory)();
    }

    /**
     * One platform row as the site's shape, or null when it has no name to
     * show.
     *
     * @param array<int|string, mixed> $row
     * @return array{name:string, kind:string, size:int, modified:?int, path:string, url:?string}|null
     */
    private function row(array $row, string $base, string $fallbackName): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim($fallbackName);
        }
        if ($name === '') {
            return null;
        }

        $path = self::join($base, $name);

        return [
            'name' => $name,
            'kind' => 'file',
            'size' => max(0, (int) ($row['size'] ?? 0)),
            'modified' => self::timestamp($row['modified'] ?? null),
            'path' => $path,
            'url' => $this->link($path, (string) ($row['sign'] ?? '')),
        ];
    }

    /**
     * The download path for one row per the site's link mode: direct (/d/),
     * proxied (/p/) or the plain path, with the platform signature when the
     * listing carried one.
     */
    private function link(string $path, string $sign): ?string
    {
        if ($path === '' || $path === '/') {
            return null;
        }

        $prefix = match ($this->linkMode) {
            'd' => '/d',
            'p' => '/p',
            default => '',
        };
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        $base = $this->linkBase !== '' ? $this->linkBase : $this->server;

        return rtrim($base, '/') . $prefix . $encoded . ($sign !== '' ? '?sign=' . $sign : '');
    }

    /**
     * Normalizes a configured path to the one canonical form this gateway
     * works in: a leading slash, no trailing slash, '/' meaning the root — so
     * an empty path and an explicit '/' behave alike and joining a name never
     * produces '//name'.
     */
    private static function normalize(mixed $path): string
    {
        $trimmed = trim((string) $path, '/');

        return $trimmed === '' ? '/' : '/' . $trimmed;
    }

    private static function join(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : $base . '/' . $name;
    }

    /**
     * OpenList stamps ISO 8601 with nanosecond precision (for example
     * 2026-09-19T08:15:16.197349297Z), which strtotime reads correctly. An
     * unreadable stamp is simply absent.
     */
    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false || $timestamp <= 0 ? null : $timestamp;
    }
}
