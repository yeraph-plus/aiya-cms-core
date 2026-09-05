<?php

/**
 * PHPUnit bootstrap: loads composer dependencies and provides a minimal
 * WordPress shim (only the functions the unit-tested classes actually call)
 * so the unit suite runs without a WordPress installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Mirror the plugin's runtime autoloader (Aiya\Core\ -> src/) so the unit
// suite loads plugin classes without booting WordPress.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aiya\\Core\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

if (!defined('AIYA_CORE_VERSION')) {
    define('AIYA_CORE_VERSION', '0.8.0-test');
}

// --- WP_Error ------------------------------------------------------------

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        private array $errors = [];

        /** @var array<string, mixed> */
        private array $error_data = [];

        public function __construct($code = '', $message = '', $data = null)
        {
            if ((string) $code !== '') {
                $this->errors[(string) $code][] = (string) $message;
                if ($data !== null) {
                    $this->error_data[(string) $code] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            return (string) (array_key_first($this->errors) ?? '');
        }

        /** @return list<string> */
        public function get_error_messages(string $code = ''): array
        {
            $code = $code !== '' ? $code : $this->get_error_code();

            return $this->errors[$code] ?? [];
        }

        public function get_error_message(string $code = ''): string
        {
            $messages = $this->get_error_messages($code);

            return $messages[0] ?? '';
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }

        public function get_error_data(string $code = ''): mixed
        {
            $code = $code !== '' ? $code : $this->get_error_code();

            return $this->error_data[$code] ?? null;
        }
    }
}

// --- Sanitizing / formatting --------------------------------------------

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        $filtered = strip_tags($str);
        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered) ?? '';

        return trim($filtered);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        $filtered = strip_tags($str);
        return preg_replace('/[ \t]+/', ' ', $filtered) ?? '';
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? strtolower($email) : '';
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        $url = trim($url);
        return preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : '';
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

// --- Options and filters (for the schema version runner) ------------------

$GLOBALS['__aiya_test_options'] = [];
$GLOBALS['__aiya_test_filters'] = [];

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__aiya_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['__aiya_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value, string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($name, $GLOBALS['__aiya_test_options'])) {
            return false;
        }
        $GLOBALS['__aiya_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__aiya_test_options'][$name]);
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        $GLOBALS['__aiya_test_filters'][$hook][$priority][] = ['callback' => $callback, 'args' => $acceptedArgs];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        return add_filter($hook, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $buckets = $GLOBALS['__aiya_test_filters'][$hook] ?? [];
        ksort($buckets);
        foreach ($buckets as $callbacks) {
            foreach ($callbacks as $entry) {
                $value = call_user_func_array($entry['callback'], array_merge([$value], array_slice($args, 0, $entry['args'] - 1)));
            }
        }
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        apply_filters($hook, null, ...$args);
    }
}
