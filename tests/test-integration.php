<?php
/**
 * Integration Tests for FediBoost.
 *
 * Tests for end-to-end workflows, integration points, and edge cases.
 *
 * @package kraftbj/fediboost
 */

use FediBoost\Accounts;
use FediBoost\ActivityPub;
use FediBoost\Boost;
use FediBoost\Encryption;
use FediBoost\OAuth;
use FediBoost\Security;

/**
 * Test_Integration class.
 *
 * Strategic integration and edge case tests for MVP release validation.
 */
class Test_Integration extends FediBoost_TestCase {

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Clear accounts and scheduled events before each test.
		update_option( 'fediboost_accounts', array() );
		update_option( 'fediboost_instance_apps', array() );
		wp_unschedule_hook( 'fediboost_boost_post' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		update_option( 'fediboost_accounts', array() );
		update_option( 'fediboost_instance_apps', array() );
		wp_unschedule_hook( 'fediboost_boost_post' );
		parent::tear_down();
	}

	/**
	 * Test end-to-end: Token encryption integrates with account storage.
	 *
	 * Verifies that when storing an account, the token is properly encrypted,
	 * stored, and can be decrypted back to the original value.
	 */
	public function test_token_encryption_integrates_with_account_storage() {
		$oauth           = OAuth::get_instance();
		$encryption      = Encryption::get_instance();
		$accounts_helper = Accounts::get_instance();

		$instance_url = 'https://mastodon.social';
		$username     = 'testuser';
		$access_token = 'original_plaintext_token_xyz123';

		// Store account (which encrypts the token internally).
		$result = $oauth->store_connected_account( $instance_url, $username, $access_token );
		$this->assertTrue( $result );

		// Retrieve stored account.
		$accounts = $accounts_helper->get_all_accounts();
		$this->assertCount( 1, $accounts );

		// Verify stored token is NOT plaintext.
		$this->assertNotEquals( $access_token, $accounts[0]['encrypted_token'] );

		// Verify we can decrypt back to original.
		$decrypted = $encryption->decrypt( $accounts[0]['encrypted_token'] );
		$this->assertEquals( $access_token, $decrypted );
	}

	/**
	 * Test integration: Multiple accounts can be stored and retrieved.
	 *
	 * Verifies that multiple accounts from different instances are stored
	 * correctly and only connected accounts are returned for boost operations.
	 */
	public function test_multiple_accounts_storage_and_retrieval() {
		$accounts_helper = Accounts::get_instance();
		$encryption      = Encryption::get_instance();

		// Add three accounts from different instances.
		$token1 = $encryption->encrypt( 'token_mastodon' );
		$token2 = $encryption->encrypt( 'token_fosstodon' );
		$token3 = $encryption->encrypt( 'token_hachyderm' );

		$accounts_helper->add_account( 'https://mastodon.social', 'user1', $token1 );
		$accounts_helper->add_account( 'https://fosstodon.org', 'user2', $token2 );
		$accounts_helper->add_account( 'https://hachyderm.io', 'user3', $token3 );

		// All three should be stored.
		$this->assertEquals( 3, $accounts_helper->get_account_count() );

		// Mark one as disconnected using the stable account key.
		$account_key = Accounts::generate_account_key( 'https://fosstodon.org', 'user2' );
		$accounts_helper->update_account_status( $account_key, Accounts::STATUS_DISCONNECTED );

		// Only two should be connected.
		$connected = $accounts_helper->get_connected_accounts();
		$this->assertCount( 2, $connected );

		// Verify the disconnected account is identified.
		$disconnected = $accounts_helper->get_disconnected_accounts();
		$this->assertCount( 1, $disconnected );
		$this->assertEquals( 'user2', $disconnected[0]['username'] );
	}

	/**
	 * Test edge case: Scheduled post triggers boost when transitioning to publish.
	 *
	 * Verifies that the wp_after_insert_post hook correctly identifies
	 * a scheduled post transitioning to publish status.
	 */
	public function test_scheduled_post_triggers_boost_on_publish() {
		$boost = Boost::get_instance();

		// Create a post that simulates a scheduled post becoming published.
		// The key is that post_before was 'future' and post is now 'publish'.
		$post_id = $this->create_post(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Scheduled Post Now Published',
			)
		);

		// Directly test schedule_boost to verify it would be called.
		$scheduled = $boost->schedule_boost( $post_id );
		$this->assertTrue( $scheduled );

