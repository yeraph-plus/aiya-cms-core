<?php
/**
 * Plugin Name: AIYA Core
 * Description: Headless-first administration and content framework for AIYA CMS.
 * Version: 0.21.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Yeraph Studio
 * License: GPL-3.0-or-later
 * Text Domain: aiya-core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AIYA_CORE_VERSION', '0.21.0');
define('AIYA_CORE_FILE', __FILE__);
define('AIYA_CORE_PATH', plugin_dir_path(__FILE__));
define('AIYA_CORE_URL', plugin_dir_url(__FILE__));

// Composer autoload: packages/ primitives (Aiya\Infra\*) land here.
if (is_readable(AIYA_CORE_PATH . 'vendor/autoload.php')) {
    require_once AIYA_CORE_PATH . 'vendor/autoload.php';
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'Aiya\\Core\\';
    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relative = substr($className, strlen($prefix));
    $path = AIYA_CORE_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
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
 */
function aiya_core_opt(string $page, string $id, mixed $fallback = null): mixed
{
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
        return $values[$id];
    }

    return $field_found ? $field_default : $fallback;
}

register_activation_hook(__FILE__, [aiya_core(), 'activate']);
register_deactivation_hook(__FILE__, [aiya_core(), 'deactivate']);

aiya_core()->boot();
