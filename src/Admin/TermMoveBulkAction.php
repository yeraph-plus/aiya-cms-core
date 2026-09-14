<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\TermTaxonomyMover;

/**
 * Bulk action "Move to another taxonomy" on every contract taxonomy's
 * term list screen (edit-tags.php): the dialog offers all other
 * contract taxonomies as radio options; applying moves the selected
 * terms along with their objects (see TermTaxonomyMover). The terms
 * form POSTs, so the handler reads the target from $_POST; the core
 * script verifies the bulk-tags nonce before the dispatch filter fires.
 */
final class TermMoveBulkAction implements Module
{
    private const ACTION = 'aiya_move_terms';
    private const PARAM_TARGET = 'aiya_bulk_move_tax';
    private const DIALOG_ID = 'aiya-move-tax-dialog';

    public function __construct(private TermTaxonomyMover $mover)
    {
    }

    public function register(): void
    {
        foreach (TermTaxonomyMover::taxonomies() as $taxonomy) {
            add_filter('bulk_actions-edit-' . $taxonomy, [$this, 'addBulkAction']);
            add_filter(
                'handle_bulk_actions-edit-' . $taxonomy,
                fn (mixed $redirect, mixed $action, mixed $ids): string => $this->handle($redirect, $action, $ids, $taxonomy),
                10,
                3
            );
        }
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_footer-edit-tags.php', [$this, 'renderDialog']);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addBulkAction(array $actions): array
    {
        $actions[self::ACTION] = __('Move to another taxonomy…', 'aiya-core');

        return $actions;
    }

    /** Dialog assets on the term screens of the contract taxonomies only. */
    public function assets(string $hook): void
    {
        if ($hook !== 'edit-tags.php') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string) $screen->taxonomy, TermTaxonomyMover::taxonomies(), true)) {
            return;
        }

        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');

        $config = [
            'form' => 'form#posts-filter',
            'checkbox' => 'delete_tags[]',
            'action' => self::ACTION,
            'targetParam' => self::PARAM_TARGET,
            'dialogId' => self::DIALOG_ID,
            'title' => __('Move to another taxonomy', 'aiya-core'),
            'confirm' => __('Move', 'aiya-core'),
            'cancel' => __('Cancel', 'aiya-core'),
            // translators: %d: number of selected terms
            'countOne' => _n('Move %d selected term to:', 'Move %d selected terms to:', 1, 'aiya-core'),
            // translators: %d: number of selected terms
            'countOther' => _n('Move %d selected term to:', 'Move %d selected terms to:', 2, 'aiya-core'),
            'labels' => self::labels(),
        ];

        wp_add_inline_script(
            'jquery-ui-dialog',
            '(function(c){' . BulkDialogBehavior::script() . '})(' . wp_json_encode($config) . ');',
            'after'
        );
    }

    /** Readable taxonomy labels for the radio options.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'category' => __('Post categories', 'aiya-core'),
            'post_tag' => __('Post tags', 'aiya-core'),
            'page_category' => __('Page categories', 'aiya-core'),
            'resource_category' => __('Resource categories', 'aiya-core'),
            'resource_original' => __('Resource tags — original work', 'aiya-core'),
            'resource_character' => __('Resource tags — characters', 'aiya-core'),
            'resource_author' => __('Resource tags — authors', 'aiya-core'),
            'resource_content' => __('Resource tags — content description', 'aiya-core'),
            'resource_other' => __('Resource tags — other', 'aiya-core'),
        ];
    }

    /** The hidden dialog markup: every other contract taxonomy as a radio. */
    public function renderDialog(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string) $screen->taxonomy, TermTaxonomyMover::taxonomies(), true)) {
            return;
        }

        $labels = self::labels();
        $current = (string) $screen->taxonomy;
        ?>
        <div id="<?php echo esc_attr(self::DIALOG_ID); ?>" style="display:none;">
            <p class="count-line" style="margin:0 0 14px;font-size:14px;"></p>
            <?php foreach (TermTaxonomyMover::targetOptions($current) as $taxonomy) : ?>
                <p style="margin:10px 0;font-size:14px;">
                    <label>
                        <input type="radio" name="<?php echo esc_attr(self::PARAM_TARGET); ?>" value="<?php echo esc_attr($taxonomy); ?>">
                        <?php echo esc_html($labels[$taxonomy] ?? $taxonomy); ?>
                    </label>
                </p>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Runs the move and reports back through the redirect query args.
     * The terms list form POSTs; core verified the bulk-tags nonce
     * before this filter fired.
     *
     * @param mixed $redirect
     * @param mixed $action
     * @param mixed $ids
     */
    private function handle(mixed $redirect, mixed $action, mixed $ids, string $sourceTaxonomy): string
    {
        if ($action !== self::ACTION) {
            return is_string($redirect) ? $redirect : '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- bulk nonce verified by wp-admin/edit-tags.php before this filter
        $target = sanitize_key((string) ($_POST[self::PARAM_TARGET] ?? ''));

        $result = $this->mover->moveTerms(
            array_values(array_map(intval(...), is_array($ids) ? $ids : [])),
            $sourceTaxonomy,
            $target
        );

        $base = is_string($redirect) && $redirect !== '' ? $redirect : (string) wp_get_referer();

        return (string) add_query_arg([
            'aiya_term_moved' => (string) $result['moved'],
            'aiya_term_skipped' => (string) $result['skipped'],
        ], $base);
    }

    public function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        if (!isset($_GET['aiya_term_moved'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counters
        $moved = absint((string) ($_GET['aiya_term_moved'] ?? '0'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto
        $skipped = absint((string) ($_GET['aiya_term_skipped'] ?? '0'));

        $messages = [];
        if ($moved > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of moved terms */
                _n('%d term moved to the new taxonomy.', '%d terms moved to the new taxonomy.', $moved, 'aiya-core'),
                $moved
            );
        }
        if ($skipped > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of skipped terms */
                _n('%d term skipped (unchanged or not permitted).', '%d terms skipped (unchanged or not permitted).', $skipped, 'aiya-core'),
                $skipped
            );
        }
        if ($messages === []) {
            $messages[] = __('Nothing was moved.', 'aiya-core');
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(implode(' ', $messages))
        );
    }
}
