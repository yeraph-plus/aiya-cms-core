<?php

/**
 * PHPStan bootstrap: provides the plugin constants that are normally defined
 * in aiya-core.php at runtime. Values are placeholders; only existence matters.
 */

if (!defined('AIYA_CORE_VERSION')) {
    define('AIYA_CORE_VERSION', '0.18.0');
}
if (!defined('AIYA_CORE_FILE')) {
    define('AIYA_CORE_FILE', '/var/www/html/wp-content/plugins/aiya-core/aiya-core.php');
}
if (!defined('AIYA_CORE_PATH')) {
    define('AIYA_CORE_PATH', '/var/www/html/wp-content/plugins/aiya-core/');
}
if (!defined('AIYA_CORE_URL')) {
    define('AIYA_CORE_URL', 'http://example.com/wp-content/plugins/aiya-core/');
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', 'example.com');
}