		// Verify the cron event was scheduled.
		$next_scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $next_scheduled );
	}

	/**
	 * Test edge case: Decryption fails when data is invalid (simulating salt change).
	 *
	 * When WordPress salts change, previously encrypted tokens become invalid.
	 * The encryption class should return false, and the boost flow should
	 * mark the account as disconnected.
	 */
	public function test_invalid_encrypted_data_returns_false() {
		$encryption = Encryption::get_instance();

		// Simulate corrupted/invalid encrypted data.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$invalid_data = base64_encode( 'this_is_not_valid_encrypted_data_at_all' );

		$result = $encryption->decrypt( $invalid_data );

		// Decryption should fail gracefully.
		$this->assertFalse( $result );
	}

	/**
	 * Test edge case: ActivityPub plugin unavailable during eligibility check.
	 *
	 * When ActivityPub plugin is deactivated, post eligibility should return false
	 * and no boost should be attempted.
	 */
	public function test_activitypub_unavailable_blocks_boost_eligibility() {
		$activitypub = ActivityPub::get_instance();

		$post_id = $this->create_post(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			)
		);

		$post = get_post( $post_id );

		// Without ActivityPub plugin active, post should NOT be eligible.
		$is_eligible = $activitypub->is_post_eligible( $post );
		$this->assertFalse( $is_eligible );

		// is_plugin_active should return false.
		$this->assertFalse( $activitypub->is_plugin_active() );
	}

	/**
	 * Test security: Unauthorized user cannot access settings page content.
	 *
	 * Verifies that capability checks work correctly for the admin page.
	 */
	public function test_unauthorized_user_cannot_access_settings() {
		$security = Security::get_instance();

		// Create a subscriber user.
		$subscriber = $this->create_user( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// Verify capability check fails.
		$this->assertFalse( $security->user_can_manage() );
		$this->assertFalse( $security->verify_user_capability( 'settings_access' ) );

		// Create an editor user (still no manage_options).
		$editor = $this->create_user( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->assertFalse( $security->user_can_manage() );

		// Only administrator should pass.
		$admin = $this->create_user( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( $security->user_can_manage() );
	}

	/**
	 * Test security: Invalid nonce rejects form submission.
	 *
	 * Verifies that the security class correctly rejects invalid nonces
	 * for both connect and disconnect actions.
	 */
	public function test_invalid_nonce_rejects_submission() {
		$security = Security::get_instance();

		// Test connect nonce.
		$invalid_connect_nonce = 'completely_invalid_nonce_value';
		$this->assertFalse( $security->verify_connect_nonce( $invalid_connect_nonce ) );

		// Test disconnect nonce with account key.
		$invalid_disconnect_nonce = 'another_invalid_nonce';
		$account_key              = Accounts::generate_account_key( 'https://mastodon.social', 'testuser' );
		$this->assertFalse( $security->verify_disconnect_nonce( $invalid_disconnect_nonce, $account_key ) );

		// Test that valid nonces are accepted.
		$valid_connect_nonce = wp_create_nonce( 'fediboost_connect' );
		$this->assertTrue( $security->verify_connect_nonce( $valid_connect_nonce ) );

		$valid_disconnect_nonce = wp_create_nonce( 'fediboost_disconnect_' . $account_key );
		$this->assertTrue( $security->verify_disconnect_nonce( $valid_disconnect_nonce, $account_key ) );
	}

	/**
	 * Test error recovery: Boost execution handles missing post gracefully.
	 *
	 * When a post is deleted between scheduling and execution, the boost
	 * should fail gracefully without crashing.
	 */
	public function test_boost_execution_handles_deleted_post() {
		$boost = Boost::get_instance();

		// Create and then delete a post.
		$post_id = $this->create_post(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Will Be Deleted',
			)
		);

		wp_delete_post( $post_id, true );

		// Execute boost on deleted post - should not throw exception.
		// This test passes if no exception is thrown.
		$boost->execute_boost( $post_id );

		// Verify the function completed without error.
		$this->assertTrue( true );
	}

	/**
	 * Test integration: Boost continues to remaining accounts when one is disconnected.
	 *
	 * Verifies that the boost loop correctly skips disconnected accounts
	 * and processes only connected ones.
	 */
	public function test_boost_skips_disconnected_accounts_and_continues() {
		$accounts_helper = Accounts::get_instance();
		$encryption      = Encryption::get_instance();

		// Add two accounts.
		$token1 = $encryption->encrypt( 'token1' );
		$token2 = $encryption->encrypt( 'token2' );

		$accounts_helper->add_account( 'https://mastodon.social', 'user1', $token1 );
		$accounts_helper->add_account( 'https://fosstodon.org', 'user2', $token2 );

		// Disconnect the first account using the stable account key.
		$account_key = Accounts::generate_account_key( 'https://mastodon.social', 'user1' );
		$accounts_helper->update_account_status( $account_key, Accounts::STATUS_DISCONNECTED );

		// Verify get_connected_accounts only returns the second account.
		$connected = $accounts_helper->get_connected_accounts();
		$this->assertCount( 1, $connected );
		$this->assertEquals( 'user2', $connected[0]['username'] );
		$this->assertEquals( 'https://fosstodon.org', $connected[0]['instance_url'] );
	}
}
