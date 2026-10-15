<?php
/**
 * PHPUnit bootstrap file for FediBoost tests that need a real database.
 *
 * Loads WorDBless with its SQLite engine. Used by the "sqlite" test suite;
 * the default suite uses tests/bootstrap.php and the in-memory engine.
 *
 * @package kraftbj/fediboost
 */

// Define test environment.
define( 'FEDIBOOST_TESTING', true );

// Load Composer autoloader and WorDBless with SQLite.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * WorDBless recreates the SQLite database on every run, and WordPress reads
 * options while loading, before the tables exist. The resulting "no such
 * table" error is echoed and also sent to error_log(); discard both so it
 * does not clutter test output.
 */
$fediboost_error_log = ini_get( 'error_log' );
$fediboost_temp_log  = tempnam( sys_get_temp_dir(), 'fediboost' );
ini_set( 'error_log', $fediboost_temp_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
ob_start();
\WorDBless\Load::load( 'sqlite' );
ob_end_clean();
ini_set( 'error_log', $fediboost_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
unlink( $fediboost_temp_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
unset( $fediboost_error_log, $fediboost_temp_log );

// Load admin includes needed by some tests.
require_once ABSPATH . 'wp-admin/includes/admin.php';

// Load the plugin.
require_once dirname( __DIR__ ) . '/fediboost.php';

// Manually initialize since plugins_loaded has already fired.
fediboost_init();

// Load the base test case classes.
require_once __DIR__ . '/class-fediboost-testcase.php';
require_once __DIR__ . '/class-fediboost-sqlite-testcase.php';
