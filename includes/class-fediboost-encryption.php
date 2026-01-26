<?php
/**
 * Encryption utility class for FediBoost.
 *
 * Handles encryption and decryption of OAuth tokens using AES-256-CBC.
 *
 * @package FediBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FediBoost_Encryption class.
 *
 * Provides encryption and decryption functionality for sensitive data.
 */
class FediBoost_Encryption {

	/**
	 * Encryption method.
	 *
	 * @var string
	 */
	const CIPHER_METHOD = 'AES-256-CBC';

	/**
	 * Length of the initialization vector for AES-256-CBC.
	 *
	 * @var int
	 */
	const IV_LENGTH = 16;

	/**
	 * Length of the HMAC-SHA256 tag.
	 *
	 * @var int
	 */
	const HMAC_LENGTH = 32;

	/**
	 * Single instance of the class.
	 *
	 * @var FediBoost_Encryption|null
	 */
	private static $instance = null;

	/**
	 * Cached encryption key.
	 *
	 * @var string|null
	 */
	private $encryption_key = null;

	/**
	 * Cached HMAC key.
	 *
	 * @var string|null
	 */
	private $hmac_key = null;

	/**
	 * Get singleton instance.
	 *
	 * @return FediBoost_Encryption
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
	 * Get the encryption key derived from WordPress auth salt.
	 *
	 * @return string The 32-byte encryption key.
	 */
	private function get_encryption_key() {
		if ( null === $this->encryption_key ) {
			$salt                 = wp_salt( 'auth' );
			$this->encryption_key = hash( 'sha256', $salt, true );
		}
		return $this->encryption_key;
	}

	/**
	 * Get the HMAC key derived from WordPress secure_auth salt.
	 *
	 * Uses a different salt than the encryption key to ensure key separation.
	 *
	 * @return string The 32-byte HMAC key.
	 */
	private function get_hmac_key() {
		if ( null === $this->hmac_key ) {
			$salt           = wp_salt( 'secure_auth' );
			$this->hmac_key = hash( 'sha256', $salt, true );
		}
		return $this->hmac_key;
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Produces base64(IV + ciphertext + HMAC-SHA256) where the HMAC covers
	 * the IV and ciphertext to prevent padding oracle attacks.
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @return string|false Base64-encoded encrypted string, or false on failure.
	 */
	public function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return false;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		$key = $this->get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( self::IV_LENGTH );

		if ( false === $iv ) {
			return false;
		}

		$encrypted = openssl_encrypt(
			$plaintext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $encrypted ) {
			return false;
		}

		$data = $iv . $encrypted;
		$hmac = hash_hmac( 'sha256', $data, $this->get_hmac_key(), true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $data . $hmac );
	}

	/**
	 * Decrypt an encrypted string.
	 *
	 * Accepts both the current HMAC-authenticated format and the legacy format
	 * (without HMAC) for backward compatibility with tokens stored before the
	 * HMAC addition. Legacy tokens are decrypted with a logged notice.
	 *
	 * @param string $encrypted_data Base64-encoded encrypted string.
	 * @return string|false The decrypted plaintext, or false on failure.
	 */
	public function decrypt( $encrypted_data ) {
		if ( ! is_string( $encrypted_data ) || '' === $encrypted_data ) {
			return false;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $encrypted_data, true );

		if ( false === $decoded ) {
			return false;
		}

		$decoded_length = strlen( $decoded );
		$key            = $this->get_encryption_key();

		// Try HMAC-authenticated format: IV + ciphertext + HMAC (32 bytes).
		if ( $decoded_length > self::IV_LENGTH + self::HMAC_LENGTH ) {
			$hmac          = substr( $decoded, -self::HMAC_LENGTH );
			$data          = substr( $decoded, 0, -self::HMAC_LENGTH );
			$expected_hmac = hash_hmac( 'sha256', $data, $this->get_hmac_key(), true );

			if ( hash_equals( $expected_hmac, $hmac ) ) {
				$iv        = substr( $data, 0, self::IV_LENGTH );
				$encrypted = substr( $data, self::IV_LENGTH );

				return openssl_decrypt(
					$encrypted,
					self::CIPHER_METHOD,
					$key,
					OPENSSL_RAW_DATA,
					$iv
				);
			}
		}

		// Legacy fallback: IV + ciphertext without HMAC.
		if ( $decoded_length <= self::IV_LENGTH ) {
			return false;
		}

		$iv        = substr( $decoded, 0, self::IV_LENGTH );
		$encrypted = substr( $decoded, self::IV_LENGTH );

		$decrypted = openssl_decrypt(
			$encrypted,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false !== $decrypted && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'FediBoost: Decrypted token using legacy format without HMAC. Re-save to upgrade.' );
		}

		return $decrypted;
	}

	/**
	 * Check if encryption is available.
	 *
	 * @return bool True if encryption functions are available.
	 */
	public function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}
}
