<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * Rewrite rules screen (the inspection half of the WPJAM Basic Rewrite
 * 优化 page): a searchable, paginated view of the cached rewrite rules
 * plus a manual flush button. WPJAM's rule-removal settings were not
 * ported — rule trimming belongs to the Optimization domain that already
 * owns the corresponding switches.
 */
final class RewritesPage
{
    private const MENU_SUFFIX = 'aiya-core-devtools-rewrites';
    private const ACTION_FLUSH = 'aiya_core_devtools_rewrite_flush';
    private const DEFAULT_PER_PAGE = 50;
    private const PER_PAGE_CHOICES = [10, 20, 50, 100];

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_FLUSH, [$this, 'handleFlush']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the rewrite rules.', 'aiya-core'));
        }

        Ui::pageHead(
            __('Permalinks', 'aiya-core'),
            __('The cached rewrite rules of this site — what a request path is matched against before any rule regeneration.', 'aiya-core')
        );
        Ui::flash('aiya_devtools_note', [
            'rewrite_flushed' => [__('Rewrite rules regenerated.', 'aiya-core'), 'success'],
        ]);
        $flushUrl = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION_FLUSH),
            self::ACTION_FLUSH
        );
        ?>
        <p>
            <a href="<?php echo esc_url($flushUrl); ?>" class="button" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Regenerate all rewrite rules from the current settings?', 'aiya-core'))); ?>);"><?php esc_html_e('Flush rules', 'aiya-core'); ?></a>
            <span class="description"><?php esc_html_e('Rebuilds the cached rule set from the registered permalinks, post types and taxonomies.', 'aiya-core'); ?></span>
        </p>
        <?php
        $this->listSection();
        Ui::pageFoot();
    }

    private function listSection(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page size
        $requested = (int) ($_GET['per_page'] ?? (string) self::DEFAULT_PER_PAGE);
        $perPage = in_array($requested, self::PER_PAGE_CHOICES, true) ? $requested : self::DEFAULT_PER_PAGE;

        $rules = get_option('rewrite_rules');
        $rules = is_array($rules) ? $rules : [];
        $rows = [];
        foreach ($rules as $regex => $query) {
            $rows[] = ['regex' => (string) $regex, 'query' => (string) $query];
        }

        $total = count($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['regex'], $search) || str_contains($row['query'], $search)));
        }
        $filtered = count($rows);
        $totalPages = max(1, (int) ceil($filtered / $perPage));
        $paged = min($paged, $totalPages);
        $rows = array_slice($rows, ($paged - 1) * $perPage, $perPage);
        Ui::heading(sprintf(
            /* translators: %d: number of rewrite rules */
            esc_html__('Rewrite rules (%d)', 'aiya-core'),
            (int) $total
        ));
        $navArgs = ['jump_nav' => true, 'per_page_nav' => true, 'per_page_choices' => self::PER_PAGE_CHOICES];
        Ui::listNav($filtered, $paged, $perPage, 'top', $navArgs + [
            'actions' => static function () use ($search): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($search): void {
                    Ui::input('s', 'search', $search, ['placeholder' => __('Filter by regex or query…', 'aiya-core')]);
                }, ['page' => self::MENU_SUFFIX]);
            },
        ]);
        Ui::listTable(
            [
                'regex' => ['label' => __('Match regex', 'aiya-core'), 'width' => '45%'],
                'query' => ['label' => __('Rewrites to query', 'aiya-core')],
            ],
            $rows,
            static function (array $row, string $column): void {
                echo '<code>' . esc_html($row[$column]) . '</code>';
            },
            __('No rewrite rules match.', 'aiya-core')
        );
        Ui::listNav($filtered, $paged, $perPage, 'bottom', $navArgs);
    }

    public function handleFlush(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to flush the rewrite rules.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_FLUSH);

        flush_rewrite_rules();

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'rewrite_flushed']);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SUFFIX);
    }
}
