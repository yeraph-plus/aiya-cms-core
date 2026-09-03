<?php
/**
 * Plugin Name: AIYA Core
 * Description: Headless-first administration and content framework for AIYA CMS.
 * Version: 0.1.0
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

define('AIYA_CORE_VERSION', '0.1.0');
define('AIYA_CORE_FILE', __FILE__);
define('AIYA_CORE_PATH', plugin_dir_path(__FILE__));
define('AIYA_CORE_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Aiya\\Core\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = AIYA_CORE_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

function aiya_core(): Aiya\Core\Plugin
{
    return Aiya\Core\Plugin::instance();
}

aiya_core()->boot();

