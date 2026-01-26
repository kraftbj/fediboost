<?php
/**
 * Accounts helper class for FediBoost.
 *
 * Provides helpers for managing connected Mastodon accounts data.
 *
 * @since 1.0.0
 *
 * @package kraftbj/fediboost
 */

namespace FediBoost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accounts class.
 *
 * Centralized account data management utilities.
 *
 * @since 1.0.0
 */
class Accounts {

	/**
	 * Option key for storing accounts.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fediboost_accounts';

	/**
	 * Account status: connected.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const STATUS_CONNECTED = 'connected';

	/**
	 * Account status: disconnected.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const STATUS_DISCONNECTED = 'disconnected';

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @var Accounts|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Accounts
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		// Protected constructor for singleton pattern.
	}

	/**
	 * Generate a stable key for an account based on instance hostname and username.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $username     The account username.
	 * @return string A 32-character hex key.
	 */
	public static function generate_account_key( $instance_url, $username ) {
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );
		return md5( $hostname . ':' . $username );
	}

	/**
	 * Get the account data schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array describing the account data schema.
	 */
	public function get_schema() {
		return array(
			'instance_url'    => array(
				'type'        => 'string',
				'description' => 'The Mastodon instance URL (e.g., https://mastodon.social)',
				'required'    => true,
			),
			'username'        => array(
				'type'        => 'string',
				'description' => 'The account username/handle',
				'required'    => true,
			),
			'encrypted_token' => array(
				'type'        => 'string',
				'description' => 'The encrypted OAuth access token',
				'required'    => true,
			),
			'status'          => array(
				'type'        => 'string',
				'description' => 'Connection status (connected or disconnected)',
				'required'    => true,
				'enum'        => array( self::STATUS_CONNECTED, self::STATUS_DISCONNECTED ),
			),
			'connected_at'    => array(
				'type'        => 'integer',
				'description' => 'Unix timestamp when the account was connected',
				'required'    => true,
			),
		);
	}

	/**
	 * Get all connected accounts from options.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of connected account data.
	 */
	public function get_all_accounts() {
		$accounts = get_option( self::OPTION_KEY, array() );
		return is_array( $accounts ) ? $accounts : array();
	}

	/**
	 * Get a single account by instance URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return array|false Account data or false if not found.
	 */
	public function get_account_by_instance( $instance_url ) {
		$accounts = $this->get_all_accounts();
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );

		foreach ( $accounts as $account ) {
			$account_host = wp_parse_url( $account['instance_url'], PHP_URL_HOST );
			if ( $account_host === $hostname ) {
				return $account;
			}
		}

		return false;
	}

	/**
	 * Get a single account by index.
	 *
	 * @since 1.0.0
	 *
	 * @param int $index The account index.
	 * @return array|false Account data or false if not found.
	 */
	public function get_account_by_index( $index ) {
		$accounts = $this->get_all_accounts();

		if ( isset( $accounts[ $index ] ) ) {
			return $accounts[ $index ];
		}

		return false;
	}

	/**
	 * Get a single account by its stable key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The account key from generate_account_key().
	 * @return array|false Account data or false if not found.
	 */
	public function get_account_by_key( $key ) {
		$accounts = $this->get_all_accounts();

		foreach ( $accounts as $account ) {
			$account_key = self::generate_account_key( $account['instance_url'], $account['username'] );
			if ( $account_key === $key ) {
				return $account;
			}
		}

		return false;
	}

	/**
	 * Find the current numeric index for an account by its stable key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The account key from generate_account_key().
	 * @return int|false The numeric index or false if not found.
	 */
	private function find_account_index_by_key( $key ) {
		$accounts = $this->get_all_accounts();

		foreach ( $accounts as $index => $account ) {
			$account_key = self::generate_account_key( $account['instance_url'], $account['username'] );
			if ( $account_key === $key ) {
				return $index;
			}
		}

		return false;
	}

	/**
	 * Get the count of connected accounts.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of connected accounts.
	 */
	public function get_account_count() {
		return count( $this->get_all_accounts() );
	}

	/**
	 * Check if any accounts are connected.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if at least one account is connected.
	 */
	public function has_accounts() {
		return $this->get_account_count() > 0;
	}

	/**
	 * Update account status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    The stable account key from generate_account_key().
	 * @param string $status The new status (connected or disconnected).
	 * @return bool True on success, false on failure.
	 */
	public function update_account_status( $key, $status ) {
		$accounts = $this->get_all_accounts();
		$index    = $this->find_account_index_by_key( $key );

		if ( false === $index ) {
			return false;
		}

		if ( ! in_array( $status, array( self::STATUS_CONNECTED, self::STATUS_DISCONNECTED ), true ) ) {
			return false;
		}

		$accounts[ $index ]['status'] = $status;

		return update_option( self::OPTION_KEY, $accounts, false );
	}

	/**
	 * Remove an account by its stable key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The stable account key from generate_account_key().
	 * @return bool True on success, false on failure.
	 */
	public function remove_account( $key ) {
		$accounts = $this->get_all_accounts();
		$index    = $this->find_account_index_by_key( $key );

		if ( false === $index ) {
			return false;
		}

		array_splice( $accounts, $index, 1 );

		return update_option( self::OPTION_KEY, $accounts, false );
	}

	/**
	 * Add a new account.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url    The Mastodon instance URL.
	 * @param string $username        The account username.
	 * @param string $encrypted_token The encrypted OAuth token.
	 * @return bool|\WP_Error True on success, false on failure, WP_Error if limit reached.
	 */
	public function add_account( $instance_url, $username, $encrypted_token ) {
		$accounts = $this->get_all_accounts();

		/**
		 * Filters the maximum number of accounts that can be connected.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_accounts Maximum number of accounts. Default 10.
		 */
		$max_accounts = apply_filters( 'fediboost_max_accounts', 10 );

		if ( count( $accounts ) >= $max_accounts ) {
			return new \WP_Error(
				'fediboost_max_accounts_reached',
				sprintf(
					/* translators: %d: Maximum number of accounts allowed */
					__( 'Maximum number of connected accounts (%d) reached. Please remove an account before adding a new one.', 'fediboost' ),
					$max_accounts
				)
			);
		}

		// Check if account already exists.
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );
		foreach ( $accounts as $index => $account ) {
			$existing_host = wp_parse_url( $account['instance_url'], PHP_URL_HOST );
			if ( $existing_host === $hostname && $account['username'] === $username ) {
				// Update existing account.
				$accounts[ $index ] = array(
					'instance_url'    => $instance_url,
					'username'        => $username,
					'encrypted_token' => $encrypted_token,
					'status'          => self::STATUS_CONNECTED,
					'connected_at'    => time(),
				);
				return update_option( self::OPTION_KEY, $accounts, false );
			}
		}

		// Add new account.
		$accounts[] = array(
			'instance_url'    => $instance_url,
			'username'        => $username,
			'encrypted_token' => $encrypted_token,
			'status'          => self::STATUS_CONNECTED,
			'connected_at'    => time(),
		);

		return update_option( self::OPTION_KEY, $accounts, false );
	}

	/**
	 * Get accounts that are actively connected.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of accounts with connected status.
	 */
	public function get_connected_accounts() {
		$accounts  = $this->get_all_accounts();
		$connected = array();

		foreach ( $accounts as $account ) {
			if ( isset( $account['status'] ) && self::STATUS_CONNECTED === $account['status'] ) {
				$connected[] = $account;
			}
		}

		return $connected;
	}

	/**
	 * Get accounts that need reconnection.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of accounts with disconnected status.
	 */
	public function get_disconnected_accounts() {
		$accounts     = $this->get_all_accounts();
		$disconnected = array();

		foreach ( $accounts as $account ) {
			if ( isset( $account['status'] ) && self::STATUS_DISCONNECTED === $account['status'] ) {
				$account['key'] = self::generate_account_key( $account['instance_url'], $account['username'] );
				$disconnected[] = $account;
			}
		}

		return $disconnected;
	}

	/**
	 * Format username for display as @handle@instance.
	 *
	 * @since 1.0.0
	 *
	 * @param array $account The account data.
	 * @return string Formatted username.
	 */
	public function format_username_display( $account ) {
		$instance_host = wp_parse_url( $account['instance_url'], PHP_URL_HOST );
		return '@' . $account['username'] . '@' . $instance_host;
	}

	/**
	 * Clear all cached data for an account.
	 *
	 * @since 1.0.0
	 *
	 * @param array $account The account data.
	 */
	public function clear_account_cache( $account ) {
		// Clear any transients related to this account.
		$hostname = wp_parse_url( $account['instance_url'], PHP_URL_HOST );
		delete_transient( 'fediboost_account_' . hash( 'sha256', $hostname . $account['username'] ) );
	}

	/**
	 * Clear all accounts data.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public function clear_all_accounts() {
		return update_option( self::OPTION_KEY, array(), false );
	}
}
