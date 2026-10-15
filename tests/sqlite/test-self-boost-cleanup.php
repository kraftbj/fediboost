<?php
/**
 * Self-Boost Cleanup Tests
 *
 * Tests finding and removing stored self-boost reposts, and the incoming
 * filter, against a real (SQLite) comments table.
 *
 * @package kraftbj/fediboost
 */

use FediBoost\Accounts;
use FediBoost\Boost;

/**
 * Test_Self_Boost_Cleanup class.
 */
class Test_Self_Boost_Cleanup extends FediBoost_SQLite_TestCase {

	/**
	 * Boost instance.
	 *
	 * @var Boost
	 */
	private $boost;

	/**
	 * Post the fixture comments belong to.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->boost = Boost::get_instance();

		// Don't try to email the post author about new comments.
		add_filter( 'pre_wp_mail', '__return_false' );

		// wp_new_comment() reads the client IP, as it would for ActivityPub's inbox request.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		update_option(
			Accounts::OPTION_KEY,
			array(
				array(
					'instance_url'    => 'https://mastodon.social',
					'username'        => 'kraft',
					'encrypted_token' => 'x',
					'status'          => Accounts::STATUS_CONNECTED,
					'connected_at'    => 1,
				),
				array(
					'instance_url'    => 'https://hachyderm.io',
					'username'        => 'kraft',
					'encrypted_token' => 'x',
					'status'          => Accounts::STATUS_DISCONNECTED,
					'connected_at'    => 1,
				),
			)
		);

		$this->post_id = $this->create_post();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	/**
	 * Insert a comment directly, bypassing the incoming filter.
	 *
	 * @param string $type       Comment type.
	 * @param string $author_url Actor profile URL.
	 * @param string $source_id  Activity ID.
	 * @param string $approved   Approval status: '1', '0', 'spam', or 'trash'.
	 * @param bool   $protocol   Whether to add the ActivityPub protocol meta.
	 * @return int Comment ID.
	 */
	private function insert( $type, $author_url, $source_id, $approved = '1', $protocol = true ) {
		$meta = array( 'source_id' => $source_id );
		if ( $protocol ) {
			$meta['protocol'] = 'activitypub';
		}

		return wp_insert_comment(
			array(
				'comment_post_ID'    => $this->post_id,
				'comment_author'     => $author_url,
				'comment_author_url' => $author_url,
				'comment_content'    => 'fixture',
				'comment_type'       => $type,
				'comment_approved'   => $approved,
				'comment_meta'       => $meta,
			)
		);
	}

