<?php

declare(strict_types=1);

namespace Aiya\Infra\Gofile;

/**
 * The GoFile reads a site actually asks for: who the token belongs to, one
 * folder's files, or the files a search matches.
 *
 * Rows come back as plain arrays in the shape callers store — name, kind,
 * size, modified (unix seconds), the content id, the download link and
 * whatever the platform reported beside it (md5, mimetype, share code) — so no
 * type of this package's own crosses the boundary. Folders are not rows: a
 * listing answers "what can I download here", and the download endpoints take
 * files.
 *
 * `link` is the platform's own download URL for a file; a folder has none. The
 * content id is the row's stable identity (the link's host can change between
 * calls, the id cannot), which is why it is always returned.
 *
 * Pagination is deliberately not implemented: unless the caller passes
 * pageSize, no page/pageSize query is sent and the platform applies its own
 * default page size — a folder larger than that page comes back silently
 * truncated, with no signal that rows are missing. MAX_PAGE_SIZE only caps an
 * explicit caller request. The platform's default page size has not been
 * verified against the live API, so a consumer listing very large folders
 * cannot assume it sees all of them.
 */
final class Gateway
{
    private const MAX_PAGE_SIZE = 1000;

    public function __construct(private Client $client)
    {
    }

    /**
     * The account behind the token: its id, email and tier. This read works on
     * every tier — it is how a caller checks whether the token is Premium
     * before expecting folder listings to answer. The tier string is echoed
     * as the platform reports it and has not been verified against the live
     * API beyond the guest account: callers must not branch hard on it.
     *
     * @return array{id: string, email: string, tier: string}|Error
     */
    public function account(): array|Error
    {
        $data = $this->client->get('/accounts/getid');
        if ($data instanceof Error) {
            return $data;
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'tier' => (string) ($data['tier'] ?? ''),
        ];
    }

    /**
     * One account's details (tier, root folder, plan usage). Read-only.
     *
     * @return array<string, mixed>|Error
     */
    public function accountDetails(string $accountId): array|Error
    {
        return $this->client->get('/accounts/' . rawurlencode($accountId));
    }

    /**
     * The files inside one folder — a UUID or a share code, both accepted.
     *
     * @param array{password?: string, sortField?: string, sortDirection?: int, contentFilter?: string, maxdepth?: int, pageSize?: int} $params
     * @return list<array{name: string, kind: string, size: int, modified: int|null, id: string, url: string|null, hash: string|null, mime: string|null, code: string|null}>|Error
     */
    public function contents(string $contentId, array $params = []): array|Error
    {
        $data = $this->client->get('/contents/' . rawurlencode($contentId), $this->query($params));
        if ($data instanceof Error) {
            return $data;
        }

        $children = $data['children'] ?? null;
        if (!is_array($children)) {
            return []; // a file id answers its own payload, which carries no children
        }

        $rows = [];
        foreach ($children as $child) {
            // A malformed child must degrade to a skipped row, never a
            // TypeError out of the strict row() signature.
            if (!is_array($child)) {
                continue;
            }
            $row = self::row($child);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The files a recursive search matches inside one folder. Matches are
     * case-insensitive substrings of the name or the tags.
     *
     * @param array{password?: string, pageSize?: int} $params
     * @return list<array{name: string, kind: string, size: int, modified: int|null, id: string, url: string|null, hash: string|null, mime: string|null, code: string|null}>|Error
     */
    public function search(string $contentId, string $searchedString, array $params = []): array|Error
    {
        $query = $this->query($params);
        $query['contentId'] = $contentId;
        $query['searchedString'] = $searchedString;

        $data = $this->client->get('/contents/search', $query);
        if ($data instanceof Error) {
            return $data;
        }

        // The answer is an object keyed by content UUID…
        $rows = [];
        foreach ($data as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $row = self::row($hit);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One platform child as the stored row shape, or null when it is not
     * something a reader can download (a folder, or an entry with no id).
     *
     * @param array<int|string, mixed> $child
     * @return array{name: string, kind: string, size: int, modified: int|null, id: string, url: string|null, hash: string|null, mime: string|null, code: string|null}|null
     */
    private static function row(array $child): ?array
    {
        $id = (string) ($child['id'] ?? '');
        $name = trim((string) ($child['name'] ?? ''));
        if ($id === '' || $name === '' || (string) ($child['type'] ?? '') !== 'file') {
            return null;
        }

        $link = $child['link'] ?? null;
        $hash = $child['md5'] ?? null;
        $mime = $child['mimetype'] ?? null;
        $code = $child['code'] ?? null;

        return [
            'name' => $name,
            'kind' => 'file',
            'size' => max(0, (int) ($child['size'] ?? 0)),
            'modified' => self::timestamp($child['modTime'] ?? $child['createTime'] ?? null),
            'id' => $id,
            'url' => is_string($link) && $link !== '' ? $link : null,
            'hash' => is_string($hash) && $hash !== '' ? $hash : null,
            'mime' => is_string($mime) && $mime !== '' ? $mime : null,
            'code' => is_string($code) && $code !== '' ? $code : null,
        ];
    }

    /**
     * The query parameters both listing calls share. Absent ones are left out
     * entirely so the platform applies its own defaults.
     *
     * @param array<string, mixed> $params
     * @return array<string, string|int>
     */
    private function query(array $params): array
    {
        $query = [];

        $password = (string) ($params['password'] ?? '');
        if ($password !== '') {
            $query['password'] = $password;
        }

        $pageSize = (int) ($params['pageSize'] ?? 0);
        if ($pageSize > 0) {
            $query['page'] = 1;
            $query['pageSize'] = min(self::MAX_PAGE_SIZE, $pageSize);
        }

        $sortField = (string) ($params['sortField'] ?? '');
        if ($sortField !== '') {
            $query['sortField'] = $sortField;
            $query['sortDirection'] = (int) ($params['sortDirection'] ?? -1) === 1 ? 1 : -1;
        }

        $filter = (string) ($params['contentFilter'] ?? '');
        if ($filter !== '') {
            $query['contentFilter'] = $filter;
        }

        $maxdepth = (int) ($params['maxdepth'] ?? 0);
        if ($maxdepth > 0) {
            $query['maxdepth'] = min(16, max(1, $maxdepth));
        }

        return $query;
    }

    /** GoFile stamps unix seconds; anything unreadable is simply absent. */
    private static function timestamp(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? $timestamp : null;
    }
}
