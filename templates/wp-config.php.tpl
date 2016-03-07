<?php
// Local configuration loading 
if (file_exists(__DIR__ . '/local-config.php')) {
    /** @noinspection PhpIncludeInspection */
    require __DIR__ . '/local-config.php';
}

// Custom MU-Plugin Directory
define('WPMU_PLUGIN_DIR', __DIR__ . '//*{wordpress-content-dir}*//composer-mu-plugins');

// Authentication Unique Keys and Salts.
/*{AUTH_KEY}*/
/*{SECURE_AUTH_KEY}*/
/*{LOGGED_IN_KEY}*/
/*{NONCE_KEY}*/
/*{AUTH_SALT}*/
/*{SECURE_AUTH_SALT}*/
/*{LOGGED_IN_SALT}*/
/*{NONCE_SALT}*/

// WordPress Database Table prefix
/*{table_prefix}*/

/**
 * WordPress Localized Language, defaults to English.
 *
 * Change this to localize WordPress. A corresponding MO file for the chosen
 * language must be installed to wp-content/languages. For example, install
 * de_DE.mo to wp-content/languages and set WPLANG to 'de_DE' to enable German
 * language support.
 */
define('WPLANG', '');

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '//*{wordpress-install-dir}*//');
}

/** Sets up WordPress vars and included files. */
/** @noinspection PhpIncludeInspection */
require_once ABSPATH . 'wp-settings.php';
