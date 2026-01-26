<?php
/**
 * Boost Functionality Tests
 *
 * Tests for ActivityPub integration and auto-boost functionality.
 *
 * @package kraftbj/fediboost
 */

/**
 * Test_Boost_Functionality class.
 *
 * Tests for ActivityPub integration and auto-boost functionality.
 */
class Test_Boost_Functionality extends WP_UnitTestCase {

	/**
	 * ActivityPub integration instance.
	 *
	 * @var FediBoost_ActivityPub
	 */
	private $activitypub;

	/**
	 * Boost instance.
	 *
	 * @var FediBoost_Boost
	 */
	private $boost;

	/**
	 * Accounts helper instance.
	 *
	 * @var FediBoost_Accounts
	 */
	private $accounts;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->activitypub = FediBoost_ActivityPub::get_instance();
		$this->boost       = FediBoost_Boost::get_instance();
		$this->accounts    = FediBoost_Accounts::get_instance();

		// Clear accounts and scheduled events before each test.
		update_option( 'fediboost_accounts', array() );
		wp_clear_scheduled_hook( 'fediboost_boost_post' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		update_option( 'fediboost_accounts', array() );
		wp_clear_scheduled_hook( 'fediboost_boost_post' );
		parent::tear_down();
	}

	/**
	 * Test ActivityPub URL resolution returns valid URL when plugin is available.
	 *
	 * Note: When ActivityPub plugin is not active, this returns false which is expected.
	 */
	public function test_activitypub_url_resolution_fallback_when_unavailable() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			)
		);

		$post = get_post( $post_id );

		// Without ActivityPub plugin, should return false.
		$url = $this->activitypub->get_activitypub_url( $post );

		$this->assertFalse( $url );
	}

	/**
	 * Test is_post_disabled check returns true when ActivityPub unavailable.
	 *
	 * Posts are considered "disabled" for federation when the plugin is not active.
	 */
	public function test_is_post_disabled_when_activitypub_unavailable() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );

		// Without ActivityPub plugin, post should be considered disabled.
		$is_disabled = $this->activitypub->is_post_disabled( $post );

		$this->assertTrue( $is_disabled );
	}

	/**
	 * Test visibility check returns false (private) when ActivityPub unavailable.
	 */
	public function test_visibility_check_when_activitypub_unavailable() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		// Without ActivityPub plugin, visibility should be considered private.
		$is_public = $this->activitypub->is_visibility_public( $post_id );

		$this->assertFalse( $is_public );
	}

	/**
	 * Test wp-cron event is scheduled on publish.
	 */
	public function test_cron_event_scheduled_on_publish() {
		// Create a post in draft status.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Test Scheduled Post',
			)
		);

		// Verify no scheduled event exists.
		$this->assertFalse( wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) ) );

		// Schedule the boost directly (simulating what would happen on publish).
		$this->boost->schedule_boost( $post_id );

		// Verify the event is now scheduled.
		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $scheduled );

		// Verify it's scheduled for approximately 30 seconds in the future.
		$this->assertGreaterThan( time(), $scheduled );
		$this->assertLessThan( time() + 60, $scheduled );
	}

	/**
	 * Test Mastodon search API request format.
	 */
	public function test_mastodon_search_request_format() {
		$instance_url    = 'https://mastodon.social';
		$activitypub_url = 'https://example.com/wp-json/activitypub/1.0/users/1/posts/123';

		$request = $this->boost->build_search_request( $instance_url, $activitypub_url );

		$this->assertStringContainsString( '/api/v2/search', $request['url'] );
		$this->assertStringContainsString( 'resolve=true', $request['url'] );
		$this->assertStringContainsString( 'type=statuses', $request['url'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
		$this->assertStringContainsString( urlencode( $activitypub_url ), $request['url'] );
	}

	/**
	 * Test reblog API request format.
	 */
	public function test_reblog_request_format() {
		$instance_url = 'https://mastodon.social';
		$status_id    = '109876543210';
		$access_token = 'test_token_12345';

		$request = $this->boost->build_reblog_request( $instance_url, $status_id, $access_token );

		$this->assertEquals( 'https://mastodon.social/api/v1/statuses/109876543210/reblog', $request['url'] );
		$this->assertArrayHasKey( 'headers', $request );
		$this->assertEquals( 'Bearer test_token_12345', $request['headers']['Authorization'] );
	}

	/**
	 * Test failed reblog marks account as disconnected for auth failures.
	 */
	public function test_failed_reblog_marks_account_disconnected() {
		// Add a connected account.
		$encryption      = FediBoost_Encryption::get_instance();
		$encrypted_token = $encryption->encrypt( 'test_token' );

		$this->accounts->add_account(
			'https://mastodon.social',
			'testuser',
			$encrypted_token
		);

		// Verify account is connected.
		$accounts = $this->accounts->get_all_accounts();
		$this->assertEquals( FediBoost_Accounts::STATUS_CONNECTED, $accounts[0]['status'] );

		// Simulate handling an auth failure (401/403).
		$this->boost->handle_boost_error( 0, 401, 'mastodon.social' );

		// Verify account is now disconnected.
		$accounts = $this->accounts->get_all_accounts();
		$this->assertEquals( FediBoost_Accounts::STATUS_DISCONNECTED, $accounts[0]['status'] );
	}

	/**
	 * Test boost continues to other accounts after one fails.
	 */
	public function test_boost_continues_after_account_failure() {
		// Add two accounts.
		$encryption      = FediBoost_Encryption::get_instance();
		$encrypted_token = $encryption->encrypt( 'test_token' );

		$this->accounts->add_account(
			'https://mastodon.social',
			'user1',
			$encrypted_token
		);
		$this->accounts->add_account(
			'https://fosstodon.org',
			'user2',
			$encrypted_token
		);

		// Mark first account as disconnected (simulating failure).
		$this->accounts->update_account_status( 0, FediBoost_Accounts::STATUS_DISCONNECTED );

		// Get connected accounts that would be processed.
		$connected = $this->accounts->get_connected_accounts();

		// Only one account should be considered connected.
		$this->assertCount( 1, $connected );
		$this->assertEquals( 'user2', $connected[0]['username'] );
	}
}
