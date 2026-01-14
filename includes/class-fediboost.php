<?php
/**
 * Main plugin class.
 *
 * @package FediBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main FediBoost class.
 *
 * Handles core plugin functionality and singleton pattern.
 */
class FediBoost {

	/**
	 * Single instance of the class.
	 *
	 * @var FediBoost|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return FediBoost
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Core plugin class - hooks are initialized by individual component classes.
	}

	/**
	 * Check if boost functionality is available.
	 *
	 * Boost is disabled when ActivityPub plugin is not active.
	 *
	 * @return bool True if boost is available, false otherwise.
	 */
	public function is_boost_available() {
		return fediboost_is_activitypub_active();
	}

	/**
	 * Get all connected accounts.
	 *
	 * @return array Array of connected accounts.
	 */
	public function get_connected_accounts() {
		$accounts = FediBoost_Accounts::get_instance();
		return $accounts->get_all_accounts();
	}
}
