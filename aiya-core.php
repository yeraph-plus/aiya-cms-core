<?php
/**
 * Plugin Name: AIYA CMS Core
 * Description: Headless-first administration and content framework for AIYA CMS.
 * Version: 0.94.0-beta.1
 * Requires at least: 6.4
 * Requires PHP: 8.5
 * Author: Yeraph Studio
 * License: GPL-3.0-or-later
 * Update URI: false
 * Text Domain: aiya-core
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AIYA_CORE_VERSION', '0.94.0-beta.1');
define('AIYA_CORE_FILE', __FILE__);
define('AIYA_CORE_PATH', plugin_dir_path(__FILE__));
define('AIYA_CORE_URL', plugin_dir_url(__FILE__));

// Composer autoload: third-party dependencies (imagine, pinyin, …). The
// bundled packages under packages/ are NOT composer-installed — vendor/aiya
// does not exist — and are loaded by the plugin autoloader below.
if (is_readable(AIYA_CORE_PATH . 'vendor/autoload.php')) {
    require_once AIYA_CORE_PATH . 'vendor/autoload.php';
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'Aiya\\Core\\';
    if (str_starts_with($className, $prefix)) {
        $relative = substr($className, strlen($prefix));
        $path = AIYA_CORE_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require_once $path;
        }

        return;
    }

    if (!str_starts_with($className, 'Aiya\\Infra\\')) {
        return;
    }

    $path = Aiya\Core\Runtime\Packages::locate($className);
    if ($path !== null) {
        require_once $path;
    }
});

function aiya_core(): Aiya\Core\Plugin
{
    return Aiya\Core\Plugin::instance();
}

/**
 * Reads a single top-level settings field for a registered page, falling back
 * to the field default and then to the caller-supplied default. Repeater and
 * nested children are not resolved here.
 *
 * Resolved values are memoized per request: the registry page lookup plus the
 * field-default linear scan repeat on every call otherwise (hot paths call
 * the same key a dozen times per request). The memo holds only values that
 * resolve independent of the caller's fallback — a missing page or field
 * answers the caller-supplied fallback and stays unmemoized, because that
 * answer differs per call site. Any `aiya_core_*` option write drops the
 * whole memo so a save-then-read sequence inside one request stays truthful.
 */
function aiya_core_opt(string $page, string $id, mixed $fallback = null): mixed
{
    static $memo = [];
    static $hooks = false;
    if (!$hooks) {
        $hooks = true;
        $clear = static function (string $optionName) use (&$memo): void {
            if (str_starts_with($optionName, 'aiya_core_')) {
                $memo = [];
            }
        };
        add_action('added_option', $clear);
        add_action('updated_option', $clear);
        add_action('deleted_option', $clear);
    }

    $key = $page . ':' . $id;
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }

    $page_schema = aiya_core()->settings()->page($page);
    if ($page_schema === null) {
        return $fallback;
    }

    $field_default = null;
    $field_found = false;
    foreach ($page_schema->fields() as $field) {
        if ($field->id() === $id) {
            $field_default = $field->defaultValue();
            $field_found = true;
            break;
        }
    }

    $values = (new Aiya\Core\Settings\Storage\OptionStore($page_schema->optionName(), $page_schema->network()))->all();
    if (array_key_exists($id, $values)) {
        $memo[$key] = $values[$id];

        return $memo[$key];
    }

    if ($field_found) {
        $memo[$key] = $field_default;

        return $memo[$key];
    }

    return $fallback;
}

register_activation_hook(__FILE__, [aiya_core(), 'activate']);
register_deactivation_hook(__FILE__, [aiya_core(), 'deactivate']);

Aiya\Core\Command\ContractsSnapshot::register();

aiya_core()->boot();
