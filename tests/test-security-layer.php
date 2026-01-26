<?php
/**
 * Security Layer Tests
 *
 * Tests for encryption, nonce verification, capability checks, and input sanitization.
 *
 * @package Auto_Tooter
 */

/**
 * Class Test_Security_Layer
 */
class Test_Security_Layer extends WP_UnitTestCase {

	/**
	 * Test that token encryption produces different output than input.
	 */
	public function test_encryption_produces_different_output_than_input() {
		$encryption = Auto_Tooter_Encryption::get_instance();
		$plaintext  = 'test_oauth_token_12345';

		$encrypted = $encryption->encrypt( $plaintext );

		$this->assertNotFalse( $encrypted );
		$this->assertNotEquals( $plaintext, $encrypted );
		$this->assertIsString( $encrypted );
	}

	/**
	 * Test that token decryption recovers the original value.
	 */
	public function test_decryption_recovers_original_value() {
		$encryption = Auto_Tooter_Encryption::get_instance();
		$plaintext  = 'test_oauth_token_with_special_chars_!@#$%';

		$encrypted = $encryption->encrypt( $plaintext );
		$decrypted = $encryption->decrypt( $encrypted );

		$this->assertEquals( $plaintext, $decrypted );
	}

	/**
	 * Test that nonce verification rejects invalid nonces.
	 */
	public function test_nonce_verification_rejects_invalid_nonces() {
		$security = Auto_Tooter_Security::get_instance();

		$invalid_nonce = 'invalid_nonce_value_12345';
		$action        = 'auto_tooter_connect';

		$result = $security->verify_nonce( $invalid_nonce, $action );

		$this->assertFalse( $result );
	}

	/**
	 * Test that nonce verification accepts valid nonces.
	 */
	public function test_nonce_verification_accepts_valid_nonces() {
		$security = Auto_Tooter_Security::get_instance();

		$action = 'auto_tooter_connect';
		$nonce  = wp_create_nonce( $action );

		$result = $security->verify_nonce( $nonce, $action );

		$this->assertTrue( $result );
	}

	/**
	 * Test that capability check blocks unauthorized users.
	 */
	public function test_capability_check_blocks_unauthorized_users() {
		$security = Auto_Tooter_Security::get_instance();

		// Create a subscriber (no manage_options capability).
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( $security->user_can_manage() );
		$this->assertFalse( $security->verify_user_capability( 'test' ) );

		// Create an administrator (has manage_options capability).
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( $security->user_can_manage() );
		$this->assertTrue( $security->verify_user_capability( 'test' ) );
	}

	/**
	 * Test sanitization of instance URLs.
	 */
	public function test_sanitization_of_instance_urls() {
		$security = Auto_Tooter_Security::get_instance();

		// Valid URL with HTTPS.
		$this->assertEquals(
			'https://mastodon.social',
			$security->sanitize_instance_url( 'https://mastodon.social' )
		);

		// URL without protocol should get HTTPS added.
		$this->assertEquals(
			'https://fosstodon.org',
			$security->sanitize_instance_url( 'fosstodon.org' )
		);

		// HTTP should be upgraded to HTTPS.
		$this->assertEquals(
			'https://instance.example.com',
			$security->sanitize_instance_url( 'http://instance.example.com' )
		);

		// Trailing slashes should be stripped.
		$this->assertEquals(
			'https://mastodon.social',
			$security->sanitize_instance_url( 'https://mastodon.social/' )
		);

		// Path should be stripped.
		$this->assertEquals(
			'https://mastodon.social',
			$security->sanitize_instance_url( 'https://mastodon.social/some/path' )
		);

		// Invalid input should return false.
		$this->assertFalse( $security->sanitize_instance_url( '' ) );
		$this->assertFalse( $security->sanitize_instance_url( '   ' ) );
		$this->assertFalse( $security->sanitize_instance_url( 'not a valid url !!!' ) );
	}

	/**
	 * Test encryption with empty input returns false.
	 */
	public function test_encryption_with_empty_input_returns_false() {
		$encryption = Auto_Tooter_Encryption::get_instance();

		$this->assertFalse( $encryption->encrypt( '' ) );
		$this->assertFalse( $encryption->decrypt( '' ) );
	}

	/**
	 * Test decryption with invalid data returns false.
	 */
	public function test_decryption_with_invalid_data_returns_false() {
		$encryption = Auto_Tooter_Encryption::get_instance();

		// Invalid base64.
		$this->assertFalse( $encryption->decrypt( '!!!not-valid-base64!!!' ) );

		// Too short data (less than IV length).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertFalse( $encryption->decrypt( base64_encode( 'short' ) ) );
	}
}
