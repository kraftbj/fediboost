<?php
/**
 * Security helper class for FediBoost.
 *
 * Provides nonce verification, capability checks, and input sanitization.
 *
 * @package kraftbj/fediboost
 */

namespace FediBoost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security class.
 *
 * Centralized security utilities for the plugin.
 */
class Security {

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
	 * @var Security|null
	 */
	private static $instance = null;

	/**
	 * Last validated IP address from is_external_url().
	 *
	 * Stored so callers can pin the resolved IP to the subsequent HTTP request,
	 * closing the TOCTOU gap between DNS validation and request dispatch.
	 *
	 * @var string|null
	 */
	private $pinned_ip = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Security
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
	protected function __construct() {
		// Protected constructor for singleton pattern.
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
	 * @param string $account_key The stable account key.
	 * @return string The nonce action.
	 */
	public function get_disconnect_nonce_action( $account_key ) {
		return self::NONCE_ACTION_DISCONNECT_PREFIX . sanitize_key( $account_key );
	}

	/**
	 * Verify nonce for disconnect action.
	 *
	 * @param string $nonce       The nonce value to verify.
	 * @param string $account_key The stable account key.
	 * @return bool True if nonce is valid.
	 */
	public function verify_disconnect_nonce( $nonce, $account_key ) {
		$action = $this->get_disconnect_nonce_action( $account_key );
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
	 * Validate that a URL host does not resolve to a private or reserved IP range.
	 *
	 * Checks both A (IPv4) and AAAA (IPv6) DNS records. Rejects the URL if any
	 * resolved address is private or reserved. On success, stores the first valid
	 * IP address so callers can pin the subsequent HTTP request via get_pinned_ip().
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if URL is safe to request, false otherwise.
	 */
	public function is_external_url( $url ) {
		// Reset pinned IP on every call.
		$this->pinned_ip = null;

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		$ips = array();

		// Resolve both A and AAAA records when dns_get_record is available.
		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record emits warnings on failure.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		// Fall back to gethostbyname if no records were found.
		if ( empty( $ips ) ) {
			$ip = gethostbyname( $host );
			if ( $ip === $host ) {
				return false; // Resolution failed entirely.
			}
			$ips[] = $ip;
		}

		// Reject if any resolved IP is private or reserved (covers IPv4 and IPv6).
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		// Store the first validated IPv4 address for IP pinning.
		// Prefer IPv4 because cURL CURLOPT_RESOLVE requires a concrete address and
		// IPv4 is universally supported across hosting environments.
		foreach ( $ips as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$this->pinned_ip = $ip;
				break;
			}
		}

		// Fall back to the first address if no IPv4 was found.
		if ( null === $this->pinned_ip ) {
			$this->pinned_ip = $ips[0];
		}

		return true;
	}

	/**
	 * Get the IP address validated by the last successful is_external_url() call.
	 *
	 * Callers should use this to pin the HTTP request to the resolved IP, closing
	 * the TOCTOU window between DNS validation and the actual request.
	 *
	 * @return string|null The validated IP address, or null if not available.
	 */
	public function get_pinned_ip() {
		return $this->pinned_ip;
	}
}
