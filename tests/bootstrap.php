<?php
/**
 * PHPUnit bootstrap file for FediBoost tests.
 *
 * @package kraftbj/fediboost
 */

// Define test environment.
define( 'FEDIBOOST_TESTING', true );

// Load Composer autoloader and WorDBless.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

\WorDBless\Load::load();

// Load admin includes needed by some tests.
require_once ABSPATH . 'wp-admin/includes/admin.php';

// Load the plugin.
require_once dirname( __DIR__ ) . '/fediboost.php';

// Manually initialize since plugins_loaded has already fired.
fediboost_init();
