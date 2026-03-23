<?php
/**
 * Boost Functionality Tests
 *
 * Tests for ActivityPub integration and auto-boost functionality.
 *
 * @package kraftbj/fediboost
 */

use FediBoost\Accounts;
use FediBoost\ActivityPub;
use FediBoost\Boost;
use FediBoost\Encryption;

/**
 * Test_Boost_Functionality class.
 *
 * Tests for ActivityPub integration and auto-boost functionality.
 */
class Test_Boost_Functionality extends FediBoost_TestCase {

	/**
	 * ActivityPub integration instance.
	 *
	 * @var ActivityPub
	 */
	private $activitypub;

	/**
	 * Boost instance.
	 *
	 * @var Boost
	 */
	private $boost;

	/**
	 * Accounts helper instance.
	 *
	 * @var Accounts
	 */
	private $accounts;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->activitypub = ActivityPub::get_instance();
		$this->boost       = Boost::get_instance();
		$this->accounts    = Accounts::get_instance();

		// Clear accounts and scheduled events before each test.
		update_option( 'fediboost_accounts', array() );
		wp_unschedule_hook( 'fediboost_boost_post' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		remove_all_filters( 'fediboost_fallback_delay' );
		update_option( 'fediboost_accounts', array() );
		wp_unschedule_hook( 'fediboost_boost_post' );
		parent::tear_down();
	}

