<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHPUnit bootstrap file for FediBoost tests.
 *
 * @package FediBoost
 */

// Define test environment.
define( 'FEDIBOOST_TESTING', true );

// Get the WordPress tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Check if WordPress test library exists.
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find WordPress test library at {$_tests_dir}/includes/functions.php\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output in test bootstrap.
	echo "Please set WP_TESTS_DIR environment variable to point to WordPress test library.\n";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/fediboost.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
