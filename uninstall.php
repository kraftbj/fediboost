<?php
/**
 * FediBoost Uninstall
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @package FediBoost
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
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
