<?php
/**
 * Security helper class for FediBoost.
 *
 * Provides nonce verification, capability checks, and input sanitization.
 *
 * @package FediBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FediBoost_Security class.
 *
 * Centralized security utilities for the plugin.
 */
class FediBoost_Security {

	/**
	 * Nonce action for OAuth connect form.
	 *
	 * @var string
	 */
	const NONCE_ACTION_CONNECT = 'fediboost_connect';

	/**
	 * Nonce field name for OAuth connect form.
	 *
	 * @var string
	 */
	const NONCE_FIELD_CONNECT = 'fediboost_nonce';

	/**
	 * Nonce action prefix for disconnect action.
	 *
	 * @var string
	 */
	const NONCE_ACTION_DISCONNECT_PREFIX = 'fediboost_disconnect_';

	/**
	 * Single instance of the class.
	 *
	 * @var FediBoost_Security|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return FediBoost_Security
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
		// Private constructor for singleton pattern.
	}

	/**
	 * Check if current user has manage_options capability.
	 *
	 * @return bool True if user can manage options.
	 */
	public function user_can_manage() {
		$capability = apply_filters( 'fediboost_manage_capability', 'manage_options' );
		// Ensure capability grants at least editor-level access.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return false;
		}
		return current_user_can( $capability );
	}

	/**
	 * Verify current user can manage options and die if not.
	 *
	 * @param string $context Optional context for logging.
	 * @return bool True if user has capability.
	 */
	public function verify_user_capability( $context = '' ) {
		if ( ! $this->user_can_manage() ) {
			$this->log_security_violation( 'capability_check_failed', $context );
			return false;
		}
		return true;
	}

	/**
	 * Verify nonce for a given action.
	 *
	 * @param string $nonce  The nonce value to verify.
	 * @param string $action The nonce action.
	 * @return bool True if nonce is valid.
	 */
	public function verify_nonce( $nonce, $action ) {
		$result = wp_verify_nonce( $nonce, $action );

		if ( false === $result || 0 === $result ) {
			$this->log_security_violation( 'nonce_verification_failed', $action );
			return false;
		}

		return true;
	}

	/**
	 * Create nonce field for OAuth connect form.
	 *
	 * @return string HTML nonce field.
	 */
	public function get_connect_nonce_field() {
		return wp_nonce_field( self::NONCE_ACTION_CONNECT, self::NONCE_FIELD_CONNECT, true, false );
	}

	/**
	 * Verify nonce for OAuth connect form.
	 *
	 * @param string $nonce The nonce value to verify.
	 * @return bool True if nonce is valid.
	 */
	public function verify_connect_nonce( $nonce ) {
		return $this->verify_nonce( $nonce, self::NONCE_ACTION_CONNECT );
	}

	/**
	 * Get nonce action for disconnect.
	 *
	 * @param int $account_index The account index.
	 * @return string The nonce action.
	 */
	public function get_disconnect_nonce_action( $account_index ) {
		return self::NONCE_ACTION_DISCONNECT_PREFIX . intval( $account_index );
	}

	/**
	 * Verify nonce for disconnect action.
	 *
	 * @param string $nonce         The nonce value to verify.
	 * @param int    $account_index The account index.
	 * @return bool True if nonce is valid.
	 */
	public function verify_disconnect_nonce( $nonce, $account_index ) {
		$action = $this->get_disconnect_nonce_action( $account_index );
		return $this->verify_nonce( $nonce, $action );
	}

	/**
	 * Sanitize and validate a Mastodon instance URL.
	 *
	 * @param string $url The URL to sanitize.
	 * @return string|false Sanitized URL or false if invalid.
	 */
	public function sanitize_instance_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		$url = trim( $url );

		// Add https:// if no protocol specified.
		if ( ! preg_match( '~^https?://~i', $url ) ) {
			$url = 'https://' . $url;
		}

		// Upgrade http to https.
		$url = preg_replace( '~^http://~i', 'https://', $url );

		// Validate URL format.
		$url = filter_var( $url, FILTER_VALIDATE_URL );

		if ( false === $url ) {
			return false;
		}

		// Ensure it uses HTTPS.
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) ) {
			return false;
		}

		// Ensure we have a host.
		if ( ! isset( $parsed['host'] ) || '' === $parsed['host'] ) {
			return false;
		}

		// Strip trailing slashes for consistency.
		$url = untrailingslashit( $url );

		// Remove any path, query, or fragment - we only want the base URL.
		$url = $parsed['scheme'] . '://' . $parsed['host'];

		if ( isset( $parsed['port'] ) ) {
			$url .= ':' . $parsed['port'];
		}

		return $url;
	}

	/**
	 * Log a security violation for debugging.
	 *
	 * @param string $type    The type of violation.
	 * @param string $context Additional context.
	 */
	private function log_security_violation( $type, $context = '' ) {
		$user_id = get_current_user_id();
		$message = sprintf(
			'FediBoost security violation: %s (user: %d, context: %s)',
			$type,
			$user_id,
			$context
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}
	}

	/**
	 * Verify both capability and nonce for a request.
	 *
	 * @param string $nonce  The nonce value.
	 * @param string $action The nonce action.
	 * @return bool True if both checks pass.
	 */
	public function verify_request( $nonce, $action ) {
		if ( ! $this->verify_user_capability( $action ) ) {
			return false;
		}

		if ( ! $this->verify_nonce( $nonce, $action ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate that a URL host does not resolve to a private IP range.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if URL is safe to request, false otherwise.
	 */
	public function is_external_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		// Resolve hostname to IP.
		$ip = gethostbyname( $host );
		if ( $ip === $host ) {
			return false; // Resolution failed.
		}

		// Check for private/reserved IP ranges.
		$private_ranges = array(
			'10.0.0.0|10.255.255.255',
			'172.16.0.0|172.31.255.255',
			'192.168.0.0|192.168.255.255',
			'127.0.0.0|127.255.255.255',
			'169.254.0.0|169.254.255.255',
			'0.0.0.0|0.255.255.255',
		);

		$ip_long = ip2long( $ip );
		foreach ( $private_ranges as $range ) {
			list( $start, $end ) = explode( '|', $range );
			if ( $ip_long >= ip2long( $start ) && $ip_long <= ip2long( $end ) ) {
				return false;
			}
		}

		return true;
	}
}
