<?php

/**
 * PHPUnit bootstrap: loads composer dependencies and provides a minimal
 * WordPress shim (only the functions the unit-tested classes actually call)
 * so the unit suite runs without a WordPress installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Mirror the plugin's runtime autoloaders (Aiya\Core\ -> src/, Aiya\Infra\ ->
// packages/*/src as each package's own composer.json declares) so the unit
// suite loads plugin and package classes without booting WordPress.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aiya\\Core\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }

        return;
    }

    if (!str_starts_with($class, 'Aiya\\Infra\\')) {
        return;
    }

    $path = Aiya\Core\Runtime\Packages::locate($class);
    if ($path !== null) {
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

// --- WP_Term --------------------------------------------------------------

if (!class_exists('WP_Term')) {
    class WP_Term
    {
        /** @var array<string, mixed> */
        private array $aiya_test_props = [];

        public function __construct(?object $row = null)
        {
            foreach (get_object_vars($row ?? new \stdClass()) as $key => $value) {
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

// --- WP_User --------------------------------------------------------------

if (!class_exists('WP_User')) {
    class WP_User
    {
        /** @var array<string, mixed> */
        private array $aiya_test_props = [];

        public function __construct(?object $row = null)
        {
            foreach (get_object_vars($row ?? new \stdClass()) as $key => $value) {
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
    /**
     * Approximates core's whitelist semantics instead of a blacklist: an
     * absolute URL is kept when its scheme is in the protocol list (the
     * second parameter, as in core; default http/https), anything that reads
     * as a relative path, protocol-relative reference, query or fragment
     * carries no scheme and is kept as-is, and every other scheme
     * (javascript:, data:, ftp:, file:, ...) — including spacing tricks,
     * which core would also refuse without needing an entity decode here —
     * is stripped to ''. The earlier blacklist form could not express
     * "only these schemes", which is what callers like PlatformAdapter
     * actually ask for.
     */
    function esc_url_raw(string $url, ?array $protocols = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $slash = strpos($url, '/');
        $colon = strpos($url, ':');
        if ($colon !== false && ($slash === false || $colon < $slash)) {
            $scheme = strtolower((string) substr($url, 0, $colon));

            return in_array($scheme, $protocols ?? ['http', 'https'], true) ? $url : '';
        }

        return $url;
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return esc_url_raw($url);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html($text);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr($text);
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

// --- Shortcodes (minimal registry: the parts contract is a shortcode) ------

$GLOBALS['__aiya_test_shortcodes'] = [];

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        $GLOBALS['__aiya_test_shortcodes'][$tag] = $callback;
    }
}

if (!function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool
    {
        return isset($GLOBALS['__aiya_test_shortcodes'][$tag]);
    }
}

if (!function_exists('do_shortcode')) {
    /**
     * Simplified core behaviour: the self-closing and enclosing forms with
     * double-quoted, single-quoted or bare attributes, the [[tag]] escaped
     * form (outer brackets stripped, the text never executed), no nesting.
     * That covers every part this plugin ships (they are flat); the real
     * parser is exercised at runtime.
     */
    function do_shortcode(string $content, bool $ignoreHtml = false): string
    {
        if ($content === '' || $GLOBALS['__aiya_test_shortcodes'] === []) {
            return $content;
        }

        $tags = implode('|', array_map('preg_quote', array_keys($GLOBALS['__aiya_test_shortcodes'])));

        // Escaped tokens first: [[tag attr="x"]] renders as the literal
        // [tag attr="x"], whatever handlers are registered.
        $unescaped = preg_replace_callback(
            '/\[\[(' . $tags . ')([^\[\]]*)\]\]/',
            static fn (array $match): string => '[' . $match[1] . $match[2] . ']',
            $content
        );
        $content = is_string($unescaped) ? $unescaped : $content;

        $pattern = '/\[(' . $tags . ')((?:\s+[^\s\]]+=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))*)\s*\](?:(.*?)\[\/\1\])?/s';

        $result = preg_replace_callback($pattern, static function (array $match): string {
            $atts = [];
            if (preg_match_all('/([^\s=]+)=(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/', $match[2], $pairs, PREG_SET_ORDER) > 0) {
                foreach ($pairs as $pair) {
                    $atts[$pair[1]] = $pair[2] ?? $pair[3] ?? $pair[4] ?? '';
                }
            }

            $handler = $GLOBALS['__aiya_test_shortcodes'][$match[1]] ?? null;
            if (!is_callable($handler)) {
                return $match[0];
            }

            return (string) $handler($atts, $match[3] ?? '', $match[1]);
        }, $content);

        return is_string($result) ? $result : $content;
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

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src(int $attachment_id, string $size = 'medium'): array|false
    {
        $images = $GLOBALS['__aiya_test_attachment_images'] ?? [];
        $image = $images[$attachment_id] ?? null;
        if ($image === null) {
            return false;
        }

        // WP's numeric tuple: [url, width, height, is_intermediate].
        return [$image['url'], $image['width'], $image['height'], false];
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

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
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

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
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

if (!function_exists('delete_user_meta')) {
    function delete_user_meta(int $userId, string $key, mixed $metaValue = ''): bool
    {
        unset($GLOBALS['__aiya_test_user_meta'][$userId][$key]);
        return true;
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

if (!function_exists('get_rest_url')) {
    function get_rest_url(?int $blogId = null, string $path = '/', string $scheme = 'rest'): string
    {
        return 'https://aiya.test/wp-json' . ('/' === $path[0] ? '' : '/') . $path;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        return $show === 'name' ? 'AIYA 测试站' : '';
    }
}

if (!function_exists('rest_get_url_prefix')) {
    function rest_get_url_prefix(): string
    {
        return 'wp-json';
    }
}

if (!function_exists('user_can')) {
    function user_can(mixed $user, string $capability, mixed ...$args): bool
    {
        // Same switch as current_user_can(): the suite reads one global,
        // and the default stays permissive so unset fixtures keep passing.
        return (bool) ($GLOBALS['__aiya_test_caps'] ?? true);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        // Unit tests always present valid nonces — but an absent one is
        // still absent, and callers that distinguish (check_ajax_referer)
        // must see it fail like it would in core.
        return $nonce !== '' ? 1 : false;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string|int $action = -1): string
    {
        return 'aiya-test-nonce';
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        $field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="aiya-test-nonce" />';

        if ($echo) {
            echo $field;
        }

        return $field;
    }
}

// --- AJAX handlers (for the bespoke metabox/page endpoints) ----------------

if (!class_exists('Aiya_Test_Json_Response')) {
    /**
     * What wp_send_json_* "returns" in the shim: in real WordPress the
     * handler echoes the envelope and dies, so the call never comes back —
     * the envelope therefore travels as an exception the test catches and
     * inspects.
     */
    class Aiya_Test_Json_Response extends RuntimeException
    {
        public function __construct(public readonly bool $success, public readonly mixed $data, public readonly int $status)
        {
            parent::__construct($success ? 'wp_send_json_success' : 'wp_send_json_error');
        }

        /** @return array{success: bool, data: mixed} */
        public function body(): array
        {
            return ['success' => $this->success, 'data' => $this->data];
        }
    }
}

if (!class_exists('Aiya_Test_Abort')) {
    /** A bare die-style stop (a failed check_ajax_referer): no envelope at all. */
    class Aiya_Test_Abort extends RuntimeException
    {
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, int $statusCode = 200): never
    {
        throw new Aiya_Test_Json_Response(true, $data, $statusCode);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, int $statusCode = 200): never
    {
        throw new Aiya_Test_Json_Response(false, $data, $statusCode);
    }
}

if (!function_exists('check_ajax_referer')) {
    /**
     * Reads the nonce named by $queryArg and verifies it; a failed check
     * throws (core dies), which is exactly the "handler never got to do
     * anything" a test wants to observe. Core reads $_REQUEST only; the
     * shim falls back to $_POST/$_GET because the CLI SAPI never populates
     * $_REQUEST from them, and the tests set the superglobals directly.
     */
    function check_ajax_referer(string|int $action = -1, string|false $queryArg = false, bool $die = true): int|false
    {
        $nonce = '';
        if (is_string($queryArg)) {
            $nonce = (string) ($_REQUEST[$queryArg] ?? $_POST[$queryArg] ?? $_GET[$queryArg] ?? '');
        }
        if (wp_verify_nonce($nonce, $action) !== false) {
            return 1;
        }

        if ($die) {
            throw new Aiya_Test_Abort('check_ajax_referer: nonce failed');
        }

        return false;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $dir): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.dir_mkdir -- test double of the core helper
        return is_dir($dir) || mkdir($dir, 0777, true);
    }
}

// --- Metabox registration (for bespoke boxes) -----------------------------

$GLOBALS['__aiya_test_meta_boxes'] = [];

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, callable $callback, mixed $screen = null, string $context = 'advanced', string $priority = 'default', mixed $args = null): void
    {
        $GLOBALS['__aiya_test_meta_boxes'][] = [
            'id' => $id,
            'title' => $title,
            'screen' => $screen,
            'context' => $context,
            'priority' => $priority,
        ];
    }
}

// --- Transients (for settings save errors, rate limiting) -----------------

$GLOBALS['__aiya_test_transients'] = [];

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['__aiya_test_transients'][$key] = $value;

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['__aiya_test_transients'][$key] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['__aiya_test_transients'][$key]);

        return true;
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

        /** @var array<string, list<array<string, mixed>>>|null rows as of START TRANSACTION */
        public ?array $aiya_test_snapshot = null;

        public int $aiya_test_reads = 0;

        public int $rows_affected = 0;

        public string $last_error = '';

        /**
         * The ledger's unique keys, honoured the way MySQL answers them: a
         * repeat of a one-shot key is a false return plus a last_error the
         * caller can read as "already recorded".
         *
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data, array $formats = []): bool
        {
            $dedupe = $data['dedupe'] ?? null;
            if (is_string($dedupe) && $dedupe !== '') {
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if (($row['dedupe'] ?? null) === $dedupe
                        && (int) ($row['user_id'] ?? 0) === (int) ($data['user_id'] ?? 0)
                    ) {
                        $this->last_error = sprintf("Duplicate entry '%s' for key 'dedupe_key'", $dedupe);

                        return false;
                    }
                }
            }

            $this->last_error = '';
            $this->aiya_test_rows[$table][] = $data;

            return true;
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            $previous = $this->suppress_errors;
            $this->suppress_errors = $suppress;

            return $previous;
        }

        public bool $suppress_errors = false;

        /**
         * The bucket read the allocator plans against: rows of id/remaining
         * for the holder, live buckets only.
         *
         * @return list<array<string, mixed>>
         */
        public function get_results(string $sql, mixed $output = null): array
        {
            $this->aiya_test_reads++;
            $table = $this->aiya_test_table($sql);
            if ($table === null) {
                return [];
            }

            $rows = [];
            foreach ($this->aiya_test_rows[$table] ?? [] as $index => $row) {
                if (str_contains($sql, "direction = 'in'") && ($row['direction'] ?? '') !== 'in') {
                    continue;
                }
                if (preg_match('/user_id = (\d+)/', $sql, $user) === 1
                    && (int) ($row['user_id'] ?? 0) !== (int) $user[1]
                ) {
                    continue;
                }
                if (str_contains($sql, 'remaining > 0') && (int) ($row['remaining'] ?? 0) <= 0) {
                    continue;
                }
                if (preg_match("/expires_at > '([^']+)'/", $sql, $since) === 1
                    && is_string($row['expires_at'] ?? null)
                    && $row['expires_at'] !== ''
                    && !($row['expires_at'] > $since[1])
                ) {
                    continue;
                }

                $rows[] = [
                    'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : $index + 1,
                    'remaining' => (int) ($row['remaining'] ?? 0),
                ];
            }

            return $rows;
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

            // The ledger's balance read: one SUM over the holder's live
            // buckets, filtered exactly like the bucket read above — the
            // statement carries `expires_at IS NULL OR expires_at > now`,
            // so a bucket past its expiry counts no more here than the
            // allocator would spend it.
            if (str_contains($sql, 'SUM(remaining)')) {
                $table = $this->aiya_test_table($sql);
                preg_match("/expires_at > '([^']+)'/", $sql, $since);
                $sum = 0;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if (($row['direction'] ?? '') !== 'in' || (int) ($row['remaining'] ?? 0) <= 0) {
                        continue;
                    }
                    if (preg_match('/user_id = (\d+)/', $sql, $user) === 1
                        && (int) ($row['user_id'] ?? 0) !== (int) $user[1]
                    ) {
                        continue;
                    }
                    if ($since !== [] && is_string($row['expires_at'] ?? null)
                        && $row['expires_at'] !== ''
                        && !($row['expires_at'] > $since[1])
                    ) {
                        continue;
                    }
                    $sum += (int) $row['remaining'];
                }

                return $sum;
            }

            $row = $this->aiya_test_match($sql);

            return $row === null ? null : $row['user_id'];
        }

        public function query(string $sql): int
        {
            $this->rows_affected = 0;

            // A transaction the ledger opened: snapshot on START, restore on
            // ROLLBACK, drop the snapshot on COMMIT.
            if (str_starts_with($sql, 'START TRANSACTION')) {
                $this->aiya_test_snapshot = $this->aiya_test_rows;

                return 1;
            }
            if (str_starts_with($sql, 'ROLLBACK')) {
                if ($this->aiya_test_snapshot !== null) {
                    $this->aiya_test_rows = $this->aiya_test_snapshot;
                    $this->aiya_test_snapshot = null;
                }

                return 1;
            }
            if (str_starts_with($sql, 'COMMIT')) {
                $this->aiya_test_snapshot = null;

                return 1;
            }

            // The ledger's guarded bucket decrement: it only succeeds while the
            // row still covers the take, exactly like the real statement.
            if (preg_match('/UPDATE (\S+) SET remaining = remaining - (\d+) WHERE id = (\d+) AND remaining >= (\d+)/', $sql, $step) === 1) {
                $table = $step[1];
                foreach ($this->aiya_test_rows[$table] ?? [] as $index => $row) {
                    if ($index + 1 !== (int) $step[3] || (int) ($row['remaining'] ?? 0) < (int) $step[2]) {
                        continue;
                    }
                    $this->aiya_test_rows[$table][$index]['remaining'] = (int) $row['remaining'] - (int) $step[2];
                    $this->rows_affected = 1;

                    return 1;
                }

                return 0;
            }

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
        if (!isset($GLOBALS['__aiya_test_post_meta'][$objectId][$key])) {
            // Core (get_metadata_default) answers '' for a missing single
            // value and [] for a missing set — never a one-element array
            // wrapping the empty default, which is what this shim used to
            // say and no production code may rely on.
            return $single ? '' : [];
        }

        $value = $GLOBALS['__aiya_test_post_meta'][$objectId][$key];

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

// --- Post records (content read paths: gate consumers, presenters) --------
//
// Fixture posts are registered by assigning to $GLOBALS['__aiya_test_posts']
// (keyed by ID) — there is no wp_insert_post double. The readers below
// mirror core's field provenance: titles come off the record, excerpts off
// post_excerpt, thumbnails off _thumbnail_id meta.

$GLOBALS['__aiya_test_posts'] = [];
$GLOBALS['__aiya_test_sticky'] = [];
$GLOBALS['__aiya_test_options'] = [];

if (!function_exists('get_post')) {
    function get_post(mixed $id = null): WP_Post|array|null
    {
        if ($id instanceof WP_Post) {
            return $id;
        }

        return $GLOBALS['__aiya_test_posts'][(int) $id] ?? null;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(mixed $post = 0): string
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        return $post instanceof WP_Post ? (string) ($post->post_title ?? '') : '';
    }
}

if (!function_exists('get_the_excerpt')) {
    function get_the_excerpt(mixed $post = null): string
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        return $post instanceof WP_Post ? (string) ($post->post_excerpt ?? '') : '';
    }
}

if (!function_exists('is_sticky')) {
    function is_sticky(int $postId = 0): bool
    {
        return in_array($postId, $GLOBALS['__aiya_test_sticky'], true);
    }
}

if (!function_exists('comments_open')) {
    function comments_open(mixed $post = null): bool
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        return $post instanceof WP_Post && ($post->comment_status ?? '') === 'open';
    }
}

if (!function_exists('get_comments_number')) {
    function get_comments_number(mixed $post = 0): string
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        return $post instanceof WP_Post ? (string) ($post->comment_count ?? 0) : '0';
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(mixed $post = 0): int
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        return $post instanceof WP_Post ? (int) get_post_meta((int) $post->ID, '_thumbnail_id', true) : 0;
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

$GLOBALS['__aiya_test_post_terms'] = [];
$GLOBALS['__aiya_test_term_meta'] = [];

if (!function_exists('wp_get_post_terms')) {
    /** @return list<WP_Term>|WP_Error */
    function wp_get_post_terms(int $postId, string $taxonomy = '', array $args = []): array|WP_Error
    {
        return $GLOBALS['__aiya_test_post_terms'][$postId][$taxonomy] ?? [];
    }
}

if (!function_exists('get_term_meta')) {
    function get_term_meta(int $termId, string $key = '', bool $single = false): mixed
    {
        $value = $GLOBALS['__aiya_test_term_meta'][$termId][$key] ?? '';

        return $single ? $value : [$value];
    }
}

if (!function_exists('get_date_from_gmt')) {
    function get_date_from_gmt(string $date, string $format = 'Y-m-d H:i:s'): string
    {
        // UTC-only test double, like the wp_date shim: the suite never
        // asserts site-local rendering.
        $parsed = strtotime($date . ' UTC');

        return $parsed === false ? $date : gmdate($format, $parsed);
    }
}

if (!function_exists('post_password_required')) {
    function post_password_required(mixed $post = null): bool
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }

        // No cookie jar here: a passworded post always owes its password,
        // which is the production topology anyway (the visitor's browser
        // never talks to WordPress, so the postpass cookie never arrives).
        return $post instanceof WP_Post && (string) ($post->post_password ?? '') !== '';
    }
}

if (!function_exists('get_post_timestamp')) {
    function get_post_timestamp(mixed $post = null, string $field = 'date'): int|false
    {
        if (!($post instanceof WP_Post)) {
            $post = $GLOBALS['__aiya_test_posts'][(int) $post] ?? null;
        }
        if (!($post instanceof WP_Post)) {
            return false;
        }

        $value = (string) ($field === 'modified' ? ($post->post_modified_gmt ?? '') : ($post->post_date_gmt ?? ''));
        $parsed = strtotime($value . ' UTC');

        return $parsed === false ? false : $parsed;
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string|false
    {
        // UTC-only test double: the suite never asserts site-local rendering.
        return gmdate($format, $timestamp ?? time());
    }
}

if (!function_exists('aiya_core_opt')) {
    /**
     * The settings facade, stubbed at the boundary: fixtures assign
     * $GLOBALS['__aiya_test_options'][page][id]; anything unset answers the
     * caller's fallback, which is how the real facade behaves on a fresh
     * install.
     */
    function aiya_core_opt(string $page, string $id, mixed $fallback = null): mixed
    {
        return $GLOBALS['__aiya_test_options'][$page][$id] ?? $fallback;
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
    /**
     * Core hands action callbacks exactly the arguments do_action was given
     * (WP_Hook::apply_filters() skips `$args[0] = $value` while doing an
     * action) and slices them by accepted_args like any other callback. The
     * leading-value injection is apply_filters' business alone: the earlier
     * shim routed do_action through apply_filters, which made every
     * multi-argument listener declare a phantom first $null parameter no
     * production signature has.
     */
    function do_action(string $hook, mixed ...$args): void
    {
        $buckets = $GLOBALS['__aiya_test_filters'][$hook] ?? [];
        ksort($buckets);
        foreach ($buckets as $callbacks) {
            foreach ($callbacks as $entry) {
                call_user_func_array($entry['callback'], array_slice($args, 0, $entry['args']));
            }
        }
    }
}
