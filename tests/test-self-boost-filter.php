<?php
/**
 * Self-Boost Filter Tests
 *
 * Tests that reposts from connected Mastodon accounts are not recorded.
 *
 * @package kraftbj/fediboost
 */

use FediBoost\Accounts;
use FediBoost\Boost;

/**
 * Test_Self_Boost_Filter class.
 *
 * Tests for discarding repost interactions from connected accounts.
 */
class Test_Self_Boost_Filter extends FediBoost_TestCase {

	/**
	 * Boost instance.
	 *
	 * @var Boost
	 */
	private $boost;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->boost = Boost::get_instance();

		update_option( 'fediboost_accounts', array() );
		Accounts::get_instance()->add_account( 'https://mastodon.social', 'kraft', 'encrypted' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		remove_all_filters( 'fediboost_ignore_self_boosts' );
		update_option( 'fediboost_accounts', array() );
		parent::tear_down();
	}

	/**
	 * Build comment data shaped like an ActivityPub repost.
	 *
	 * @param string $author_url The actor profile URL.
	 * @param string $source_id  The Announce activity ID.
	 * @return array Comment data.
	 */
	private function repost( $author_url, $source_id ) {
		return array(
			'comment_post_ID'    => 1,
			'comment_type'       => 'repost',
			'comment_author_url' => $author_url,
			'comment_meta'       => array(
				'protocol'  => 'activitypub',
				'source_id' => $source_id,
			),
		);
	}

	/**
	 * Test the filter is registered.
	 */
	public function test_filter_is_registered() {
		$this->assertSame( 10, has_filter( 'pre_comment_approved', array( $this->boost, 'maybe_reject_self_boost' ) ) );
	}

	/**
	 * Test URL matching for connected accounts.
	 */
	public function test_is_connected_account_url() {
		$this->assertTrue( $this->boost->is_connected_account_url( 'https://mastodon.social/@kraft' ) );
		$this->assertTrue( $this->boost->is_connected_account_url( 'https://Mastodon.Social/@Kraft' ) );
		$this->assertTrue( $this->boost->is_connected_account_url( 'https://mastodon.social/users/kraft' ) );
		$this->assertTrue( $this->boost->is_connected_account_url( 'https://mastodon.social/users/kraft/statuses/123/activity' ) );

		$this->assertFalse( $this->boost->is_connected_account_url( 'https://mastodon.social/@kraftbj' ) );
		$this->assertFalse( $this->boost->is_connected_account_url( 'https://mastodon.social/users/someone/statuses/123/activity' ) );
		$this->assertFalse( $this->boost->is_connected_account_url( 'https://example.com/@kraft' ) );
		$this->assertFalse( $this->boost->is_connected_account_url( '' ) );
		$this->assertFalse( $this->boost->is_connected_account_url( 'not a url' ) );
	}

	/**
	 * Test reposts from a connected account are rejected.
	 */
	public function test_rejects_repost_from_connected_account() {
		$result = $this->boost->maybe_reject_self_boost(
			1,
			$this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fediboost_self_boost', $result->get_error_code() );
	}

	/**
	 * Test the activity ID alone is enough to identify the account.
	 */
	public function test_rejects_repost_matched_by_source_id() {
		$result = $this->boost->maybe_reject_self_boost(
			1,
			$this->repost( 'https://mastodon.social/some/other/profile', 'https://mastodon.social/users/kraft/statuses/1/activity' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test disconnected accounts are still matched.
	 */
	public function test_rejects_repost_from_disconnected_account() {
		$key = Accounts::generate_account_key( 'https://mastodon.social', 'kraft' );
		Accounts::get_instance()->update_account_status( $key, Accounts::STATUS_DISCONNECTED );

		$result = $this->boost->maybe_reject_self_boost(
			1,
			$this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test reposts from other people are left alone.
	 */
	public function test_allows_repost_from_other_account() {
		$result = $this->boost->maybe_reject_self_boost(
			1,
			$this->repost( 'https://mastodon.social/@someone', 'https://mastodon.social/users/someone/statuses/1/activity' )
		);

		$this->assertSame( 1, $result );
	}

	/**
	 * Test non-repost comments are left alone.
	 */
	public function test_ignores_other_comment_types() {
		$data                 = $this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1' );
		$data['comment_type'] = 'comment';

		$this->assertSame( 1, $this->boost->maybe_reject_self_boost( 1, $data ) );

		$data['comment_type'] = 'like';
		$this->assertSame( 1, $this->boost->maybe_reject_self_boost( 1, $data ) );
	}

	/**
	 * Test reposts not created by ActivityPub are left alone.
	 */
	public function test_ignores_non_activitypub_reposts() {
		$data = $this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' );
		unset( $data['comment_meta']['protocol'] );

		$this->assertSame( 1, $this->boost->maybe_reject_self_boost( 1, $data ) );
	}

	/**
	 * Test the behavior can be disabled via filter.
	 */
	public function test_filter_disables_rejection() {
		add_filter( 'fediboost_ignore_self_boosts', '__return_false' );

		$result = $this->boost->maybe_reject_self_boost(
			'0',
			$this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' )
		);

		$this->assertSame( '0', $result );
	}

	/**
	 * Test an existing WP_Error is passed through untouched.
	 */
	public function test_passes_through_existing_error() {
		$error = new WP_Error( 'comment_duplicate', 'Duplicate' );

		$result = $this->boost->maybe_reject_self_boost(
			$error,
			$this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' )
		);

		$this->assertSame( $error, $result );
	}

	/**
	 * Test is_self_boost() identifies reposts from connected accounts.
	 */
	public function test_is_self_boost() {
		$this->assertTrue(
			$this->boost->is_self_boost( $this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' ) )
		);

		$this->assertFalse(
			$this->boost->is_self_boost( $this->repost( 'https://mastodon.social/@someone', 'https://mastodon.social/users/someone/statuses/1/activity' ) )
		);

		$like                 = $this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft#likes/1' );
		$like['comment_type'] = 'like';
		$this->assertFalse( $this->boost->is_self_boost( $like ) );

		$this->assertFalse( $this->boost->is_self_boost( 'not an array' ) );
		$this->assertFalse( $this->boost->is_self_boost( array() ) );
	}

	/**
	 * Test is_self_boost() ignores the opt-out filter, which only governs incoming reposts.
	 */
	public function test_is_self_boost_ignores_opt_out_filter() {
		add_filter( 'fediboost_ignore_self_boosts', '__return_false' );

		$this->assertTrue(
			$this->boost->is_self_boost( $this->repost( 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' ) )
		);
	}
}