	/**
	 * Insert one comment for every case the cleanup has to tell apart.
	 *
	 * @return array<string, int> Comment IDs keyed by label.
	 */
	private function seed() {
		return array(
			'self_approved'    => $this->insert( 'repost', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/1/activity' ),
			'self_pending'     => $this->insert( 'repost', 'https://hachyderm.io/@kraft', 'https://hachyderm.io/users/kraft/statuses/2/activity', '0' ),
			'self_spam'        => $this->insert( 'repost', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/3/activity', 'spam' ),
			'self_trashed'     => $this->insert( 'repost', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/4/activity', 'trash' ),
			'other_user'       => $this->insert( 'repost', 'https://mastodon.social/@someone', 'https://mastodon.social/users/someone/statuses/5/activity' ),
			'lookalike'        => $this->insert( 'repost', 'https://mastodon.social/@kraftbj', 'https://mastodon.social/users/kraftbj/statuses/6/activity' ),
			'self_like'        => $this->insert( 'like', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft#likes/7' ),
			'self_no_protocol' => $this->insert( 'repost', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/8/activity', '1', false ),
			'self_reply'       => $this->insert( 'comment', 'https://mastodon.social/@kraft', 'https://mastodon.social/users/kraft/statuses/9' ),
		);
	}

	/**
	 * Get the IDs of a list of comments, sorted.
	 *
	 * @param WP_Comment[] $comments Comments.
	 * @return int[] Sorted comment IDs.
	 */
	private function ids( $comments ) {
		$ids = array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Test the suite is really running against SQLite.
	 */
	public function test_runs_on_sqlite() {
		global $wpdb;

		$this->assertSame( 'sqlite', DB_ENGINE );
		$this->assertInstanceOf( 'WP_SQLite_DB', $wpdb );
	}

	/**
	 * Test each test starts with no comments left over from another test.
	 */
	public function test_comments_do_not_leak_between_tests() {
		global $wpdb;

		$this->seed();

		// The 9 fixtures plus the sample comment WordPress installs.
		$this->assertSame( 10, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ) );
		$this->assertCount( 3, $this->boost->find_self_boost_comments() );
	}

	/**
	 * Test only approved, pending, and spam reposts from stored accounts are found.
	 */
	public function test_find_self_boost_comments_matches_only_own_reposts() {
		$ids = $this->seed();

		$expected = array( $ids['self_approved'], $ids['self_pending'], $ids['self_spam'] );
		sort( $expected );

		$this->assertSame( $expected, $this->ids( $this->boost->find_self_boost_comments() ) );
	}

	/**
	 * Test nothing is found when no accounts are stored.
	 */
	public function test_find_self_boost_comments_without_accounts() {
		$this->seed();
		update_option( Accounts::OPTION_KEY, array() );

		$this->assertSame( array(), $this->boost->find_self_boost_comments() );
	}

	/**
	 * Test removal trashes by default, including spam, and untrash restores the status.
	 */
	public function test_remove_trashes_by_default() {
		$ids = $this->seed();

		foreach ( $this->boost->find_self_boost_comments() as $comment ) {
			$this->assertTrue( $this->boost->remove_self_boost_comment( $comment ) );
		}

		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_approved'] ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_pending'] ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_spam'] ) );

		wp_untrash_comment( $ids['self_spam'] );
		$this->assertSame( 'spam', wp_get_comment_status( $ids['self_spam'] ) );

		$this->assertSame( 'approved', wp_get_comment_status( $ids['other_user'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['lookalike'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_like'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_no_protocol'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_reply'] ) );

		// Only the restored spam repost is left to find.
		$this->assertSame( array( $ids['self_spam'] ), $this->ids( $this->boost->find_self_boost_comments() ) );
	}

	/**
	 * Test forced removal deletes permanently.
	 */
	public function test_remove_force_deletes() {
		$ids = $this->seed();

		foreach ( $this->boost->find_self_boost_comments() as $comment ) {
			$this->assertTrue( $this->boost->remove_self_boost_comment( $comment, true ) );
		}

		$this->assertNull( get_comment( $ids['self_approved'] ) );
		$this->assertNull( get_comment( $ids['self_pending'] ) );
		$this->assertNull( get_comment( $ids['self_spam'] ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_trashed'] ) );
		$this->assertSame( array(), $this->boost->find_self_boost_comments() );
	}

	/**
	 * Build comment data the way ActivityPub passes reposts to wp_new_comment().
	 *
	 * @param string $user Mastodon username on mastodon.social.
	 * @param int    $n    Status number.
	 * @return array Comment data.
	 */
	private function incoming_repost( $user, $n ) {
		return array(
			'comment_post_ID'      => $this->post_id,
			'comment_author'       => $user,
			'comment_author_url'   => "https://mastodon.social/@$user",
			'comment_author_email' => '',
			'comment_content'      => '&hellip; reposted this!',
			'comment_type'         => 'repost',
			'comment_meta'         => array(
				'protocol'  => 'activitypub',
				'source_id' => "https://mastodon.social/users/$user/statuses/$n/activity",
			),
		);
	}

	/**
	 * Test the incoming filter stops a self repost from being stored.
	 */
	public function test_wp_new_comment_rejects_incoming_self_repost() {
		$result = wp_new_comment( $this->incoming_repost( 'kraft', 100 ), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fediboost_self_boost', $result->get_error_code() );
		$this->assertSame( 0, (int) get_comments( array( 'post_id' => $this->post_id, 'count' => true ) ) );
	}

	/**
	 * Test the incoming filter lets other people's reposts through.
	 */
	public function test_wp_new_comment_stores_other_users_repost() {
		$result = wp_new_comment( $this->incoming_repost( 'someone', 101 ), true );

		$this->assertIsInt( $result );
		$this->assertSame( 'repost', get_comment( $result )->comment_type );
	}

	/**
	 * Test the opt-out filter lets self reposts through.
	 */
	public function test_wp_new_comment_stores_self_repost_with_opt_out() {
		add_filter( 'fediboost_ignore_self_boosts', '__return_false' );

		$result = wp_new_comment( $this->incoming_repost( 'kraft', 102 ), true );

		$this->assertIsInt( $result );
	}
	/**
	 * Test unforced removal is refused when the trash is disabled.
	 *
	 * With EMPTY_TRASH_DAYS at 0, wp_trash_comment() deletes permanently, so
	 * only forced removal may delete.
	 */
	public function test_remove_refuses_unforced_when_trash_disabled() {
		$ids = $this->seed();

		$boost = new class() extends Boost {
			/**
			 * Skip the parent constructor so hooks are not registered twice.
			 */
			public function __construct() {}

			/**
			 * Pretend EMPTY_TRASH_DAYS is 0.
			 *
			 * @return bool
			 */
			public function is_trash_enabled() {
				return false;
			}
		};

		$comment = get_comment( $ids['self_approved'] );

		$this->assertFalse( $boost->remove_self_boost_comment( $comment ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_approved'] ) );

		$this->assertTrue( $boost->remove_self_boost_comment( $comment, true ) );
		$this->assertNull( get_comment( $ids['self_approved'] ) );
	}

	/**
	 * Test the trash is enabled in the default test environment.
	 */
	public function test_trash_enabled_by_default() {
		$this->assertTrue( $this->boost->is_trash_enabled() );
	}

	/**
	 * Test finding candidates uses a fixed number of queries, not one per comment.
	 */
	public function test_find_self_boost_comments_query_count_is_constant() {
		global $wpdb;

		$count_queries = function () use ( $wpdb ) {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$this->boost->find_self_boost_comments();
			return $wpdb->num_queries - $before;
		};

		$this->seed();
		$queries_before = $count_queries();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->insert( 'repost', "https://mastodon.social/@user$i", "https://mastodon.social/users/user$i/statuses/$i/activity" );
		}

		$this->assertSame( $queries_before, $count_queries() );
	}
}
