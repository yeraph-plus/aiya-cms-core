<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

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
    private const PER_PAGE = 50;

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_FLUSH, [$this, 'handleFlush']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the rewrite rules.', 'aiya-core'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Rewrites', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('The cached rewrite rules of this site — what a request path is matched against before any rule regeneration.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>
            <p>
                <?php
                $flushUrl = wp_nonce_url(
                    admin_url('admin-post.php?action=' . self::ACTION_FLUSH),
                    self::ACTION_FLUSH
                );
                ?>
                <a href="<?php echo esc_url($flushUrl); ?>" class="button" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Regenerate all rewrite rules from the current settings?', 'aiya-core'))); ?>);"><?php esc_html_e('Flush rules', 'aiya-core'); ?></a>
                <span class="description"><?php esc_html_e('Rebuilds the cached rule set from the registered permalinks, post types and taxonomies.', 'aiya-core'); ?></span>
            </p>
            <?php $this->listSection(); ?>
        </div>
        <?php
    }

    private function listSection(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));

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
        $pages = (int) ceil($filtered / self::PER_PAGE);
        $rows = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        ?>
        <h2 class="title" style="margin-top:24px;">
            <?php
            printf(
                /* translators: %d: number of rewrite rules */
                esc_html__('Rewrite rules (%d)', 'aiya-core'),
                (int) $total
            );
            ?>
        </h2>
        <form method="get" class="aiya-core-filters" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SUFFIX); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Filter by regex or query…', 'aiya-core'); ?>">
            <button type="submit" class="button"><?php esc_html_e('Filter', 'aiya-core'); ?></button>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:45%;"><?php esc_html_e('Match regex', 'aiya-core'); ?></th>
                    <th><?php esc_html_e('Rewrites to query', 'aiya-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="2"><?php esc_html_e('No rewrite rules match.', 'aiya-core'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row['regex']); ?></code></td>
                            <td><code><?php echo esc_html($row['query']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo wp_kses_post(
                (string) paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ])
            );
            echo '</div></div>';
        }
    }

    private function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_devtools_note'] ?? ''));
        $messages = [
            'rewrite_flushed' => __('Rewrite rules regenerated.', 'aiya-core'),
        ];

        if (!isset($messages[$note])) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($messages[$note])
        );
    }

    public function handleFlush(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to flush the rewrite rules.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_FLUSH);

        flush_rewrite_rules();

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SUFFIX, 'aiya_devtools_note' => 'rewrite_flushed'],
            admin_url('admin.php')
        ));
        exit;
    }
}
