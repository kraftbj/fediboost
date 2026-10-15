<?php
/**
 * Self-Boost Cleanup Command Tests
 *
 * Runs `wp fediboost cleanup-self-boosts` against a real (SQLite) comments
 * table, with WP-CLI replaced by the recording stub in tests/stubs/wp-cli.php.
 *
 * @package kraftbj/fediboost
 */

use FediBoost\Accounts;
use FediBoost\Boost;
use FediBoost\CLI;

/**
 * Test_Self_Boost_CLI class.
 */
class Test_Self_Boost_CLI extends FediBoost_SQLite_TestCase {

	/**
	 * Post the fixture comments belong to.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Boost singleton before a test swapped in a double.
	 *
	 * @var Boost|null
	 */
	private $original_boost;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

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
			)
		);

		$this->post_id        = $this->create_post();
		$this->original_boost = Boost::get_instance();

		\WP_CLI::$output = array();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		$this->set_boost_instance( $this->original_boost );
		parent::tear_down();
	}

	/**
	 * Replace the Boost singleton the command uses.
	 *
	 * @param Boost $boost Boost instance.
	 */
	private function set_boost_instance( $boost ) {
		$property = new ReflectionProperty( Boost::class, 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $boost );
	}

	/**
	 * Swap in a Boost double for the trash and removal behavior.
	 *
	 * @param bool  $trash_enabled Whether the trash is enabled.
	 * @param int[] $fail_ids      Comment IDs whose removal fails.
	 */
	private function use_boost_double( $trash_enabled, $fail_ids = array() ) {
		$boost = new class() extends Boost {

			/**
			 * Whether the trash is enabled.
			 *
			 * @var bool
			 */
			public $trash_enabled = true;

			/**
			 * Comment IDs whose removal fails.
			 *
			 * @var int[]
			 */
			public $fail_ids = array();

			/**
			 * Skip the parent constructor so hooks are not registered twice.
			 */
			public function __construct() {}

			/**
			 * Report the configured trash state.
			 *
			 * @return bool
			 */
			public function is_trash_enabled() {
				return $this->trash_enabled;
			}

			/**
			 * Fail for the configured IDs, otherwise remove as usual.
			 *
			 * @param WP_Comment $comment Comment.
			 * @param bool       $force   Whether to delete permanently.
			 * @return bool
			 */
			public function remove_self_boost_comment( $comment, $force = false ) {
				if ( in_array( (int) $comment->comment_ID, $this->fail_ids, true ) ) {
					return false;
				}
				return parent::remove_self_boost_comment( $comment, $force );
			}
		};

		$boost->trash_enabled = $trash_enabled;
		$boost->fail_ids      = $fail_ids;

		$this->set_boost_instance( $boost );
	}

	/**
	 * Insert an ActivityPub repost directly, bypassing the incoming filter.
	 *
	 * @param string $user     Mastodon username on mastodon.social.
	 * @param string $approved Approval status.
	 * @return int Comment ID.
	 */
	private function insert_repost( $user, $approved = '1' ) {
		return wp_insert_comment(
			array(
				'comment_post_ID'    => $this->post_id,
				'comment_author'     => $user,
				'comment_author_url' => "https://mastodon.social/@$user",
				'comment_content'    => 'fixture',
				'comment_type'       => 'repost',
				'comment_approved'   => $approved,
				'comment_meta'       => array(
					'protocol'  => 'activitypub',
					'source_id' => "https://mastodon.social/users/$user/statuses/1/activity",
				),
			)
		);
	}

	/**
	 * Insert two self reposts and one from someone else.
	 *
	 * @return array<string, int> Comment IDs keyed by label.
	 */
	private function seed() {
		return array(
			'self_approved' => $this->insert_repost( 'kraft' ),
			'self_spam'     => $this->insert_repost( 'kraft', 'spam' ),
			'other_user'    => $this->insert_repost( 'someone' ),
		);
	}

	/**
	 * Run the command.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return bool True if the command exited with an error.
	 */
	private function run_command( $assoc_args = array() ) {
		try {
			CLI::get_instance()->cleanup_self_boosts_command( array(), $assoc_args );
		} catch ( \WP_CLI\ExitException $e ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the comment IDs in the one list the command printed.
	 *
	 * @return int[] Sorted comment IDs.
	 */
	private function listed_ids() {
		$items = \WP_CLI::get( 'items' );
		$this->assertCount( 1, $items );

		$ids = wp_list_pluck( $items[0][1], 'comment_id' );
		sort( $ids );
		return $ids;
	}

	/**
	 * Get the expected IDs of the self reposts, sorted.
	 *
	 * @param array<string, int> $ids Seeded comment IDs.
	 * @return int[] Sorted comment IDs.
	 */
	private function self_ids( $ids ) {
		$expected = array( $ids['self_approved'], $ids['self_spam'] );
		sort( $expected );
		return $expected;
	}

	/**
	 * Test the command refuses to run without stored accounts.
	 */
	public function test_errors_without_accounts() {
		$this->seed();
		update_option( Accounts::OPTION_KEY, array() );

		$this->assertTrue( $this->run_command() );
		$this->assertSame( array( 'No Mastodon accounts are stored, so there is nothing to match reposts against.' ), \WP_CLI::get( 'error' ) );
		$this->assertSame( array(), \WP_CLI::get( 'items' ) );
	}

	/**
	 * Test the table format reports success when nothing matches.
	 */
	public function test_no_matches_reports_success() {
		$this->insert_repost( 'someone' );

		$this->assertFalse( $this->run_command() );
		$this->assertSame( array( 'No reposts from connected accounts found.' ), \WP_CLI::get( 'success' ) );
		$this->assertSame( array(), \WP_CLI::get( 'items' ) );
	}

	/**
	 * Test machine-readable formats print an empty list, and nothing else, when nothing matches.
	 */
	public function test_no_matches_machine_format_prints_empty_list() {
		$this->insert_repost( 'someone' );

		$this->assertFalse( $this->run_command( array( 'format' => 'json' ) ) );
		$this->assertSame( array( 'items' ), array_column( \WP_CLI::$output, 0 ) );
		$this->assertSame( array( 'json', array() ), array_slice( \WP_CLI::get( 'items' )[0], 0, 2 ) );
	}

	/**
	 * Test a dry run lists the self reposts and changes nothing.
	 */
	public function test_dry_run_lists_without_removing() {
		$ids = $this->seed();

		$this->assertFalse( $this->run_command( array( 'dry-run' => true ) ) );
		$this->assertSame( $this->self_ids( $ids ), $this->listed_ids() );
		$this->assertSame( array( 'Dry run: 2 repost(s) would be moved to the trash.' ), \WP_CLI::get( 'log' ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_approved'] ) );
		$this->assertSame( 'spam', wp_get_comment_status( $ids['self_spam'] ) );
	}

	/**
	 * Test the default run trashes only the self reposts.
	 */
	public function test_trashes_by_default() {
		$ids = $this->seed();

		$this->assertFalse( $this->run_command() );
		$this->assertSame( $this->self_ids( $ids ), $this->listed_ids() );
		$this->assertSame( array( '2 repost(s) moved to the trash.' ), \WP_CLI::get( 'success' ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_approved'] ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_spam'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['other_user'] ) );
	}

	/**
	 * Test machine-readable formats skip the summary line.
	 */
	public function test_machine_format_omits_summary() {
		$ids = $this->seed();

		$this->assertFalse( $this->run_command( array( 'format' => 'count' ) ) );
		$this->assertSame( array( 'items' ), array_column( \WP_CLI::$output, 0 ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_approved'] ) );
	}

	/**
	 * Test --force deletes the self reposts permanently.
	 */
	public function test_force_deletes() {
		$ids = $this->seed();

		$this->assertFalse( $this->run_command( array( 'force' => true ) ) );
		$this->assertSame( array( '2 repost(s) permanently deleted.' ), \WP_CLI::get( 'success' ) );
		$this->assertNull( get_comment( $ids['self_approved'] ) );
		$this->assertNull( get_comment( $ids['self_spam'] ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['other_user'] ) );
	}

	/**
	 * Test an unforced run errors and removes nothing when the trash is disabled.
	 */
	public function test_trash_disabled_without_force_errors() {
		$ids = $this->seed();
		$this->use_boost_double( false );

		$this->assertTrue( $this->run_command() );
		$this->assertSame( $this->self_ids( $ids ), $this->listed_ids() );
		$this->assertCount( 1, \WP_CLI::get( 'error' ) );
		$this->assertStringContainsString( 'Re-run with --force', \WP_CLI::get( 'error' )[0] );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_approved'] ) );
		$this->assertSame( 'spam', wp_get_comment_status( $ids['self_spam'] ) );
	}

	/**
	 * Test an unforced dry run only warns when the trash is disabled.
	 */
	public function test_trash_disabled_dry_run_warns() {
		$ids = $this->seed();
		$this->use_boost_double( false );

		$this->assertFalse( $this->run_command( array( 'dry-run' => true ) ) );
		$this->assertCount( 1, \WP_CLI::get( 'warning' ) );
		$this->assertStringContainsString( 'Re-run with --force', \WP_CLI::get( 'warning' )[0] );
		$this->assertSame( array(), \WP_CLI::get( 'log' ) );
		$this->assertSame( 'approved', wp_get_comment_status( $ids['self_approved'] ) );
	}

	/**
	 * Test --force still deletes when the trash is disabled.
	 */
	public function test_trash_disabled_with_force_deletes() {
		$ids = $this->seed();
		$this->use_boost_double( false );

		$this->assertFalse( $this->run_command( array( 'force' => true ) ) );
		$this->assertSame( array( '2 repost(s) permanently deleted.' ), \WP_CLI::get( 'success' ) );
		$this->assertNull( get_comment( $ids['self_approved'] ) );
		$this->assertNull( get_comment( $ids['self_spam'] ) );
	}

	/**
	 * Test a failed removal is warned about and makes the command exit with an error.
	 */
	public function test_partial_failure_warns_and_errors() {
		$ids = $this->seed();
		$this->use_boost_double( true, array( $ids['self_spam'] ) );

		$this->assertTrue( $this->run_command() );
		$this->assertSame( array( sprintf( 'Could not remove comment %d.', $ids['self_spam'] ) ), \WP_CLI::get( 'warning' ) );
		$this->assertSame( array( '1 repost(s) moved to the trash. 1 could not be removed.' ), \WP_CLI::get( 'error' ) );
		$this->assertSame( array(), \WP_CLI::get( 'success' ) );
		$this->assertSame( 'trash', wp_get_comment_status( $ids['self_approved'] ) );
		$this->assertSame( 'spam', wp_get_comment_status( $ids['self_spam'] ) );
	}
}
