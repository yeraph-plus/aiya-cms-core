<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * The one counter-notice renderer behind the list-table bulk actions:
 * reads the redirect's counters off the query string, prints a _n()'d
 * line per counter above zero (or the fallback sentence when everything
 * came back zero) inside a dismissible info notice. Each action only
 * supplies its counter map — param name → [singular, plural].
 */
final class BulkActionNotice
{
    /**
     * @param array<string, array{0: string, 1: string}> $counters param name => [singular, plural]
     */
    public static function render(array $counters, string $emptyMessage): void
    {
        $present = false;
        foreach (array_keys($counters) as $param) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
            if (isset($_GET[$param])) {
                $present = true;
                break;
            }
        }
        if (!$present) {
            return;
        }

        $messages = [];
        foreach ($counters as $param => [$singular, $plural]) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counters
            $count = absint((string) ($_GET[$param] ?? '0'));
            if ($count > 0) {
                $messages[] = sprintf(_n($singular, $plural, $count, 'aiya-core'), $count);
            }
        }
        if ($messages === []) {
            $messages[] = $emptyMessage;
        }

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(implode(' ', $messages))
        );
    }
}
