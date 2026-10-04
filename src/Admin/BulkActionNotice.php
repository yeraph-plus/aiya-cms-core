<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * The one counter-notice renderer behind the list-table bulk actions:
 * reads the redirect's counters off the query string, prints a _n()'d
 * line per counter above zero (or the fallback sentence when everything
 * came back zero) inside a dismissible info notice. Each action only
 * supplies its counter map — param name → line factory; keeping the _n()
 * literals at the call site is what leaves them visible to the i18n
 * extractor (and to the literal-string sniff).
 */
final class BulkActionNotice
{
    /**
     * @param array<string, callable(int): string> $counters param name => notice line factory (receives the parsed count)
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
        foreach ($counters as $param => $line) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counters
            $count = absint((string) ($_GET[$param] ?? '0'));
            if ($count > 0) {
                $messages[] = $line($count);
            }
        }
        if ($messages === []) {
            $messages[] = $emptyMessage;
        }

        Ui::notice(implode(' ', $messages), ['variant' => 'info', 'dismissible' => true]);
    }
}
