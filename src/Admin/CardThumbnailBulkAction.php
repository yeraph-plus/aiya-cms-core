<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Media\CardThumbnailService;

/**
 * Bulk action "Refresh thumbnails" on every list-table post type: re-runs
 * the card composite for the selected rows (content image changed, the
 * source was wrong the first time, the card was hand-deleted). Replaces
 * the per-row refresh link — a bulk round trip covers the common
 * "regenerate a batch after touching the cover pipeline" flow, and core
 * verifies the bulk nonce before the handler filter fires.
 *
 * The card pipeline itself is type-agnostic (any content with a source
 * image composites), so the action registers on all show_ui types instead
 * of a contract list. Only published rows composite — the card is a
 * front-end listing asset; anything else counts as skipped.
 */
final class CardThumbnailBulkAction implements Module
{
    private const ACTION = 'aiya_refresh_thumbs';

    public function __construct(private CardThumbnailService $cards)
    {
    }

    public function register(): void
    {
        // Late init: the type list must include code-registered CPTs,
        // which only exist after ContentTypeModule ran (init 5).
        add_action('init', function (): void {
            foreach ($this->screenTypes() as $type) {
                add_filter('bulk_actions-edit-' . $type, [$this, 'addBulkAction']);
                add_filter('handle_bulk_actions-edit-' . $type, [$this, 'handle'], 10, 3);
            }
        }, 20);
        add_action('admin_notices', [$this, 'notice']);
    }

    /** Every list-table post type; attachment's table lives on upload.php.
     *
     * @return list<string>
     */
    private function screenTypes(): array
    {
        $types = get_post_types(['show_ui' => true]);
        unset($types['attachment']);

        return array_keys($types);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addBulkAction(array $actions): array
    {
        $actions[self::ACTION] = __('Refresh thumbnails', 'aiya-core');

        return $actions;
    }

    /**
     * Runs the composite for the selected rows and reports back through
     * redirect query args. Core has already verified the bulk nonce.
     *
     * @param mixed $redirect
     * @param mixed $action
     * @param mixed $ids
     */
    public function handle($redirect, $action, $ids): string
    {
        if ($action !== self::ACTION || !is_string($redirect)) {
            return is_string($redirect) ? $redirect : '';
        }

        $done = 0;
        $skipped = 0;
        foreach (is_array($ids) ? $ids : [] as $id) {
            $postId = (int) $id;
            $post = $postId > 0 ? get_post($postId) : null;
            if (!$post instanceof \WP_Post
                || $post->post_status !== 'publish'
                || !current_user_can('edit_post', $postId)
                || !$this->cards->refreshFor($postId)) {
                ++$skipped;
                continue;
            }
            ++$done;
        }

        return (string) add_query_arg([
            'aiya_thumbs_done' => (string) $done,
            'aiya_thumbs_skipped' => (string) $skipped,
        ], $redirect);
    }

    public function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        if (!isset($_GET['aiya_thumbs_done']) && !isset($_GET['aiya_thumbs_skipped'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counters
        $done = absint((string) ($_GET['aiya_thumbs_done'] ?? '0'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto
        $skipped = absint((string) ($_GET['aiya_thumbs_skipped'] ?? '0'));

        $messages = [];
        if ($done > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of refreshed items */
                _n('%d item refreshed.', '%d items refreshed.', $done, 'aiya-core'),
                $done
            );
        }
        if ($skipped > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of skipped items */
                _n('%d item skipped (nothing to refresh or not permitted).', '%d items skipped (nothing to refresh or not permitted).', $skipped, 'aiya-core'),
                $skipped
            );
        }
        if ($messages === []) {
            $messages[] = __('No thumbnails were refreshed.', 'aiya-core');
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(implode(' ', $messages))
        );
    }
}
