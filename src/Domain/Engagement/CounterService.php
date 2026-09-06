<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Engagement;

use WP_Error;

/**
 * Like and view counters for content (posts and pages by default), carried
 * on the persistent protocol postmeta keys `like_count` / `view_count`
 * (workspace AGENTS.md) — read-write compatible with the legacy values.
 *
 * Unlike the legacy implementation, which read and wrote these through lazy
 * properties hanging off the global post wrapper, counting lives in this
 * service: increments run as single atomic SQL statements (no read-modify-
 * write races) with the meta cache invalidated afterwards, and per-visitor
 * throttling keeps view bumps and like spam from writing on every hit.
 *
 * The headless front end drives the counters through the REST endpoints in
 * Api/Rest/CounterController; nothing increments during PHP-side rendering.
 */
final class CounterService
{
    /** Persistent protocol keys — the key names are the contract. */
    public const LIKE_KEY = 'like_count';
    public const VIEW_KEY = 'view_count';

    private const VIEW_THROTTLE_TTL = 3600;
    private const LIKE_DEDUPE_TTL = 2592000;

    /** @return list<string> */
    public function postTypes(): array
    {
        /** @var list<string> $types */
        $types = (array) apply_filters('aiya_core_counter_post_types', ['post', 'page']);

        return array_values(array_filter(array_map('strval', $types)));
    }

    public function likes(int $postId): int
    {
        return absint((string) get_post_meta($postId, self::LIKE_KEY, true));
    }

    public function views(int $postId): int
    {
        return absint((string) get_post_meta($postId, self::VIEW_KEY, true));
    }

    /**
     * Records one view for a visitor; repeated hits inside the throttle
     * window (default one hour) are ignored.
     *
     * @return int|WP_Error The view count after processing.
     */
    public function registerView(int $postId, string $visitorHash): int|WP_Error
    {
        $error = $this->ensureTarget($postId);
        if ($error !== null) {
            return $error;
        }

        $key = 'aiya_core_view_' . md5($postId . '|' . $visitorHash);
        if (get_transient($key) !== false) {
            return $this->views($postId);
        }

        $ttl = (int) apply_filters('aiya_core_view_throttle_ttl', self::VIEW_THROTTLE_TTL);
        set_transient($key, 1, max(1, $ttl));

        return $this->bump($postId, self::VIEW_KEY);
    }

    /**
     * Records one like for a visitor; further likes from the same visitor
     * are deduplicated (default window: 30 days) instead of counted.
     *
     * @return array{likes: int, already: bool}|WP_Error
     */
    public function registerLike(int $postId, string $visitorHash): array|WP_Error
    {
        $error = $this->ensureTarget($postId);
        if ($error !== null) {
            return $error;
        }

        $key = 'aiya_core_like_' . md5($postId . '|' . $visitorHash);
        if (get_transient($key) !== false) {
            return ['likes' => $this->likes($postId), 'already' => true];
        }

        $ttl = (int) apply_filters('aiya_core_like_dedupe_ttl', self::LIKE_DEDUPE_TTL);
        set_transient($key, 1, max(1, $ttl));

        return ['likes' => $this->bump($postId, self::LIKE_KEY), 'already' => false];
    }

    /**
     * Stable per-visitor identifier: logged-in users by id, guests by a
     * hash of IP and user agent. Guests behind a shared NAT share a bucket
     * — acceptable throttle granularity.
     */
    public function visitorHash(): string
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return 'u' . $userId;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        return 'g' . md5($ip . '|' . $agent);
    }

    private function ensureTarget(int $postId): ?WP_Error
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return new WP_Error('aiya_counter_missing_post', __('The content does not exist.', 'aiya-core'), ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->postTypes(), true) || $post->post_status !== 'publish') {
            return new WP_Error('aiya_counter_not_supported', __('This content does not carry counters.', 'aiya-core'), ['status' => 404]);
        }

        return null;
    }

    /** Atomic single-statement increment; no read-modify-write race. */
    private function bump(int $postId, string $key): int
    {
        global $wpdb;

        // Baseline row so the atomic UPDATE below always has a target.
        add_post_meta($postId, $key, 0, true);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
            $postId,
            $key
        ));
        wp_cache_delete($postId, 'post_meta');

        return absint((string) get_post_meta($postId, $key, true));
    }
}
