<?php
/**
 * FediBoost Uninstall
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @since 1.0.0
 *
 * @package FediBoost
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Attempt best-effort token revocation for all accounts.
if ( ! defined( 'FEDIBOOST_PLUGIN_DIR' ) ) {
	define( 'FEDIBOOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
require_once FEDIBOOST_PLUGIN_DIR . 'includes/autoload.php';

$accounts   = get_option( 'fediboost_accounts', array() );
$encryption = FediBoost\Encryption::get_instance();
$oauth      = FediBoost\OAuth::get_instance();

foreach ( $accounts as $account ) {
	if ( empty( $account['encrypted_token'] ) || empty( $account['instance_url'] ) ) {
		continue;
	}

	$token = $encryption->decrypt( $account['encrypted_token'] );
	if ( false === $token ) {
		continue;
	}

	$credentials = $oauth->get_cached_app_credentials( $account['instance_url'] );
	if ( false === $credentials ) {
		continue;
	}

	// Best-effort revocation; ignore failures.
	$oauth->revoke_token(
		$account['instance_url'],
		$token,
		$credentials['client_id'],
		$credentials['client_secret']
	);
}

// Delete plugin options.
delete_option( 'fediboost_accounts' );
delete_option( 'fediboost_instance_apps' );
delete_option( 'fediboost_activated' );
delete_option( 'fediboost_show_activitypub_notice' );

// Clean up transients with plugin prefixes.
global $wpdb;

// Delete transients with fediboost_oauth_state_ prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for transient cleanup by prefix
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_fediboost_oauth_state_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_fediboost_oauth_state_' ) . '%'
	)
);

// Delete transients with fediboost_account_ prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for transient cleanup by prefix
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_fediboost_account_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_fediboost_account_' ) . '%'
	)
);
