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
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @return string|false Base64-encoded encrypted string with IV prepended, or false on failure.
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

		// Prepend IV to encrypted data and base64 encode.
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt an encrypted string.
	 *
	 * @param string $encrypted_data Base64-encoded encrypted string with IV prepended.
	 * @return string|false The decrypted plaintext, or false on failure.
	 */
	public function decrypt( $encrypted_data ) {
		if ( ! is_string( $encrypted_data ) || '' === $encrypted_data ) {
			return false;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$decoded = base64_decode( $encrypted_data, true );

		if ( false === $decoded ) {
			return false;
		}

		// Verify we have at least IV + 1 byte of data.
		if ( strlen( $decoded ) <= self::IV_LENGTH ) {
			return false;
		}

		// Extract IV from the beginning of the data.
		$iv        = substr( $decoded, 0, self::IV_LENGTH );
		$encrypted = substr( $decoded, self::IV_LENGTH );

		$key = $this->get_encryption_key();

		$decrypted = openssl_decrypt(
			$encrypted,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

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
