<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

use Closure;

/**
 * Registered shortcodes screen (the inspection half of the WPJAM Basic
 * 常用简码 page): every shortcode in the global registry with a readable
 * callback label. Purely diagnostic — this site renders shortcodes
 * through the Parts framework, so unlike WPJAM this page registers no
 * shortcodes of its own.
 */
final class ShortcodesPage
{
    private const MENU_SUFFIX = 'aiya-core-devtools-shortcodes';
    private const PER_PAGE = 50;

    public function register(): void
    {
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the shortcode registry.', 'aiya-core'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Shortcodes', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Every shortcode registered in this request, with its callback. Content bodies may reference these; rendering stays a front-end concern.', 'aiya-core'); ?></p>
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

        $rows = [];
        foreach ($GLOBALS['shortcode_tags'] ?? [] as $tag => $callback) {
            $rows[] = ['tag' => (string) $tag, 'callback' => self::callbackLabel($callback)];
        }

        $total = count($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['tag'], $search) || str_contains($row['callback'], $search)));
        }
        $filtered = count($rows);
        $pages = (int) ceil($filtered / self::PER_PAGE);
        $rows = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        ?>
        <h2 class="title" style="margin-top:24px;">
            <?php
            printf(
                /* translators: %d: number of registered shortcodes */
                esc_html__('Registered shortcodes (%d)', 'aiya-core'),
                (int) $total
            );
            ?>
        </h2>
        <form method="get" class="aiya-core-filters" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SUFFIX); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Filter by tag or callback…', 'aiya-core'); ?>">
            <button type="submit" class="button"><?php esc_html_e('Filter', 'aiya-core'); ?></button>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:30%;"><?php esc_html_e('Tag', 'aiya-core'); ?></th>
                    <th><?php esc_html_e('Callback', 'aiya-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="2"><?php esc_html_e('No shortcodes match.', 'aiya-core'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><code><?php echo esc_html($row['tag']); ?></code></td>
                            <td><code><?php echo esc_html($row['callback']); ?></code></td>
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

    /**
     * Human-readable callback identity: plain functions as-is,
     * `[class, method]` pairs as `Class::method`, closures as `Closure`,
     * anything else as its short type.
     */
    public static function callbackLabel(mixed $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if ($callback instanceof Closure) {
            return 'Closure';
        }
        if (is_array($callback) && count($callback) === 2) {
            [$class, $method] = $callback;
            $className = is_object($class) ? get_class($class) : (string) $class;

            return $className . '::' . (string) $method;
        }
        if (is_object($callback)) {
            return get_class($callback);
        }

        return get_debug_type($callback);
    }
}
