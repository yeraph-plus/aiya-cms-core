<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Domain\Content\PostVisibility;
use WP_Error;
use WP_Post;

/**
 * The file lists of one content item: every group its meta holds, run one
 * after another, each projected into the shape the API serves.
 *
 * The listing itself is public — rows carry no link, only an opaque ref — so
 * the only viewer-dependent answer is the post gate, applied here and nowhere
 * else: a gated post answers exactly like one that does not exist, the rule
 * the comment thread already follows.
 *
 * What an adapter answers is normalized once, cached, then projected. A group
 * nobody could read contributes nothing to the public answer and is reported
 * instead (the log hook, and the editor's preview in its own words).
 */
final class FileService
{
    public const CACHE_GROUP = 'aiya_core_fileserve';

    private const DEFAULT_CACHE_MINUTES = 5;

    /**
     * The short TTL for results that must not pin a request to the upstream
     * every time but also must not live a full cache generation: a failed
     * group (negative caching) and a genuinely empty listing.
     */
    private const BRIEF_CACHE_SECONDS = 60;

    public function __construct(
        private AdapterRegistry $adapters,
        private PostVisibility $visibility,
    ) {
    }

    /**
     * The public lists of one content item, or the 404 the gate answers with.
     *
     * @return array{lists: list<array<string, mixed>>}|WP_Error
     */
    public function forPost(int $postId, int $viewerId): array|WP_Error
    {
        if ($this->readablePost($postId, $viewerId) === null) {
            return new WP_Error('aiya_not_found', __('Content not found.', 'aiya-core'), ['status' => 404]);
        }

        $lists = [];
        foreach ($this->gather(Config::read($postId, $this->adapters), $postId, false) as $group) {
            if ($group['entries'] instanceof Failure) {
                continue;
            }
            $lists[] = $this->project($group);
        }

        return ['lists' => $lists];
    }

    /**
     * The same assembly for the editor's preview: no post, no gate, always read
     * through (an editor looks at what the sources answer right now), and every
     * group reported — failures included, in the upstream's own words.
     *
     * @param array<int|string, array<string, mixed>> $config
     * @return array{lists: list<array<string, mixed>>}
     */
    public function preview(array $config): array
    {
        $lists = [];
        foreach ($this->gather($config, 0, true) as $group) {
            $list = $this->project($group);
            $failure = $group['entries'] instanceof Failure ? $group['entries'] : null;
            $list['error'] = $failure === null ? null : ['code' => $failure->wireCode(), 'message' => $failure->message];
            $lists[] = $list;
        }

        return ['lists' => $lists];
    }

