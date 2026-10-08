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

        /** Core's WP_User answers this on every instance; NotificationController
            reads it on whatever wp_get_current_user hands over. */
        public function exists(): bool
        {
            return (int) ($this->aiya_test_props['ID'] ?? 0) > 0;
        }
    }
}

// --- WP_Admin_Bar ----------------------------------------------------------

if (!class_exists('WP_Admin_Bar')) {
    /**
     * Minimal recorder double: captures add_node() payloads so tests can
     * assert toolbar nodes without a full admin chrome.
     */
    class WP_Admin_Bar
    {
        /** @var array<int, array<string, mixed>> */
        public array $nodes = [];

        public function add_node(array $args): void
        {
            $this->nodes[] = $args;
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

// --- WP_Comment -----------------------------------------------------------

if (!class_exists('WP_Comment')) {
    class WP_Comment
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

if (!function_exists('get_comment')) {
    function get_comment(mixed $comment = null): WP_Comment|array|null
    {
        if ($comment instanceof WP_Comment) {
            return $comment;
        }

        return $GLOBALS['__aiya_test_comments'][(int) $comment] ?? null;
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $numWords = 55, string $more = '…'): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($words) <= $numWords) {
            return trim($text);
        }

        return implode(' ', array_slice($words, 0, $numWords)) . $more;
    }
}

if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta(string $field = '', int $userId = 0): string
    {
        $value = $GLOBALS['__aiya_test_user_meta'][$userId][$field] ?? '';

        return is_scalar($value) ? (string) $value : '';
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

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
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

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class, string $fallback = ''): string
    {
        // Mirrors core: strip percent-encoding pairs, then everything
        // outside [A-Za-z0-9_-]; empty results may take the fallback.
        $sanitized = preg_replace('|%[a-f0-9][a-f0-9]|i', '', $class) ?? '';
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '', $sanitized) ?? '';
        if ($sanitized === '' && $fallback !== '') {
            return $fallback;
        }

        return $sanitized;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? " selected='selected'" : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $result = (string) $checked === (string) $current ? " checked='checked'" : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('remove_query_arg')) {
    function remove_query_arg(string|array $keys, string|false $uri = false): string
    {
        $uri = $uri === false ? ($_SERVER['REQUEST_URI'] ?? '') : $uri;
        $pos = strpos($uri, '?');
        if ($pos === false) {
            return $uri;
        }
        $base = substr($uri, 0, $pos);
        parse_str(substr($uri, $pos + 1), $query);
        foreach ((array) $keys as $key) {
            unset($query[$key]);
        }
        $remainder = http_build_query($query);

        return $remainder === '' ? $base : $base . '?' . $remainder;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float|int $number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
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

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
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

// --- Attachment URL plumbing (avatar default migration + resolution) ------
//
// Fixtures assign $GLOBALS['__aiya_test_attachment_urls'] (URL → id, for
// attachment_url_to_postid) and $GLOBALS['__aiya_test_attachment_files']
// (id → URL, for wp_get_attachment_url); missing entries answer WP's
// "nothing there" shapes (0 and false).

if (!function_exists('attachment_url_to_postid')) {
    function attachment_url_to_postid(string $url): int
    {
        $map = $GLOBALS['__aiya_test_attachment_urls'] ?? [];

        return isset($map[$url]) ? (int) $map[$url] : 0;
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string|false
    {
        $map = $GLOBALS['__aiya_test_attachment_files'] ?? [];

        return isset($map[$attachment_id]) ? (string) $map[$attachment_id] : false;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('add_query_arg')) {
    /**
     * Array-first form only: the suite's callers hand the query args as
     * an array plus the target URL, and the real function keeps the
     * array order.
     */
    function add_query_arg(array $args, string $uri = ''): string
    {
        $query = http_build_query($args);
        if ($query === '') {
            return $uri;
        }

        return $uri . (str_contains($uri, '?') ? '&' : '?') . $query;
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

if (!function_exists('wp_get_object_terms')) {
    /**
     * The term names of the seeded vocabularies: fixtures fill
     * $GLOBALS['__aiya_test_terms'][taxonomy] with objects or arrays
     * carrying a name. Object relations are not modeled — the consumers
     * in the suite (the push template's term placeholders) read whole
     * vocabularies.
     */
    function wp_get_object_terms(mixed $objectIds, array|string $taxonomies, array|string $args = []): array
    {
        $names = [];
        foreach ((array) $taxonomies as $taxonomy) {
            foreach ($GLOBALS['__aiya_test_terms'][(string) $taxonomy] ?? [] as $term) {
                $name = is_object($term) ? ($term->name ?? '') : (is_array($term) ? ($term['name'] ?? '') : '');
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}

if (!function_exists('wp_kses')) {
    /**
     * An allowlist approximation of core's kses: script/style blocks go
     * with their content, every non-allowed tag is dropped while its
     * inner text stays. Enough fidelity for the whitelist assertions the
     * comment read/write paths pin (the canonical definition, previously
     * a CommentsControllerTest-local guard that lost the load-order race
     * the moment a second consumer appeared).
     *
     * @param array<string, mixed> $allowedHtml
     * @param list<string> $allowedProtocols
     */
    function wp_kses(string $content, array $allowedHtml = [], array $allowedProtocols = []): string
    {
        $allowed = array_map('strtolower', array_keys($allowedHtml));
        $content = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@si', '', $content) ?? $content;

        return (string) preg_replace_callback(
            '/<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\/*>/',
            static function (array $match) use ($allowed): string {
                return in_array(strtolower($match[1]), $allowed, true) ? $match[0] : '';
            },
            $content
        );
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

if (!function_exists('wpautop')) {
    /** Plain-text messages hit this before the shell: double newlines
        separate paragraphs, single ones break lines (core semantics for
        the text-only shapes these messages carry). */
    function wpautop(string $text, bool $br = true): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text)) ?: [];
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $out[] = '<p>' . ($br ? nl2br($paragraph) : $paragraph) . '</p>';
        }

        return implode("\n", $out);
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file(int $attachmentId): string|false
    {
        return $GLOBALS['__aiya_test_attached_files'][$attachmentId] ?? false;
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

if (!function_exists('wp_cache_add')) {
    /**
     * The atomic twin the integration ticket's single-use guard rides:
     * fails when the key already holds an unexpired value, claims the
     * slot otherwise.
     */
    function wp_cache_add(string|int $key, mixed $value, string $group = '', int $expires = 0): bool
    {
        $existing = $GLOBALS['__aiya_test_object_cache'][$group][$key] ?? null;
        if ($existing !== null && ($existing['expires'] <= 0 || $existing['expires'] > time())) {
            return false;
        }
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
    function get_userdata(int $userId): WP_User|false
    {
        if (!isset($GLOBALS['__aiya_test_users'][$userId])) {
            return false;
        }
        // Fixtures may store field overrides (e.g. display_name) as an
        // array; anything else is just an existence marker. Core answers a
        // WP_User and consumers (AuthController's session gate) read it as
        // one — a bare stdClass here would flip them onto failure paths.
        $stored = $GLOBALS['__aiya_test_users'][$userId];
        $fields = is_array($stored) ? $stored : [];
        if (!isset($fields['ID'])) {
            $fields = ['ID' => $userId] + $fields;
        }

        return new WP_User((object) $fields);
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        return substr(bin2hex(random_bytes(48)), 0, $length);
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string|int
    {
        if ('timestamp' === $type || 'U' === $type) {
            return $gmt ? time() : time() + (int) ((float) get_option('gmt_offset') * HOUR_IN_SECONDS);
        }
        if ('mysql' === $type) {
            $type = 'Y-m-d H:i:s';
        }
        $timezone = $gmt ? new DateTimeZone('UTC') : wp_timezone();
        $datetime = new DateTime('now', $timezone);

        return $datetime->format($type);
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
        // Mirrors core: the meta API unslashes incoming (slashed) values
        // before persisting them.
        $GLOBALS['__aiya_test_user_meta'][$userId][$key] = wp_unslash($value);
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

if (!function_exists('wp_hash')) {
    /** Core semantics: keyed md5 over the salt for the given scheme. */
    function wp_hash(string $data, string $scheme = 'session'): string
    {
        return hash_hmac('md5', $data, wp_salt($scheme));
    }
}

$GLOBALS['__aiya_test_current_user_id'] = 0;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['__aiya_test_current_user_id'];
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return get_current_user_id() > 0;
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

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

if (!function_exists('get_avatar_url')) {
    function get_avatar_url(mixed $idOrEmail, array $args = []): string|false
    {
        $key = is_int($idOrEmail) ? (string) $idOrEmail : (string) $idOrEmail;

        return $GLOBALS['__aiya_test_avatar_urls'][$key] ?? false;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, mixed $value): WP_User|false
    {
        foreach (($GLOBALS['__aiya_test_users'] ?? []) as $user) {
            $match = match ($field) {
                'id' => (int) $user->ID === (int) $value,
                'slug' => (string) $user->user_nicename === (string) $value,
                'login' => (string) $user->user_login === (string) $value,
                'email' => (string) $user->user_email === (string) $value,
                default => false,
            };
            if ($match) {
                return $user;
            }
        }

        return false;
    }
}

if (!function_exists('get_locale')) {
    function get_locale(): string
    {
        return (string) ($GLOBALS['__aiya_test_locale'] ?? 'zh_CN');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        // Overridable per test: $GLOBALS['__aiya_test_bloginfo'][$show].
        return (string) ($GLOBALS['__aiya_test_bloginfo'][$show] ?? ($show === 'name' ? 'AIYA 测试站' : ''));
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

        public string $postmeta = 'wp_postmeta';

        public string $posts = 'wp_posts';

        public string $term_relationships = 'wp_term_relationships';

        public string $users = 'wp_users';

        /** @var array<string, list<array<string, mixed>>> */
        public array $aiya_test_rows = [];

        /** @var array<string, list<array<string, mixed>>>|null rows as of START TRANSACTION */
        public ?array $aiya_test_snapshot = null;

        public int $aiya_test_reads = 0;

        public int $rows_affected = 0;

        /** Last auto-increment id the stand-in "issued" (row count per table). */
        public int $insert_id = 0;

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

            // The discussion-likes unique actor key: a repeat of a
            // (thread, user) pair is the "already liked" no-op, exactly
            // like the real table answers an INSERT on that key.
            if (str_contains($table, 'discussion_likes')) {
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['thread_id'] ?? 0) === (int) ($data['thread_id'] ?? 0)
                        && (int) ($row['user_id'] ?? 0) === (int) ($data['user_id'] ?? 0)
                    ) {
                        $this->last_error = "Duplicate entry 'actor' for key 'actor'";

                        return false;
                    }
                }
            }

            $this->last_error = '';
            // Rows carry their auto-increment id like a real table, so
            // write-through updates can address them by primary key.
            $this->aiya_test_rows[$table][] = ['id' => count($this->aiya_test_rows[$table] ?? []) + 1] + $data;
            $this->insert_id = count($this->aiya_test_rows[$table]);

            return true;
        }

        /** Write-through UPDATE: every seeded row matching the where pairs
         *  merges the data set. No affected-rows semantics — callers in the
         *  suite only need the merge to land. */
        public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): bool
        {
            foreach (($this->aiya_test_rows[$table] ?? []) as $index => $row) {
                foreach ($where as $key => $value) {
                    if ((string) ($row[$key] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }
                $this->aiya_test_rows[$table][$index] = array_merge($row, $data);
            }
            $this->last_error = '';

            return true;
        }

        /** Write-through DELETE: removes every seeded row matching the
         * where pairs and answers the removal count, like the real
         * statement. */
        public function delete(string $table, array $where, array $formats = []): int|false
        {
            $kept = [];
            $removed = 0;
            foreach (($this->aiya_test_rows[$table] ?? []) as $row) {
                foreach ($where as $key => $value) {
                    if ((string) ($row[$key] ?? '') !== (string) $value) {
                        $kept[] = $row;
                        continue 2;
                    }
                }
                $removed++;
            }
            if ($removed > 0) {
                $this->aiya_test_rows[$table] = $kept;
            }
            $this->last_error = '';

            return $removed;
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

            // The chat thread page: same honest simulation, session-scoped.
            if ($table !== null && str_contains($table, 'aiya_chat_messages') && str_contains($sql, 'ORDER BY id DESC')) {
                preg_match("/session_id = '([^']+)'/", $sql, $session);
                preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $window);
                $rows = array_values(array_filter($this->aiya_test_rows[$table] ?? [], static fn (array $row): bool => $session === [] || ($row['session_id'] ?? '') === $session[1]));
                usort($rows, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
                $rows = array_slice($rows, (int) ($window[2] ?? 0), (int) ($window[1] ?? count($rows)));

                return array_map(
                    static fn (array $row): array|object => $output === ARRAY_A ? $row : (object) $row,
                    $rows
                );
            }

            $matched = [];
            foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                if (str_contains($sql, "direction = 'in'") && ($row['direction'] ?? '') !== 'in') {
                    continue;
                }
                if (preg_match("/display_name = '([^']*)'/", $sql, $name) === 1
                    && ($row['display_name'] ?? '') !== $name[1]
                ) {
                    continue;
                }
                if (preg_match('/user_id = (\d+)/', $sql, $user) === 1
                    && (int) ($row['user_id'] ?? 0) !== (int) $user[1]
                ) {
                    continue;
                }
                // The support relay's binding read: the visitor row the
                // owner's reply points at, by the stored bot-copy pair.
                if (preg_match('/tg_chat_id = (-?\d+)/', $sql, $tgc) === 1
                    && (int) ($row['tg_chat_id'] ?? -1) !== (int) $tgc[1]
                ) {
                    continue;
                }
                if (preg_match('/tg_message_id = (\d+)/', $sql, $tgm) === 1
                    && (int) ($row['tg_message_id'] ?? -1) !== (int) $tgm[1]
                ) {
                    continue;
                }
                // The feed's channel filter: rows of the prepared source.
                if (preg_match('/source_chat_id = (-?\d+)/', $sql, $src) === 1
                    && (int) ($row['source_chat_id'] ?? 0) !== (int) $src[1]
                ) {
                    continue;
                }
                // The feed's substring search: the escaped LIKE un-escapes
                // before the contains check, the way MySQL reads it.
                if (preg_match("/text LIKE '([^']*)'/", $sql, $like) === 1) {
                    $needle = trim($like[1], '%');
                    $needle = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], $needle);
                    if (!str_contains((string) ($row['text'] ?? ''), $needle)) {
                        continue;
                    }
                }
                // The feed's NSFW channel exclusion: the prepared NOT IN
                // lists drop the marked sources; a NULL username row
                // survives the username list the way MySQL reads
                // `chat_username IS NULL OR chat_username NOT IN`.
                if (preg_match('/source_chat_id NOT IN \(([^)]*)\)/', $sql, $notIds) === 1
                    && in_array((int) ($row['source_chat_id'] ?? 0), array_map('intval', explode(',', $notIds[1])), true)
                ) {
                    continue;
                }
                if (preg_match("/chat_username NOT IN \(([^)]*)\)/", $sql, $notUsers) === 1) {
                    $username = $row['chat_username'] ?? null;
                    $listed = array_map(static fn (string $item): string => trim($item, "'"), explode(',', $notUsers[1]));
                    if ($username !== null && in_array((string) $username, $listed, true)) {
                        continue;
                    }
                }
                // A standalone primary-key probe (the like service's thread
                // existence read) narrows to the matching row, the way the
                // real WHERE id = N does.
                if (preg_match('/\bid = (\d+)\b/', $sql, $idSel) === 1
                    && array_key_exists('id', $row)
                    && (int) $row['id'] !== (int) $idSel[1]
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

                $matched[] = $row;
            }

            // The ledger's FIFO sweep orders by `expires_at IS NULL ASC,
            // expires_at ASC, id ASC`; simulating it is what keeps the
            // expiry test honest — its seeds deliberately contradict the
            // natural row order, so the spend dies here if the query (or
            // this simulation) ever loses the ordering.
            if (str_contains($sql, 'ORDER BY expires_at IS NULL ASC')) {
                usort($matched, static fn (array $a, array $b): int =>
                    [($a['expires_at'] ?? null) === null, (string) ($a['expires_at'] ?? ''), (int) ($a['id'] ?? 0)]
                        <=> [($b['expires_at'] ?? null) === null, (string) ($b['expires_at'] ?? ''), (int) ($b['id'] ?? 0)]);
            }

            // The feed page read: id DESC with LIMIT/OFFSET, simulated for
            // real — the WHERE filters (channel, text LIKE) already ran in
            // the generic loop above, and a natural-order shortcut would
            // fake the pagination semantics the controller's windows
            // depend on.
            if ($table !== null && str_contains($table, 'aiya_channel_feed') && str_contains($sql, 'ORDER BY id DESC')) {
                usort($matched, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
                preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $window);
                $matched = array_slice($matched, (int) ($window[2] ?? 0), (int) ($window[1] ?? count($matched)));
            }

            $rows = [];
            foreach ($matched as $row) {
                // A `col AS alias` select projects one column (the follow
                // sweep's target ids); honour it the way MySQL would.
                $projected = $row;
                if (preg_match('/SELECT\s+(\w+)\s+AS\s+(\w+)/i', $sql, $alias) === 1) {
                    $projected = [$alias[2] => $row[$alias[1]] ?? null];
                }

                $rows[] = $output === ARRAY_A
                    // Core's ARRAY_A shape: the stored row as an assoc array
                    // (bucket sweeps, follower pages and history reads each
                    // pick their own columns off it).
                    ? $projected
                    // Core's default output shape: object rows carrying
                    // every stored field.
                    : (object) $row;
            }

            return $rows;
        }

        public function get_row(string $sql, mixed $output = null): ?object
        {
            $this->aiya_test_reads++;
            $table = $this->aiya_test_table($sql);
            if ($table === null || !isset($this->aiya_test_rows[$table])) {
                return null;
            }

            // The last-reply lookup behind syncReplyStats: the newest seeded
            // reply of the prepared thread.
            if (str_contains($sql, 'ORDER BY id DESC') && str_contains($sql, 'thread_id')) {
                preg_match('/thread_id = (\d+)/', $sql, $thread);
                $last = null;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['thread_id'] ?? 0) === (int) $thread[1]) {
                        $last = $row;
                    }
                }

                return $last === null ? null : (object) $last;
            }

            // First seeded row of the table wins — the readers this serves
            // (thread lookups) run against a one-row fixture.
            $row = $this->aiya_test_rows[$table][0] ?? null;

            return $row === null ? null : (object) $row;
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

        /** Escapes LIKE wildcards the way the real statement does. */
        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_var(string $sql): mixed
        {
            $this->aiya_test_reads++;

            // The channel feed's binding read: the id of the prepared
            // (source_chat_id, message_id) pair, null when never stored.
            if (str_contains($sql, 'SELECT id FROM') && str_contains($sql, 'aiya_channel_feed')) {
                $table = $this->aiya_test_table($sql);
                preg_match('/source_chat_id = (-?\d+)/', $sql, $chat);
                preg_match('/message_id = (\d+)/', $sql, $msg);
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['source_chat_id'] ?? 0) === (int) $chat[1]
                        && (int) ($row['message_id'] ?? 0) === (int) $msg[1]
                    ) {
                        return (int) $row['id'];
                    }
                }

                return null;
            }

            // The chat session's page count.
            if (str_contains($sql, 'COUNT(id)') && str_contains($sql, 'aiya_chat_messages')) {
                preg_match("/session_id = '([^']+)'/", $sql, $session);
                $count = 0;
                foreach ($this->aiya_test_rows[$this->aiya_test_table($sql)] ?? [] as $row) {
                    if ($session === [] || ($row['session_id'] ?? '') === $session[1]) {
                        $count++;
                    }
                }

                return $count;
            }

            // The feed's page count: the same filters the page read runs.
            if (str_contains($sql, 'COUNT(id)') && str_contains($sql, 'aiya_channel_feed')) {
                preg_match('/source_chat_id = (-?\d+)/', $sql, $src);
                preg_match("/text LIKE '([^']*)'/", $sql, $like);
                $needle = $like === [] ? null : trim($like[1], '%');
                $needle = $needle === null ? null : str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], $needle);
                preg_match('/source_chat_id NOT IN \(([^)]*)\)/', $sql, $notIds);
                preg_match("/chat_username NOT IN \(([^)]*)\)/", $sql, $notUsers);
                $listed = $notUsers === [] ? [] : array_map(static fn (string $item): string => trim($item, "'"), explode(',', $notUsers[1]));
                $table = $this->aiya_test_table($sql);
                $count = 0;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ($src !== [] && (int) ($row['source_chat_id'] ?? 0) !== (int) $src[1]) {
                        continue;
                    }
                    if ($needle !== null && !str_contains((string) ($row['text'] ?? ''), $needle)) {
                        continue;
                    }
                    if ($notIds !== [] && in_array((int) ($row['source_chat_id'] ?? 0), array_map('intval', explode(',', $notIds[1])), true)) {
                        continue;
                    }
                    $username = $row['chat_username'] ?? null;
                    if ($username !== null && in_array((string) $username, $listed, true)) {
                        continue;
                    }
                    $count++;
                }

                return $count;
            }

            // Advisory locks answer granted — the double has no concurrency
            // to serialize (the schema runner and the entitlement queue both
            // take one).
            if (str_contains($sql, 'GET_LOCK(') || str_contains($sql, 'RELEASE_LOCK(')) {
                return 1;
            }

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

            // The discussion replies count behind syncReplyStats: seeded
            // rows of the replies table filtered by the prepared thread id.
            if (str_contains($sql, 'COUNT(id)') && str_contains($sql, 'thread_id')) {
                $table = $this->aiya_test_table($sql);
                preg_match('/thread_id = (\d+)/', $sql, $thread);
                $count = 0;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['thread_id'] ?? 0) === (int) $thread[1]) {
                        $count++;
                    }
                }

                return $count;
            }

            // The follower sweep's page count: rows of the follows table
            // filtered by the prepared followed id.
            if (str_contains($sql, 'COUNT(id)') && str_contains($sql, 'followed_id')) {
                $table = $this->aiya_test_table($sql);
                preg_match('/followed_id = (\d+)/', $sql, $followed);
                $count = 0;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['followed_id'] ?? 0) === (int) $followed[1]) {
                        $count++;
                    }
                }

                return $count;
            }

            // The thread creation read behind syncReplyStats' deletion
            // fallback (activity falls back to creation when the last reply
            // goes away).
            if (str_contains($sql, 'SELECT created_at FROM') && str_contains($this->aiya_test_table($sql) ?? '', 'aiya_discussions')) {
                $table = $this->aiya_test_table($sql);
                preg_match('/WHERE id = (\d+)/', $sql, $id);
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) $id[1]) {
                        return $row['created_at'] ?? null;
                    }
                }

                return null;
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

            // The mirror wipe's bare `DELETE FROM <table>` (no WHERE —
            // every row goes). Answers the affected-row count.
            if (preg_match('/^DELETE FROM \S+$/i', trim($sql)) === 1) {
                $table = $this->aiya_test_table($sql);
                $removed = isset($this->aiya_test_rows[$table]) ? count($this->aiya_test_rows[$table]) : 0;
                $this->aiya_test_rows[$table] = [];
                $this->rows_affected = $removed;

                return $removed;
            }

            // The ledger retention purge: the three closed-history branches
            // (expired in-buckets, out rows, fully consumed buckets) leave
            // once the retention window has passed their clock. Live
            // buckets and open-ended (NULL expiry) buckets match no
            // branch, exactly like the real statement.
            if (preg_match("/DELETE FROM (\S+)\s+WHERE \(direction = 'in' AND expires_at IS NOT NULL AND expires_at <= '([^']*)'\)\s+OR \(direction = 'out' AND created_at < '([^']*)'\)\s+OR \(direction = 'in' AND remaining <= 0 AND created_at < '([^']*)'/", $sql, $prune) === 1) {
                $table = $prune[1];
                $kept = [];
                $removed = 0;
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    $expired = ($row['direction'] ?? '') === 'in'
                        && ($row['expires_at'] ?? null) !== null
                        && (string) $row['expires_at'] <= $prune[2];
                    $spentOut = ($row['direction'] ?? '') === 'out'
                        && (string) ($row['created_at'] ?? '') < $prune[3];
                    $emptied = ($row['direction'] ?? '') === 'in'
                        && (int) ($row['remaining'] ?? 0) <= 0
                        && (string) ($row['created_at'] ?? '') < $prune[4];
                    if ($expired || $spentOut || $emptied) {
                        $removed++;
                        continue;
                    }
                    $kept[] = $row;
                }
                $this->aiya_test_rows[$table] = $kept;
                $this->rows_affected = $removed;

                return $removed;
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

            // The discussion like counter: the atomic +/- pair the like
            // service issues inside its transaction (GREATEST floors at
            // zero the way MySQL would).
            if (preg_match('/^UPDATE (\S+) SET like_count = like_count \+ 1 WHERE id = (\d+)$/', $sql, $bump) === 1) {
                foreach ($this->aiya_test_rows[$bump[1]] ?? [] as $index => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) $bump[2]) {
                        continue;
                    }
                    $this->aiya_test_rows[$bump[1]][$index]['like_count'] = (int) ($row['like_count'] ?? 0) + 1;
                    $this->rows_affected = 1;

                    return 1;
                }

                return 0;
            }
            if (preg_match('/^UPDATE (\S+) SET like_count = GREATEST\(like_count - 1, 0\) WHERE id = (\d+)$/', $sql, $drop) === 1) {
                foreach ($this->aiya_test_rows[$drop[1]] ?? [] as $index => $row) {
                    if ((int) ($row['id'] ?? 0) !== (int) $drop[2]) {
                        continue;
                    }
                    $this->aiya_test_rows[$drop[1]][$index]['like_count'] = max(0, (int) ($row['like_count'] ?? 0) - 1);
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

            // The favorites existence read (presenter viewer state): match
            // the prepared user and post ids against the seeded rows.
            if ($table !== null && str_contains($table, 'user_favorites')) {
                preg_match('/user_id = (\d+)/', $sql, $user);
                preg_match('/post_id = (\d+)/', $sql, $post);
                if ($user === [] || $post === []) {
                    return null;
                }
                foreach ($this->aiya_test_rows[$table] ?? [] as $row) {
                    if ((int) ($row['user_id'] ?? 0) === (int) $user[1]
                        && (int) ($row['post_id'] ?? 0) === (int) $post[1]
                    ) {
                        return $row;
                    }
                }

                return null;
            }

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

if (!function_exists('add_post_meta')) {
    function add_post_meta(int $objectId, string $key, mixed $value, bool $unique = false): bool
    {
        // Core inserts a second row for a non-unique add; the counter's
        // baseline row rides $unique=true, so the store keeps one value.
        if ($unique && isset($GLOBALS['__aiya_test_post_meta'][$objectId][$key])) {
            return false;
        }
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

if (!function_exists('wp_unique_post_slug')) {
    function wp_unique_post_slug(string $slug, int $postId, string $postStatus, string $postType, int $postParent): string
    {
        // Collision-free double: the suite holds no competing slug space,
        // and the real function never sanitizes its input — it only queries
        // for conflicts (case-insensitive collation) — so a pass-through is
        // faithful for the case semantics the slug tests pin.
        return $slug;
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

if (!function_exists('get_post_field')) {
    function get_post_field(string $field, int $postId): string
    {
        $post = $GLOBALS['__aiya_test_posts'][$postId] ?? null;

        return $post instanceof WP_Post ? (string) ($post->{$field} ?? '') : '';
    }
}

if (!function_exists('cache_users')) {
    function cache_users(array $userIds): void
    {
        // The mass user fill is a performance primitive with no observable
        // state in these tests; the per-user reads below work regardless.
    }
}

if (!function_exists('_prime_post_caches')) {
    function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
    {
        // Same stance as cache_users: a warm-up with no observable state.
    }
}

if (!function_exists('_prime_comment_caches')) {
    function _prime_comment_caches(array $commentIds, bool $updateMetaCache = true): void
    {
    }
}

if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck(array $list, string|int $field, string|int|null $indexKey = null): array
    {
        $out = [];
        foreach ($list as $key => $item) {
            $value = is_object($item) ? ($item->{$field} ?? null) : ($item[$field] ?? null);
            if ($indexKey !== null) {
                $index = is_object($item) ? ($item->{$indexKey} ?? null) : ($item[$indexKey] ?? null);
                $out[$index] = $value;

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}

if (!class_exists('WP_Query')) {
    /**
     * Minimal stand-in for the read queries the API layer issues: honours
     * `post__in` order, publish-only and the post_type whitelist. The
     * 0.96.0 list tests need the real query's window semantics too, so
     * this also implements `post__not_in`, `offset`/`paged`+limit
     * slicing, `fields => ids`, `found_posts` and the `get()` accessor —
     * searches, tax and meta legs stay ignored (no SQL layer here).
     */
    class WP_Query
    {
        /** @var list<WP_Post|int> */
        public array $posts = [];

        public int $found_posts = 0;

        /** @var array<string, mixed> */
        private array $args = [];

        public function __construct(array $args = [])
        {
            $this->args = $args;
            $types = (array) ($args['post_type'] ?? 'any');
            $wanted = [];
            foreach ($types as $type) {
                $wanted[] = (string) $type;
            }
            $notIn = array_map('intval', (array) ($args['post__not_in'] ?? []));

            $matched = [];
            if (($args['post__in'] ?? []) !== []) {
                foreach ((array) ($args['post__in'] ?? []) as $id) {
                    $post = $GLOBALS['__aiya_test_posts'][(int) $id] ?? null;
                    if (!$post instanceof WP_Post) {
                        continue;
                    }
                    if ($args['post_status'] ?? null) {
                        if (!in_array((string) $post->post_status, (array) $args['post_status'], true)) {
                            continue;
                        }
                    }
                    if ($wanted !== [] && !in_array((string) $post->post_type, $wanted, true)) {
                        continue;
                    }
                    $matched[] = $post;
                }
            } else {
                foreach (($GLOBALS['__aiya_test_posts'] ?? []) as $post) {
                    if (!$post instanceof WP_Post) {
                        continue;
                    }
                    if ($args['post_status'] ?? null) {
                        if (!in_array((string) $post->post_status, (array) $args['post_status'], true)) {
                            continue;
                        }
                    }
                    if ($wanted !== [] && !in_array((string) $post->post_type, $wanted, true)) {
                        continue;
                    }
                    $matched[] = $post;
                }
                // Default date listing: newest first, id tiebreak (the
                // direction follows `order`).
                $direction = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 1 : -1;
                usort($matched, static fn (WP_Post $a, WP_Post $b): int => $direction * (
                    [$a->post_date, (int) $a->ID] <=> [$b->post_date, (int) $b->ID]
                ));
            }

            $matched = array_values(array_filter(
                $matched,
                static fn (WP_Post $post): bool => !in_array((int) $post->ID, $notIn, true)
            ));
            $this->found_posts = count($matched);

            // posts_per_page 0 / -1 mean "no limit" in real WP — keep that.
            $perPage = isset($args['posts_per_page']) && (int) $args['posts_per_page'] > 0
                ? (int) $args['posts_per_page']
                : null;
            $offset = 0;
            if (isset($args['offset'])) {
                $offset = max(0, (int) $args['offset']);
            } elseif ($perPage !== null && isset($args['paged'])) {
                $offset = (max(1, (int) $args['paged']) - 1) * $perPage;
            }
            if ($perPage !== null) {
                $matched = array_slice($matched, $offset, $perPage);
            } elseif ($offset > 0) {
                $matched = array_slice($matched, $offset);
            }

            $this->posts = (($args['fields'] ?? '') === 'ids')
                ? array_map(static fn (WP_Post $post): int => (int) $post->ID, $matched)
                : $matched;
        }

        public function get(string $key, mixed $default = ''): mixed
        {
            return $this->args[$key] ?? $default;
        }
    }
}

if (!function_exists('is_sticky')) {
    function is_sticky(int $postId = 0): bool
    {
        return in_array($postId, $GLOBALS['__aiya_test_sticky'], true);
    }
}

if (!function_exists('unstick_post')) {
    /** Mirrors core's sticky-options removal against the sticky fixture
        list; core answers void, so no return value exists to lean on. */
    function unstick_post(int $postId): void
    {
        foreach ($GLOBALS['__aiya_test_sticky'] ?? [] as $index => $stickyId) {
            if ((int) $stickyId === $postId) {
                unset($GLOBALS['__aiya_test_sticky'][$index]);
            }
        }
        $GLOBALS['__aiya_test_sticky'] = array_values($GLOBALS['__aiya_test_sticky'] ?? []);
    }
}

if (!function_exists('get_post_type_object')) {
    /** The suite has no post-type registry: any name answers an object
        carrying the edit_posts capability the gates read. Unknown names
        are excluded upstream by the PublicTypes gate. */
    function get_post_type_object(string $name): ?object
    {
        if ($name === '') {
            return null;
        }

        $object = new stdClass();
        $object->name = $name;
        $object->cap = new stdClass();
        $object->cap->edit_posts = 'edit_posts';

        return $object;
    }
}

if (!function_exists('wp_update_post')) {
    /** Applies post_type-class field writes onto the posts fixture and
        records the payload; an unknown ID fails the way core does
        (0, or WP_Error when asked). */
    function wp_update_post(array|object $postarr = [], bool $wp_error = false, bool $fireAfterHooks = true): int|WP_Error
    {
        $postarr = (array) $postarr;
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id === 0 || !isset($GLOBALS['__aiya_test_posts'][$id])) {
            return $wp_error ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
        }

        $GLOBALS['__aiya_test_post_updates'] ??= [];
        $GLOBALS['__aiya_test_post_updates'][] = $postarr;

        // Core merges the payload over the stored row (slug unification and
        // hooks aside) — every provided field lands, none is dropped.
        $post = $GLOBALS['__aiya_test_posts'][$id];
        foreach ($postarr as $field => $value) {
            if ($field !== 'ID') {
                $post->{$field} = $value;
            }
        }

        return $id;
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

if (!function_exists('update_term_meta')) {
    function update_term_meta(int $termId, string $key, mixed $value): bool
    {
        // Mirrors core: the meta API unslashes incoming (slashed) values
        // before persisting them.
        $GLOBALS['__aiya_test_term_meta'][$termId][$key] = wp_unslash($value);
        return true;
    }
}

if (!function_exists('delete_term_meta')) {
    function delete_term_meta(int $termId, string $key): bool
    {
        unset($GLOBALS['__aiya_test_term_meta'][$termId][$key]);
        return true;
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, int|bool $timestampWithOffset = false): string
    {
        return date($format, $timestampWithOffset === false ? time() : $timestampWithOffset);
    }
}

if (!function_exists('has_action')) {
    /** Reads the filter registry the add_action shim writes. */
    function has_action(string $hook, callable|string|false $callback = false): bool|int
    {
        $buckets = $GLOBALS['__aiya_test_filters'][$hook] ?? [];
        if ($callback === false) {
            return $buckets !== [] ? true : false;
        }
        foreach ($buckets as $entries) {
            foreach ($entries as $entry) {
                if ($entry['callback'] === $callback) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('wp_mail')) {
    /** Captures every send for assertions; the branded shell arrives here
        exactly as a real MTA would receive it. */
    function wp_mail(string|array $to, string $subject, string $message, array|string $headers = [], array|string $attachments = [], array|string $embeds = []): bool
    {
        // Mirror production's flow: the envelope goes through the
        // 'wp_mail' args filter (then the pre_wp_mail short-circuit)
        // before transport. A shim that skipped the filter stage recorded
        // raw text and hid the whole args-rewriter class of bugs (the CID
        // embeds regression slipped through exactly this hole).
        $atts = apply_filters('wp_mail', compact('to', 'subject', 'message', 'headers', 'attachments', 'embeds'));

        $pre = apply_filters('pre_wp_mail', null, $atts);
        if ($pre !== null) {
            return (bool) $pre;
        }

        $GLOBALS['__aiya_test_mails'][] = [
            'to' => $atts['to'] ?? $to,
            'subject' => $atts['subject'] ?? $subject,
            'message' => $atts['message'] ?? $message,
            'headers' => $atts['headers'] ?? $headers,
            'embeds' => $atts['embeds'] ?? $embeds,
        ];

        return true;
    }
}

$GLOBALS['__aiya_test_mails'] = [];

if (!function_exists('wp_rand')) {
    /** Mirrors pluggable wp_rand(): null defaults, int cast, either argument
        order, then the CSPRNG with the final absint (core never draws
        negatives). The $rnd_value reuse cache is a legacy performance
        detail no test pins. */
    function wp_rand($min = null, $max = null)
    {
        if ($min === null) {
            $min = 0;
        }
        if ($max === null) {
            $max = 4294967295;
        }
        $min = (int) $min;
        $max = (int) $max;
        if ($min > $max) {
            [$max, $min] = [$min, $max];
        }

        return absint(random_int($min, $max));
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
        // The real query accepts one taxonomy or a list; the stub merges
        // the vocabularies in listed order.
        $taxonomies = (array) ($args['taxonomy'] ?? '');
        $terms = [];
        foreach ($taxonomies as $taxonomy) {
            $terms = array_merge($terms, $GLOBALS['__aiya_test_terms'][(string) $taxonomy] ?? []);
        }
        return $terms === [] ? [] : $terms;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): ?object
    {
        if (!array_key_exists($taxonomy, $GLOBALS['__aiya_test_terms'])) {
            return null;
        }
        // Cached per name, so a test that mutates the taxonomy object (the
        // tag-cloud label rewrite) sees the mutation on the next read.
        $GLOBALS['__aiya_test_taxonomies'] ??= [];
        $GLOBALS['__aiya_test_taxonomies'][$taxonomy] ??= (object) ['labels' => (object) ['singular_name' => ucfirst($taxonomy)]];

        return $GLOBALS['__aiya_test_taxonomies'][$taxonomy];
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        return (string) preg_replace(['/[^a-z0-9_-]+/', '/-+/'], ['-', '-'], $title);
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

if (!function_exists('remove_theme_support')) {
    function remove_theme_support(string $feature): bool
    {
        unset($GLOBALS['__aiya_test_theme_features'][$feature]);
        return true;
    }
}

if (!function_exists('get_post_types')) {
    // Registry-free double: the strips only iterate the list to unhook
    // per-type support, so an empty registry is a faithful no-op.
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        return [];
    }
}

if (!function_exists('remove_post_type_support')) {
    function remove_post_type_support(string $post_type, string $feature): void
    {
    }
}

if (!function_exists('wp_deregister_script')) {
    function wp_deregister_script(string $handle): void
    {
    }
}

if (!function_exists('wp_dequeue_style')) {
    function wp_dequeue_style(string $handle): void
    {
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
    function add_filter(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        $GLOBALS['__aiya_test_filters'][$hook][$priority][] = ['callback' => $callback, 'args' => $acceptedArgs];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): true
    {
        return add_filter($hook, $callback, $priority, $acceptedArgs);
    }
}

// Core's canned response helpers: src wires several filters by their
// string names, which only resolve as callables when the functions exist.
if (!function_exists('__return_false')) {
    function __return_false(): bool
    {
        return false;
    }
}

if (!function_exists('__return_true')) {
    /**
     * The current admin screen, staged per test via
     * $GLOBALS['__aiya_test_screen'] (null = no screen context).
     */
    function get_current_screen(): ?object
    {
        $screen = $GLOBALS['__aiya_test_screen'] ?? null;

        return is_object($screen) ? $screen : null;
    }

    function __return_true(): bool
    {
        return true;
    }
}

if (!function_exists('__return_empty_array')) {
    function __return_empty_array(): array
    {
        return [];
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable|string $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['__aiya_test_filters'][$hook][$priority] ?? [] as $index => $entry) {
            if ($entry['callback'] === $callback) {
                unset($GLOBALS['__aiya_test_filters'][$hook][$priority][$index]);

                return true;
            }
        }

        return false;
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $hook, callable|string $callback, int $priority = 10): bool
    {
        return remove_filter($hook, $callback, $priority);
    }
}

if (!function_exists('get_term_children')) {
    function get_term_children(int $termId, string $taxonomy): array
    {
        // Core answers every descendant (flat, recursive) from the
        // taxonomy hierarchy; the fixture is that flat list per term id.
        return array_map('intval', $GLOBALS['__aiya_test_term_children'][$termId] ?? []);
    }
}

if (!function_exists('get_term')) {
    function get_term(mixed $term = null, string $taxonomy = ''): WP_Term|null
    {
        if ($term instanceof WP_Term) {
            return $term;
        }
        // Like core: a taxonomy argument narrows the lookup (an id shared
        // across taxonomies answers the one asked for, not an ambiguity).
        foreach (($GLOBALS['__aiya_test_terms'] ?? []) as $ownTaxonomy => $terms) {
            if ($taxonomy !== '' && (string) $ownTaxonomy !== $taxonomy) {
                continue;
            }
            foreach ((array) $terms as $candidate) {
                if ($candidate instanceof WP_Term && (int) $candidate->term_id === (int) $term) {
                    return $candidate;
                }
            }
        }

        return null;
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


// --- Scheduled events (single-event retries, the Telegram push) -----------

if (!function_exists('wp_schedule_single_event')) {
    /**
     * Recorder double: events land in __aiya_test_cron as
     * {hook, timestamp, args} (timestamp = the offset the caller asked
     * for, relative to the test's frozen time), so assertions read the
     * schedule instead of waiting on a runner that does not exist here.
     */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['__aiya_test_cron'][] = ['hook' => $hook, 'timestamp' => $timestamp, 'args' => $args];

        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    /** Answers the offset of the first matching entry, false when none. */
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach ($GLOBALS['__aiya_test_cron'] ?? [] as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                return (int) $event['timestamp'];
            }
        }

        return false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): void
    {
        $kept = [];
        foreach ($GLOBALS['__aiya_test_cron'] ?? [] as $event) {
            if ($event['hook'] === $hook && ($args === [] || $event['args'] === $args)) {
                continue;
            }
            $kept[] = $event;
        }
        $GLOBALS['__aiya_test_cron'] = $kept;
    }
}

if (!function_exists('strip_shortcodes')) {
    /**
     * Pass-through: the suite's fixture content carries no registered
     * shortcodes, so the strip step has nothing to remove in tests.
     */
    function strip_shortcodes(string $content): string
    {
        return $content;
    }
}

if (!function_exists('wp_unique_filename')) {
    /**
     * Collision-checked naming, simulated: a same-name fixture file gets
     * the numeric suffix the real directory walker would produce.
     */
    function wp_unique_filename(string $dir, string $filename): string
    {
        if (!is_file($dir . '/' . $filename)) {
            return $filename;
        }
        $dot = strrpos($filename, '.');
        $base = $dot === false ? $filename : substr($filename, 0, $dot);
        $ext = $dot === false ? '' : substr($filename, $dot);
        $n = 1;
        while (is_file($dir . '/' . $base . '-' . $n . $ext)) {
            $n++;
        }

        return $base . '-' . $n . $ext;
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the shim IS the stand-in for core's unlink wrapper
        @unlink($file);
    }
}
