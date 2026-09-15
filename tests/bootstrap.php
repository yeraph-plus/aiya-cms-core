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

if (!defined('AIYA_CORE_URL')) {
    define('AIYA_CORE_URL', 'https://aiya.test/wp-content/plugins/aiya-core/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/aiya-test-content');
}

if (!defined('WP_CONTENT_URL')) {
    define('WP_CONTENT_URL', 'https://aiya.test/wp-content');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// --- WP_Error ------------------------------------------------------------

// --- WP_Post --------------------------------------------------------------

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        /** @var array<string, mixed> */
        private array $aiya_test_props = [];

        public function __construct(object $row)
        {
            foreach (get_object_vars($row) as $key => $value) {
                $this->aiya_test_props[$key] = $value;
            }
        }

        public function __get(string $name): mixed
        {
            return $this->aiya_test_props[$name] ?? '';
        }

        public function __set(string $name, mixed $value): void
        {
            $this->aiya_test_props[$name] = $value;
        }

        public function __isset(string $name): bool
        {
            return isset($this->aiya_test_props[$name]);
        }
    }
}

if (!class_exists('WP_Error')) {    class WP_Error
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

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return esc_url_raw($url);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode(string $string, int $quoteStyle = ENT_QUOTES): string
    {
        // Core decodes twice by design so double-encoded entities unwind;
        // mirror that with two passes.
        $decoded = htmlspecialchars_decode($string, $quoteStyle);

        return htmlspecialchars_decode($decoded, $quoteStyle);
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content, bool $ignoreHtml = false): string
    {
        return $content; // the unit suite never registers shortcodes
    }
}

if (!function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        $base = rtrim(WP_CONTENT_URL, '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_html_split')) {
    function wp_html_split(string $input): array
    {
        // Mirrors the core split shape: markup runs (comments, CDATA,
        // tags — the trailing > optional for an unclosed <) occupy odd
        // offsets, plain text the even ones.
        $parts = preg_split(
            '/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<[^>]*>?)/s',
            $input,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        return $parts === false ? [] : $parts;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://aiya.test' . $path;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $string, bool $remove_breaks = false): string
    {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $string) ?? $string;
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[
	 ]+/', ' ', $string) ?? $string;
        }
        return trim($string);
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

if (!function_exists('wp_slash')) {
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = wp_slash($item);
            }
            return $value;
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = wp_unslash($item);
            }
            return $value;
        }
        // Mirrors core: wp_unslash -> stripslashes_deep -> stripslashes.
        return is_string($value) ? stripslashes($value) : $value;
    }
}

// --- Object cache (for TokenStore / SitePresenter / SmiliesRegistry) ------

$GLOBALS['__aiya_test_object_cache'] = [];

if (!function_exists('wp_cache_get')) {
    function wp_cache_get(string|int $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
    {
        $entry = $GLOBALS['__aiya_test_object_cache'][$group][$key] ?? null;
        if ($entry === null || ($entry['expires'] > 0 && $entry['expires'] <= time())) {
            $found = false;
            return false;
        }

        $found = true;
        return $entry['value'];
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set(string|int $key, mixed $value, string $group = '', int $expires = 0): bool
    {
        $GLOBALS['__aiya_test_object_cache'][$group][$key] = [
            'value' => $value,
            'expires' => $expires > 0 ? time() + $expires : 0,
        ];
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string|int $key, string $group = ''): bool
    {
        $existed = isset($GLOBALS['__aiya_test_object_cache'][$group][$key]);
        unset($GLOBALS['__aiya_test_object_cache'][$group][$key]);
        return $existed;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        $GLOBALS['__aiya_test_object_cache'] = [];
        return true;
    }
}

// --- Users and user meta (for TokenStore) ---------------------------------

$GLOBALS['__aiya_test_users'] = [];
$GLOBALS['__aiya_test_user_meta'] = [];

if (!function_exists('get_userdata')) {
    function get_userdata(int $userId): object|false
    {
        return isset($GLOBALS['__aiya_test_users'][$userId]) ? (object) ['ID' => $userId] : false;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        return substr(bin2hex(random_bytes(48)), 0, $length);
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key, bool $single = false): mixed
    {
        $value = $GLOBALS['__aiya_test_user_meta'][$userId][$key] ?? '';
        return $single ? $value : [$value];
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta(int $userId, string $key, mixed $value): bool
    {
        $GLOBALS['__aiya_test_user_meta'][$userId][$key] = $value;
        return true;
    }
}

if (!function_exists('add_user_meta')) {
    function add_user_meta(int $userId, string $key, mixed $value, bool $unique = false): int|false
    {
        $GLOBALS['__aiya_test_user_meta'][$userId][$key] = $value;
        return $GLOBALS['__aiya_test_user_meta'][$userId][$key] !== '' ? $userId : false;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'aiya-test-salt';
    }
}

$GLOBALS['__aiya_test_current_user_id'] = 0;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['__aiya_test_current_user_id'];
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int|WP_Post $post): int|false
    {
        return false; // revisions never exist in the unit suite
    }
}

if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int|WP_Post $post): int|false
    {
        return false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return (bool) ($GLOBALS['__aiya_test_caps'] ?? true);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        return 1; // unit tests always present valid nonces
    }
}