    /**
     * The post a listing or a claim hangs off, or null when this viewer may not
     * read it: unknown id, a type outside the set, unpublished, or gated.
     */
    public function readablePost(int $postId, int $viewerId): ?WP_Post
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return null;
        }
        if (!PostTypes::supports((string) $post->post_type) || $post->post_status !== 'publish') {
            return null;
        }
        if (!$this->visibility->satisfied($this->visibility->level($post), $viewerId)) {
            return null;
        }

        return $post;
    }

    /**
     * One group of a post as the domain sees it — its adapter, its rate and its
     * rows — or null when the post has no such group or its source failed.
     * Claims resolve their row through this, never through what they were sent.
     *
     * @return array{id: string, adapter: string, title: string, price: int, entries: list<Entry>}|null
     */
    public function resolve(int $postId, string $id): ?array
    {
        foreach ($this->gather(Config::read($postId, $this->adapters), $postId, false) as $group) {
            if ($group['id'] !== $id || $group['entries'] instanceof Failure) {
                continue;
            }

            /** @var list<Entry> $entries */
            $entries = $group['entries'];

            return [
                'id' => $group['id'],
                'adapter' => $group['adapter'],
                'title' => $group['title'],
                'price' => $group['price'],
                'entries' => $entries,
            ];
        }

        return null;
    }

    /**
     * The opaque ref of one row: a keyed digest of its identity, stable on this
     * site and meaningless anywhere else. It is what a claim quotes back, and
     * the service recomputes it over the rows it resolved itself — so a forged
     * ref can never name a row the list does not hold.
     */
    public static function ref(Entry $entry): string
    {
        return substr(hash_hmac('sha256', $entry->identity(), wp_salt('nonce')), 0, 16);
    }

    /**
     * Every configured group of one post, in configuration order, each run one
     * at a time — a slow or broken source costs its own group and nothing else.
     *
     * @param array<int|string, array<string, mixed>> $config
     * @return list<array{id: string, adapter: string, title: string, price: int, entries: list<Entry>|Failure}>
     */
    private function gather(array $config, int $postId, bool $fresh): array
    {
        $groups = [];
        foreach ($config as $id => $group) {
            $adapter = $this->adapters->get((string) ($group['adapter'] ?? ''));
            if ($adapter === null || !$adapter->configured($group)) {
                continue;
            }

            $entries = $this->entries((string) $id, $postId, $adapter, $group, $fresh);
            if ($entries instanceof Failure) {
                do_action('aiya_core_fileserve_error', $postId, (string) $id, $entries);
            }

            $groups[] = [
                'id' => (string) $id,
                'adapter' => $adapter->id(),
                'title' => trim((string) ($group['title'] ?? '')),
                'price' => max(0, (int) ($group['price'] ?? 0)),
                'entries' => $entries,
            ];
        }

        return $groups;
    }

    /**
     * One group's normalized rows, served from the object cache when it may
     * be. The key folds the group's whole configuration plus the adapter's
     * site-level settings, so editing either starts a new generation with no
     * invalidation hook, and only plain arrays go in — a persistent object
     * cache never has to serialize an object.
     *
     * Two answers travel on their own short TTL so an anonymous GET never
     * re-pays the upstream's timeout on every request: a failed group (the
     * failure itself, as a plain array) and a genuinely empty listing.
     * The preview path is never cached and always reads through.
     *
     * @param array<string, mixed> $group
     * @return list<Entry>|Failure
     */
    private function entries(string $id, int $postId, Adapter $adapter, array $group, bool $fresh): array|Failure
    {
        $minutes = $this->cacheMinutes();
        $cacheable = !$fresh && $postId > 0 && $minutes > 0;
        $cacheKey = sprintf(
            'list_%d_%s_%s',
            $postId,
            $id,
            md5((string) wp_json_encode([$group, $adapter->siteConfig()]))
        );
        $failKey = 'failed_' . $cacheKey;

        if ($cacheable) {
            $failed = wp_cache_get($failKey, self::CACHE_GROUP);
            if (is_array($failed)) {
                return new Failure(
                    (string) ($failed['code'] ?? Failure::UNREACHABLE),
                    (string) ($failed['message'] ?? ''),
                    (int) ($failed['status'] ?? 502)
                );
            }

            $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
            if (is_array($cached)) {
                $entries = [];
                foreach ($cached as $row) {
                    if (is_array($row)) {
                        $entries[] = Entry::fromArray($row);
                    }
                }

                return $entries;
            }
        }

        $entries = $adapter->entries($group);
        if ($entries instanceof Failure) {
            if ($cacheable) {
                wp_cache_set(
                    $failKey,
                    ['code' => $entries->code, 'message' => $entries->message, 'status' => $entries->status],
                    self::CACHE_GROUP,
                    self::BRIEF_CACHE_SECONDS
                );
            }

            return $entries;
        }

        if ($cacheable) {
            wp_cache_set(
                $cacheKey,
                array_map(static fn (Entry $entry): array => $entry->toArray(), $entries),
                self::CACHE_GROUP,
                // An empty listing gets the brief TTL only: a directory that
                // is empty right now may fill any minute, and it is exactly
                // the answer a freshly-filled directory would resent being
                // pinned for.
                ($entries === [] ? self::BRIEF_CACHE_SECONDS : $minutes * MINUTE_IN_SECONDS)
            );
        }

        return $entries;
    }

    /**
     * The wire shape of one group: its id, its adapter, its caption, its rate,
     * and one row per entry — with an opaque ref where a link would otherwise
     * be.
     *
     * @param array{id: string, adapter: string, title: string, price: int, entries: list<Entry>|Failure} $group
     * @return array<string, mixed>
     */
    private function project(array $group): array
    {
        $icons = (bool) aiya_core_opt('fileserve', 'fileserve_icons', true);
        $entries = $group['entries'] instanceof Failure ? [] : $group['entries'];

        $items = [];
        foreach ($entries as $entry) {
            $items[] = [
                'ref' => self::ref($entry),
                'name' => $entry->name,
                'kind' => $entry->kind,
                'size' => $entry->size,
                'type' => FileIcons::forEntry($entry->name, $entry->isDir(), $icons),
                'modified' => $entry->modified !== null ? (string) wp_date('c', $entry->modified) : null,
            ];
        }

        return [
            'id' => $group['id'],
            'adapter' => $group['adapter'],
            'title' => $group['title'],
            'price' => $group['price'],
            'items' => $items,
        ];
    }

    /** How long a group's rows may be served from the object cache; 0 disables it. */
    private function cacheMinutes(): int
    {
        return max(0, (int) aiya_core_opt('fileserve', 'fileserve_cache_minutes', self::DEFAULT_CACHE_MINUTES));
    }
}
