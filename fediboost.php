<?php
/**
 * Plugin Name: FediBoost
 * Plugin URI: https://github.com/kraft/fediboost
 * Description: Automatically boost WordPress posts on connected Mastodon accounts when published via ActivityPub.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: activitypub
 * Author: Brandon Kraft
 * Author URI: https://kraft.blog/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fediboost
 *
 * @package FediBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check PHP version requirement.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'fediboost_php_version_notice' );
	return;
}

/**
 * Display admin notice for PHP version requirement.
 */
function fediboost_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: Required PHP version */
				esc_html__( 'FediBoost requires PHP %s or higher. Please upgrade your PHP version.', 'fediboost' ),
				'7.4'
			);
			?>
		</p>
	</div>
	<?php
}

// Define plugin constants.
define( 'FEDIBOOST_VERSION', '1.0.0' );
define( 'FEDIBOOST_PLUGIN_FILE', __FILE__ );
define( 'FEDIBOOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FEDIBOOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include autoloader.
require_once FEDIBOOST_PLUGIN_DIR . 'includes/autoload.php';

/*
 * Hook Registration Summary
 *
 * This plugin registers hooks in the following locations:
 *
 * Global (this file):
 *   register_activation_hook  → fediboost_activate()
 *   register_deactivation_hook → fediboost_deactivate()
 *   admin_init                → fediboost_check_activitypub_dependency()
 *   admin_notices             → fediboost_activitypub_missing_notice()
 *   admin_notices             → fediboost_openssl_missing_notice() [conditional]
 *   plugins_loaded            → fediboost_init()
 *
 * FediBoost\Boost::init_hooks():
 *   wp_after_insert_post (priority 50) → on_post_publish()
 *   fediboost_boost_post (cron)        → execute_boost()
 *
 * FediBoost\Admin::init_hooks():
 *   admin_menu             → register_admin_menu()
 *   admin_init             → register_settings()
 *   admin_enqueue_scripts  → enqueue_admin_styles()
 *   admin_notices          → display_admin_notices()
 *   admin_notices          → display_reconnection_warning()
 *   admin_post_fediboost_connect        → handle_connect_request()
 *   admin_post_fediboost_oauth_callback → handle_oauth_callback()
 *   admin_init                          → handle_disconnect_action()
 */

// Register activation hook.
register_activation_hook( __FILE__, 'fediboost_activate' );

// Register deactivation hook.
register_deactivation_hook( __FILE__, 'fediboost_deactivate' );

/**
 * Plugin activation callback.
 *
 * Checks for ActivityPub dependency and initializes default options.
 */
function fediboost_activate() {
	// Set activation flag.
	update_option( 'fediboost_activated', '1', false );

	// Initialize default options if not already set.
	if ( false === get_option( 'fediboost_accounts' ) ) {
		update_option( 'fediboost_accounts', array(), false );
	}

	// Initialize instance apps storage.
	if ( false === get_option( 'fediboost_instance_apps' ) ) {
		update_option( 'fediboost_instance_apps', array(), false );
	}

	// Check ActivityPub dependency - set a flag to show notice on next admin load.
	if ( ! fediboost_is_activitypub_active() ) {
		update_option( 'fediboost_show_activitypub_notice', '1', false );
	}
}

/**
 * Plugin deactivation callback.
 *
 * Clears scheduled wp-cron events and transients but retains account data.
 */
function fediboost_deactivate() {
	// Clear all scheduled boost events.
	wp_clear_scheduled_hook( 'fediboost_boost_post' );

	// Remove the ActivityPub notice flag.
	delete_option( 'fediboost_show_activitypub_notice' );

	// Clean up OAuth state transients.
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for transient cleanup by prefix
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_fediboost_oauth_state_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_fediboost_oauth_state_' ) . '%'
		)
	);
}

/**
 * Check if ActivityPub plugin is active.
 *
 * @return bool True if ActivityPub is active, false otherwise.
 */
function fediboost_is_activitypub_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( 'activitypub/activitypub.php' );
}

/**
 * Check ActivityPub dependency on admin_init.
 */
function fediboost_check_activitypub_dependency() {
	if ( ! fediboost_is_activitypub_active() ) {
		update_option( 'fediboost_show_activitypub_notice', '1', false );
	} else {
		delete_option( 'fediboost_show_activitypub_notice' );
	}
}
add_action( 'admin_init', 'fediboost_check_activitypub_dependency' );

/**
 * Display admin notice when ActivityPub plugin is missing.
 */
function fediboost_activitypub_missing_notice() {
	if ( '1' !== get_option( 'fediboost_show_activitypub_notice' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %s: ActivityPub plugin link */
				esc_html__( 'FediBoost requires the %s plugin to function. Please install and activate it to enable automatic boosting.', 'fediboost' ),
				'<a href="https://wordpress.org/plugins/activitypub/" target="_blank">ActivityPub</a>'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'fediboost_activitypub_missing_notice' );

/**
 * Display admin notice when OpenSSL extension is not loaded.
 */
function fediboost_openssl_missing_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<div class="notice notice-warning">
		<p>
			<?php
			esc_html_e( 'FediBoost: The OpenSSL PHP extension is not loaded. Token encryption requires OpenSSL to function properly.', 'fediboost' );
			?>
		</p>
	</div>
	<?php
}

if ( ! extension_loaded( 'openssl' ) ) {
	add_action( 'admin_notices', 'fediboost_openssl_missing_notice' );
}

/**
 * Initialize the plugin.
 */
function fediboost_init() {
	// Initialize main plugin class.
	FediBoost\Plugin::get_instance();

	// Initialize boost functionality (includes cron handlers and publish hooks).
	FediBoost\Boost::get_instance();

	// Initialize admin class if in admin.
	if ( is_admin() ) {
		FediBoost\Admin::get_instance();
	}
}
add_action( 'plugins_loaded', 'fediboost_init' );