	/**
	 * Test ActivityPub URL resolution returns valid URL when plugin is available.
	 *
	 * Note: When ActivityPub plugin is not active, this returns false which is expected.
	 */
	public function test_activitypub_url_resolution_fallback_when_unavailable() {
		$post_id = $this->create_post(
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
		$post_id = $this->create_post(
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
		$post_id = $this->create_post(
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
		$post_id = $this->create_post(
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
		$encryption      = Encryption::get_instance();
		$encrypted_token = $encryption->encrypt( 'test_token' );

		$this->accounts->add_account(
			'https://mastodon.social',
			'testuser',
			$encrypted_token
		);

		// Verify account is connected.
		$accounts = $this->accounts->get_all_accounts();
		$this->assertEquals( Accounts::STATUS_CONNECTED, $accounts[0]['status'] );

		// Simulate handling an auth failure (401/403) using the stable account key.
		$account_key = Accounts::generate_account_key( 'https://mastodon.social', 'testuser' );
		$this->boost->handle_boost_error( $account_key, 401, 'mastodon.social' );

		// Verify account is now disconnected.
		$accounts = $this->accounts->get_all_accounts();
		$this->assertEquals( Accounts::STATUS_DISCONNECTED, $accounts[0]['status'] );
	}

	/**
	 * Test boost continues to other accounts after one fails.
	 */
	public function test_boost_continues_after_account_failure() {
		// Add two accounts.
		$encryption      = Encryption::get_instance();
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

		// Mark first account as disconnected (simulating failure) using the stable account key.
		$account_key = Accounts::generate_account_key( 'https://mastodon.social', 'user1' );
		$this->accounts->update_account_status( $account_key, Accounts::STATUS_DISCONNECTED );

		// Get connected accounts that would be processed.
		$connected = $this->accounts->get_connected_accounts();

		// Only one account should be considered connected.
		$this->assertCount( 1, $connected );
		$this->assertEquals( 'user2', $connected[0]['username'] );
	}

	/**
	 * Test schedule_fallback_boost schedules cron with the fallback delay.
	 */
	public function test_schedule_fallback_boost() {
		$post_id = $this->create_post( array( 'post_status' => 'draft' ) );

		$this->assertFalse( wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) ) );

		$before = time();
		$result = $this->boost->schedule_fallback_boost( $post_id );
		$after  = time();

		$this->assertTrue( $result );

		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $scheduled );

		// Should be scheduled ~300 seconds out (FALLBACK_DELAY).
		$this->assertGreaterThanOrEqual( $before + Boost::FALLBACK_DELAY, $scheduled );
		$this->assertLessThanOrEqual( $after + Boost::FALLBACK_DELAY, $scheduled );
	}

	/**
	 * Test schedule_fallback_boost respects the fediboost_fallback_delay filter.
	 */
	public function test_schedule_fallback_boost_uses_filter() {
		$post_id = $this->create_post( array( 'post_status' => 'draft' ) );

		add_filter(
			'fediboost_fallback_delay',
			function () {
				return 600;
			}
		);

		$before = time();
		$this->boost->schedule_fallback_boost( $post_id );
		$after = time();

		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertGreaterThanOrEqual( $before + 600, $scheduled );
		$this->assertLessThanOrEqual( $after + 600, $scheduled );
	}

	/**
	 * Test schedule_boost prevents duplicate scheduling.
	 */
	public function test_schedule_boost_prevents_duplicates() {
		$post_id = $this->create_post( array( 'post_status' => 'draft' ) );

		$first  = $this->boost->schedule_boost( $post_id );
		$second = $this->boost->schedule_boost( $post_id );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	/**
	 * Test schedule_fallback_boost prevents duplicate scheduling.
	 */
	public function test_schedule_fallback_boost_prevents_duplicates() {
		$post_id = $this->create_post( array( 'post_status' => 'draft' ) );

		$first  = $this->boost->schedule_fallback_boost( $post_id );
		$second = $this->boost->schedule_fallback_boost( $post_id );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	/**
	 * Test on_federation_complete reschedules boost with shorter delay.
	 */
	public function test_on_federation_complete_reschedules_boost() {
		$post_id    = $this->create_post( array( 'post_status' => 'publish' ) );
		$object_url = 'https://example.com/?p=' . $post_id;

		// Simulate the pending transient set by on_post_publish.
		set_transient( 'fediboost_pending_' . md5( $object_url ), $post_id, HOUR_IN_SECONDS );

		// Simulate a fallback cron event already scheduled.
		wp_schedule_single_event( time() + Boost::FALLBACK_DELAY, 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) ) );

		// Create an outbox item post with the required meta.
		$outbox_id = $this->create_post( array( 'post_type' => 'ap_outbox' ) );
		update_post_meta( $outbox_id, '_activitypub_activity_type', 'Create' );
		update_post_meta( $outbox_id, '_activitypub_object_id', $object_url );

		$before = time();
		$this->boost->on_federation_complete( array( 'https://remote.example/inbox' ), '{}', 1, $outbox_id );
		$after = time();

		// The fallback should have been cancelled and a new shorter-delay event scheduled.
		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThanOrEqual( $before + Boost::BOOST_DELAY, $scheduled );
		$this->assertLessThanOrEqual( $after + Boost::BOOST_DELAY, $scheduled );

		// The pending transient should be cleaned up.
		$this->assertFalse( get_transient( 'fediboost_pending_' . md5( $object_url ) ) );
	}

	/**
	 * Test on_federation_complete ignores non-Create activities.
	 */
	public function test_on_federation_complete_ignores_non_create_activities() {
		$post_id    = $this->create_post( array( 'post_status' => 'publish' ) );
		$object_url = 'https://example.com/?p=' . $post_id;

		// Set up a pending transient and fallback event.
		set_transient( 'fediboost_pending_' . md5( $object_url ), $post_id, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + Boost::FALLBACK_DELAY, 'fediboost_boost_post', array( $post_id ) );
		$original_scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );

		// Create an outbox item with an Update activity type.
		$outbox_id = $this->create_post( array( 'post_type' => 'ap_outbox' ) );
		update_post_meta( $outbox_id, '_activitypub_activity_type', 'Update' );
		update_post_meta( $outbox_id, '_activitypub_object_id', $object_url );

		$this->boost->on_federation_complete( array( 'https://remote.example/inbox' ), '{}', 1, $outbox_id );

		// The fallback schedule should remain unchanged.
		$this->assertEquals( $original_scheduled, wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) ) );

		// The transient should still exist.
		$this->assertEquals( $post_id, get_transient( 'fediboost_pending_' . md5( $object_url ) ) );
	}

	/**
	 * Test on_federation_complete uses JSON fallback when post meta is unavailable.
	 *
	 * Exercises the code path where _activitypub_activity_type and
	 * _activitypub_object_id meta are not set, so the method parses the
	 * JSON body to determine the activity type and object ID.
	 *
	 * @since 1.1.0
	 */
	public function test_on_federation_complete_json_fallback() {
		$post_id    = $this->create_post( array( 'post_status' => 'publish' ) );
		$object_url = 'https://example.com/?p=' . $post_id;

		// Simulate the pending transient set by on_post_publish.
		set_transient( 'fediboost_pending_' . md5( $object_url ), $post_id, HOUR_IN_SECONDS );

		// Simulate a fallback cron event already scheduled.
		wp_schedule_single_event( time() + Boost::FALLBACK_DELAY, 'fediboost_boost_post', array( $post_id ) );

		// Create an outbox item WITHOUT post meta — forces JSON fallback.
		$outbox_id = $this->create_post( array( 'post_type' => 'ap_outbox' ) );

		$json = wp_json_encode(
			array(
				'type'   => 'Create',
				'object' => array(
					'id'   => $object_url,
					'type' => 'Note',
				),
			)
		);

		$before = time();
		$this->boost->on_federation_complete( array( 'https://remote.example/inbox' ), $json, 1, $outbox_id );
		$after = time();

		// The boost should have been rescheduled with the shorter delay.
		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThanOrEqual( $before + Boost::BOOST_DELAY, $scheduled );
		$this->assertLessThanOrEqual( $after + Boost::BOOST_DELAY, $scheduled );

		// The pending transient should be cleaned up.
		$this->assertFalse( get_transient( 'fediboost_pending_' . md5( $object_url ) ) );
	}

	/**
	 * Test on_federation_complete handles string object URL in JSON.
	 *
	 * Per the ActivityPub spec, the object field can be a bare string URL
	 * instead of a nested object with an id field.
	 *
	 * @since 1.1.0
	 */
	public function test_on_federation_complete_json_string_object() {
		$post_id    = $this->create_post( array( 'post_status' => 'publish' ) );
		$object_url = 'https://example.com/?p=' . $post_id;

		// Simulate the pending transient.
		set_transient( 'fediboost_pending_' . md5( $object_url ), $post_id, HOUR_IN_SECONDS );

		// Schedule a fallback.
		wp_schedule_single_event( time() + Boost::FALLBACK_DELAY, 'fediboost_boost_post', array( $post_id ) );

		// Create outbox item without meta.
		$outbox_id = $this->create_post( array( 'post_type' => 'ap_outbox' ) );

		// JSON with object as a bare string URL (valid per ActivityPub spec).
		$json = wp_json_encode(
			array(
				'type'   => 'Create',
				'object' => $object_url,
			)
		);

		$before = time();
		$this->boost->on_federation_complete( array( 'https://remote.example/inbox' ), $json, 1, $outbox_id );
		$after = time();

		// The boost should have been rescheduled.
		$scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThanOrEqual( $before + Boost::BOOST_DELAY, $scheduled );
		$this->assertLessThanOrEqual( $after + Boost::BOOST_DELAY, $scheduled );
	}

	/**
	 * Test on_federation_complete handles malformed JSON gracefully.
	 *
	 * @since 1.1.0
	 */
	public function test_on_federation_complete_malformed_json() {
		$post_id    = $this->create_post( array( 'post_status' => 'publish' ) );
		$object_url = 'https://example.com/?p=' . $post_id;

		// Set up transient and fallback.
		set_transient( 'fediboost_pending_' . md5( $object_url ), $post_id, HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + Boost::FALLBACK_DELAY, 'fediboost_boost_post', array( $post_id ) );
		$original_scheduled = wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) );

		// Create outbox item without meta.
		$outbox_id = $this->create_post( array( 'post_type' => 'ap_outbox' ) );

		// Pass malformed JSON.
		$this->boost->on_federation_complete( array( 'https://remote.example/inbox' ), 'not valid json', 1, $outbox_id );

		// The fallback schedule should remain unchanged.
		$this->assertEquals( $original_scheduled, wp_next_scheduled( 'fediboost_boost_post', array( $post_id ) ) );

		// The transient should still exist.
		$this->assertEquals( $post_id, get_transient( 'fediboost_pending_' . md5( $object_url ) ) );
	}
}
