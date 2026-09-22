<?php

/**
 * Unit-suite shims for the WordPress functions the metabox/profile save
 * and render paths call that tests/bootstrap.php does not provide (yet).
 * Global namespace, every definition guarded — a bootstrap addition wins
 * by load order without redefinition fatals.
 *
 * Lives OUTSIDE tests/Unit so PHPUnit's directory scan never picks it up
 * (a class-less file there would raise a "could not be found" warning on
 * every run); the test classes that need it require it explicitly.
 */

declare(strict_types=1);

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string|int $action = -1, string $queryArg = '_wpnonce'): bool
    {
        return true; // the unit suite has no referer to verify
    }
}

if (!function_exists('update_term_meta')) {
    function update_term_meta(int $termId, string $metaKey, mixed $metaValue, mixed $prevValue = ''): bool
    {
        $GLOBALS['__aiya_test_term_meta'][$termId][$metaKey] = $metaValue;

        return true;
    }
}

if (!function_exists('delete_term_meta')) {
    function delete_term_meta(int $termId, string $metaKey): bool
    {
        unset($GLOBALS['__aiya_test_term_meta'][$termId][$metaKey]);

        return true;
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $html = (string) $checked === (string) $current ? " checked='checked'" : '';
        if ($display) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}