// --- wpdb stand-in (for TokenStore) ----------------------------------------
// Intercepts the exact statement shapes TokenStore issues against the
// token table; anything else (e.g. the per-user trim DELETE, which the
// tests never exercise) is a counted no-op.

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';

        public string $usermeta = 'wp_usermeta';

        /** @var array<string, list<array<string, mixed>>> */
        public array $aiya_test_rows = [];

        public int $aiya_test_reads = 0;

        public int $rows_affected = 0;

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data, array $formats = []): bool
        {
            $this->aiya_test_rows[$table][] = $data;

            return true;
        }

        public function prepare(string $sql, mixed ...$args): string
        {
            // Like core, %s substitutes quoted; %i and %d go in bare (the
            // values these statements carry contain no quotes themselves).
            $index = 0;

            return (string) preg_replace_callback(
                '/%[ids]/',
                static function (array $match) use (&$index, $args): string {
                    $arg = (string) $args[$index++];

                    return $match[0] === '%s' ? "'" . $arg . "'" : $arg;
                },
                $sql
            );
        }

        public function get_var(string $sql): mixed
        {
            $this->aiya_test_reads++;
            $row = $this->aiya_test_match($sql);

            return $row === null ? null : $row['user_id'];
        }

        public function query(string $sql): int
        {
            $this->rows_affected = 0;
            $table = $this->aiya_test_table($sql);
            if ($table === null || str_contains($sql, 'expires_at <')) {
                return 0; // trim sweep: not simulated, the tests never need it
            }

            if (preg_match("/token_hash = '([^']+)'/", $sql, $hash) === 1) {
                $rows = array_values(array_filter(
                    $this->aiya_test_rows[$table] ?? [],
                    static fn (array $row): bool => $row['token_hash'] !== $hash[1]
                ));
                $before = count($this->aiya_test_rows[$table] ?? []);
                $this->aiya_test_rows[$table] = $rows;

                return $before - count($rows);
            }

            if (preg_match('/WHERE user_id = (\d+)$/', $sql, $user) === 1) {
                $rows = array_values(array_filter(
                    $this->aiya_test_rows[$table] ?? [],
                    static fn (array $row): bool => (int) $row['user_id'] !== (int) $user[1]
                ));
                $before = count($this->aiya_test_rows[$table] ?? []);
                $this->aiya_test_rows[$table] = $rows;

                return $before - count($rows);
            }

            return 0;
        }

        /** @return array<string, mixed>|null */
        private function aiya_test_match(string $sql): ?array
        {
            $table = $this->aiya_test_table($sql);
            preg_match("/token_hash = '([^']+)'/", $sql, $hash);
            if ($table === null || $hash === []) {
                return null;
            }

            preg_match("/expires_at > '([^']+)'/", $sql, $since);
            foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                if ($row['token_hash'] !== $hash[1]) {
                    continue;
                }
                if ($since !== [] && !((string) $row['expires_at'] > $since[1])) {
                    return null; // expired: the row exists but the read misses it
                }

                return $row;
            }

            return null;
        }

        private function aiya_test_table(string $sql): ?string
        {
            if (preg_match('/FROM (\S+)/', $sql, $table) !== 1) {
                return null;
            }

            return $table[1];
        }
    }
}

// --- Post meta store (for PostMetaStore) ---------------------------------

$GLOBALS['__aiya_test_post_meta'] = [];

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $objectId, string $key, mixed $value): bool
    {
        // Mirrors core: the meta API unslashes incoming (slashed) values
        // before persisting them.
        $GLOBALS['__aiya_test_post_meta'][$objectId][$key] = wp_unslash($value);
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $objectId, string $key, bool $single = false): mixed
    {
        $value = $GLOBALS['__aiya_test_post_meta'][$objectId][$key] ?? '';
        return $single ? $value : [$value];
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $objectId, string $key): bool
    {
        unset($GLOBALS['__aiya_test_post_meta'][$objectId][$key]);
        return true;
    }
}

// --- Term queries (for OptionsResolver) -----------------------------------

$GLOBALS['__aiya_test_terms'] = [];

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return array_key_exists($taxonomy, $GLOBALS['__aiya_test_terms']);
    }
}

if (!function_exists('get_terms')) {
    function get_terms(array $args = []): mixed
    {
        $taxonomy = (string) ($args['taxonomy'] ?? '');
        $terms = $GLOBALS['__aiya_test_terms'][$taxonomy] ?? [];
        return $terms === [] ? [] : $terms;
    }
}

// --- Options and filters (for the schema version runner) ------------------

$GLOBALS['__aiya_test_options'] = [];
$GLOBALS['__aiya_test_filters'] = [];
$GLOBALS['__aiya_test_theme_features'] = [];

if (!function_exists('add_theme_support')) {
    function add_theme_support(string $feature, mixed ...$args): void
    {
        $GLOBALS['__aiya_test_theme_features'][$feature] = $args === [] ? true : $args;
    }
}

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
