<?php
// Database connection parameters
/*{ DB_HOST }*/
/*{ DB_NAME }*/
/*{ DB_USER }*/
/*{ DB_PASSWORD }*/
/*{ DB_CHARSET }*/
/*{ DB_COLLATE }*/

// Site URL parameters
/*{ WP_HOME }*/
define('WP_SITEURL', WP_HOME . '/');

// Other useful parameters
define('WP_DEBUG', false);
define('WP_CACHE', !WP_DEBUG);
define('COOKIEHASH', '/*{ COOKIEHASH }*/');
define('DISALLOW_UNFILTERED_HTML', true);
define('WP_DISABLE_TRANSIENTS', WP_DEBUG);
