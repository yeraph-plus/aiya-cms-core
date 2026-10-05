<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Closure;

/**
 * Registered shortcodes screen (the inspection half of the WPJAM Basic
 * 常用简码 page): every shortcode in the global registry with a readable
 * callback label. Purely diagnostic — this site renders shortcodes
 * through the Shortcodes framework, so unlike WPJAM this page registers no
 * shortcodes of its own.
 */
final class ShortcodesPage
{
    private const MENU_SUFFIX = 'aiya-core-devtools-shortcodes';
    private const DEFAULT_PER_PAGE = 50;
    private const PER_PAGE_CHOICES = [10, 20, 50, 100];

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the shortcode registry.', 'aiya-core'));
        }

        Ui::pageHead(
            __('Shortcodes', 'aiya-core'),
            __('Every shortcode registered in this request, with its callback. Content bodies may reference these; rendering stays a front-end concern.', 'aiya-core')
        );
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

        $rows = [];
        foreach ($GLOBALS['shortcode_tags'] ?? [] as $tag => $callback) {
            $rows[] = ['tag' => (string) $tag, 'callback' => self::callbackLabel($callback)];
        }

        $total = count($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['tag'], $search) || str_contains($row['callback'], $search)));
        }
        $filtered = count($rows);
        $totalPages = max(1, (int) ceil($filtered / $perPage));
        $paged = min($paged, $totalPages);
        $rows = array_slice($rows, ($paged - 1) * $perPage, $perPage);
        Ui::heading(sprintf(
            /* translators: %d: number of registered shortcodes */
            esc_html__('Registered shortcodes (%d)', 'aiya-core'),
            (int) $total
        ));
        $navArgs = ['jump_nav' => true, 'per_page_nav' => true, 'per_page_choices' => self::PER_PAGE_CHOICES];
        Ui::listNav($filtered, $paged, $perPage, 'top', $navArgs + [
            'actions' => static function () use ($search): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($search): void {
                    Ui::input('s', 'search', $search, ['placeholder' => __('Filter by tag or callback…', 'aiya-core')]);
                }, ['page' => self::MENU_SUFFIX]);
            },
        ]);
        Ui::listTable(
            [
                'tag' => ['label' => __('Tag', 'aiya-core'), 'width' => '30%'],
                'callback' => ['label' => __('Callback', 'aiya-core')],
            ],
            $rows,
            static function (array $row, string $column): void {
                echo '<code>' . esc_html($row[$column]) . '</code>';
            },
            __('No shortcodes match.', 'aiya-core')
        );
        Ui::listNav($filtered, $paged, $perPage, 'bottom', $navArgs);
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
