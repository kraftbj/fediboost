<?php
/**
 * Tests for Mastodon OAuth 2.0 authentication flow.
 *
 * @package kraftbj/fediboost
 */

/**
 * OAuth Flow Tests
 *
 * Tests for Mastodon OAuth 2.0 authentication flow.
 *
 * @package kraftbj/fediboost
 */
class Test_OAuth_Flow extends WP_UnitTestCase {

	/**
	 * OAuth instance.
	 *
	 * @var FediBoost_OAuth
	 */
	private $oauth;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->oauth = FediBoost_OAuth::get_instance();

		// Clean up any stored instance apps.
		delete_option( 'fediboost_instance_apps' );
		delete_option( 'fediboost_accounts' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		delete_option( 'fediboost_instance_apps' );
		delete_option( 'fediboost_accounts' );
		parent::tear_down();
	}

	/**
	 * Test OAuth app registration request format.
	 */
	public function test_oauth_app_registration_request_format() {
		$instance_url = 'https://mastodon.social';

		$request = $this->oauth->build_app_registration_request( $instance_url );

		$this->assertEquals( 'https://mastodon.social/api/v1/apps', $request['endpoint'] );
		$this->assertArrayHasKey( 'client_name', $request['body'] );
		$this->assertArrayHasKey( 'redirect_uris', $request['body'] );
		$this->assertArrayHasKey( 'scopes', $request['body'] );
		$this->assertEquals( 'FediBoost for WordPress', $request['body']['client_name'] );
		$this->assertStringContainsString( 'read', $request['body']['scopes'] );
		$this->assertStringContainsString( 'write:statuses', $request['body']['scopes'] );
	}

	/**
	 * Test authorization URL generation with correct parameters.
	 */
	public function test_authorization_url_generation_with_correct_parameters() {
		$instance_url = 'https://mastodon.social';
		$client_id    = 'test_client_id_12345';

		$auth_url = $this->oauth->generate_authorization_url( $instance_url, $client_id );

		$this->assertStringStartsWith( 'https://mastodon.social/oauth/authorize?', $auth_url );
		$this->assertStringContainsString( 'response_type=code', $auth_url );
		$this->assertStringContainsString( 'client_id=' . $client_id, $auth_url );
		$this->assertStringContainsString( 'redirect_uri=', $auth_url );
		$this->assertStringContainsString( 'scope=', $auth_url );
		$this->assertStringContainsString( 'state=', $auth_url );
	}

	/**
	 * Test token exchange request format.
	 */
	public function test_token_exchange_request_format() {
		$instance_url  = 'https://mastodon.social';
		$code          = 'test_auth_code_xyz';
		$client_id     = 'test_client_id';
		$client_secret = 'test_client_secret';

		$request = $this->oauth->build_token_exchange_request( $instance_url, $code, $client_id, $client_secret );

		$this->assertEquals( 'https://mastodon.social/oauth/token', $request['endpoint'] );
		$this->assertEquals( 'authorization_code', $request['body']['grant_type'] );
		$this->assertEquals( $client_id, $request['body']['client_id'] );
		$this->assertEquals( $client_secret, $request['body']['client_secret'] );
		$this->assertEquals( $code, $request['body']['code'] );
		$this->assertArrayHasKey( 'redirect_uri', $request['body'] );
		$this->assertArrayHasKey( 'scope', $request['body'] );
	}

	/**
	 * Test callback handling stores encrypted token.
	 */
	public function test_callback_handling_stores_encrypted_token() {
		$instance_url = 'https://mastodon.social';
		$username     = 'testuser';
		$access_token = 'plaintext_token_12345';

		$result = $this->oauth->store_connected_account( $instance_url, $username, $access_token );

		$this->assertTrue( $result );

		$accounts = get_option( 'fediboost_accounts', array() );
		$this->assertCount( 1, $accounts );
		$this->assertEquals( $instance_url, $accounts[0]['instance_url'] );
		$this->assertEquals( $username, $accounts[0]['username'] );
		$this->assertEquals( 'connected', $accounts[0]['status'] );

		// Verify the token is encrypted (not stored as plaintext).
		$this->assertNotEquals( $access_token, $accounts[0]['encrypted_token'] );

		// Verify the token can be decrypted back to original.
		$encryption = FediBoost_Encryption::get_instance();
		$decrypted  = $encryption->decrypt( $accounts[0]['encrypted_token'] );
		$this->assertEquals( $access_token, $decrypted );
	}

	/**
	 * Test error handling marks failed auth appropriately.
	 */
	public function test_error_handling_marks_failed_auth_appropriately() {
		// First, add a connected account.
		$accounts = array(
			array(
				'instance_url'    => 'https://mastodon.social',
				'username'        => 'testuser',
				'encrypted_token' => 'encrypted_value',
				'status'          => 'connected',
				'connected_at'    => time(),
			),
		);
		update_option( 'fediboost_accounts', $accounts );

		// Mark the account as disconnected.
		$result = $this->oauth->mark_account_disconnected( 0 );

		$this->assertTrue( $result );

		$accounts = get_option( 'fediboost_accounts', array() );
		$this->assertEquals( 'disconnected', $accounts[0]['status'] );
	}

	/**
	 * Test instance app credentials are cached.
	 */
	public function test_instance_app_credentials_are_cached() {
		$instance_url = 'https://mastodon.social';
		$credentials  = array(
			'client_id'     => 'test_client_id',
			'client_secret' => 'test_client_secret',
			'created_at'    => time(),
		);

		// Store credentials manually to simulate caching.
		$apps              = array();
		$hostname          = wp_parse_url( $instance_url, PHP_URL_HOST );
		$apps[ $hostname ] = $credentials;
		update_option( 'fediboost_instance_apps', $apps );

		// Verify we can retrieve cached credentials.
		$cached = $this->oauth->get_cached_app_credentials( $instance_url );

		$this->assertNotFalse( $cached );
		$this->assertEquals( 'test_client_id', $cached['client_id'] );
		$this->assertEquals( 'test_client_secret', $cached['client_secret'] );
	}

	/**
	 * Test state parameter generation and verification for CSRF protection.
	 */
	public function test_state_parameter_csrf_protection() {
		$instance_url = 'https://mastodon.social';
		$client_id    = 'test_client_id';

		// Generate authorization URL which creates a state.
		$auth_url = $this->oauth->generate_authorization_url( $instance_url, $client_id );

		// Extract state from URL.
		$parsed_url = wp_parse_url( $auth_url );
		parse_str( $parsed_url['query'], $params );

		$this->assertArrayHasKey( 'state', $params );
		$state = $params['state'];

		// Verify the state can be validated and returns instance data.
		$state_data = $this->oauth->verify_state( $state );

		$this->assertNotFalse( $state_data );
		$this->assertEquals( $instance_url, $state_data['instance_url'] );
		$this->assertArrayHasKey( 'created_at', $state_data );

		// Verify the state cannot be reused (replay protection).
		$state_data_again = $this->oauth->verify_state( $state );
		$this->assertFalse( $state_data_again );
	}
}
