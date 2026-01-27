<?php
/**
 * WP-CLI commands for FediBoost.
 *
 * Provides CLI commands for managing Mastodon accounts and boosting.
 *
 * @since 1.0.0
 *
 * @package kraftbj/fediboost
 */

namespace FediBoost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLI class.
 *
 * Registers WP-CLI commands for FediBoost operations.
 *
 * @since 1.0.0
 */
class CLI {

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @var CLI|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return CLI
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {
		$this->register_commands();
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @since 1.0.0
	 */
	private function register_commands() {
		\WP_CLI::add_command( 'fediboost accounts', array( $this, 'accounts_command' ) );
		\WP_CLI::add_command( 'fediboost boost', array( $this, 'boost_command' ) );
		\WP_CLI::add_command( 'fediboost status', array( $this, 'status_command' ) );
	}

	/**
	 * List Mastodon accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all accounts
	 *     $ wp fediboost accounts
	 *
	 *     # Output as JSON
	 *     $ wp fediboost accounts --format=json
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function accounts_command( $args, $assoc_args ) {
		$accounts_helper = Accounts::get_instance();
		$accounts        = $accounts_helper->get_all_accounts();

		if ( empty( $accounts ) ) {
			\WP_CLI::warning( 'No Mastodon accounts found.' );
			return;
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$output = array();
		foreach ( $accounts as $index => $account ) {
			$output[] = array(
				'index'        => $index,
				'username'     => $accounts_helper->format_username_display( $account ),
				'instance'     => $account['instance_url'],
				'status'       => $account['status'],
				'connected_at' => gmdate( 'Y-m-d H:i:s', $account['connected_at'] ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $output, array( 'index', 'username', 'instance', 'status', 'connected_at' ) );
	}

	/**
	 * Manually trigger a boost for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to boost.
	 *
	 * [--force]
	 * : Bypass eligibility checks and attempt boost anyway.
	 *
	 * ## EXAMPLES
	 *
	 *     # Boost post ID 123
	 *     $ wp fediboost boost 123
	 *
	 *     # Force boost even if not eligible
	 *     $ wp fediboost boost 123 --force
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function boost_command( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a post ID.' );
		}

		$post_id = absint( $args[0] );
		$force   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			\WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}

		// Check if post is published.
		if ( 'publish' !== $post->post_status ) {
			\WP_CLI::error( sprintf( 'Post %d is not published (status: %s).', $post_id, $post->post_status ) );
		}

		// Check connected accounts.
		$accounts_helper    = Accounts::get_instance();
		$connected_accounts = $accounts_helper->get_connected_accounts();
		if ( empty( $connected_accounts ) ) {
			\WP_CLI::error( 'No connected Mastodon accounts. Connect an account first.' );
		}

		// Check eligibility unless forced.
		$activitypub = ActivityPub::get_instance();
		if ( ! $force && ! $activitypub->is_post_eligible( $post ) ) {
			\WP_CLI::error( 'Post is not eligible for boosting (ActivityPub disabled or not public). Use --force to override.' );
		}

		\WP_CLI::log( sprintf( 'Boosting post %d: %s', $post_id, get_the_title( $post ) ) );

		// Execute the boost directly (bypass cron).
		$boost = Boost::get_instance();
		$boost->execute_boost( $post_id );

		\WP_CLI::success( sprintf( 'Boost triggered for post %d. Check logs or your Mastodon instance to confirm delivery.', $post_id ) );
	}

	/**
	 * Check boost status for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to check.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check boost status for post 123
	 *     $ wp fediboost status 123
	 *
	 *     # Output as JSON
	 *     $ wp fediboost status 123 --format=json
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status_command( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a post ID.' );
		}

		$post_id = absint( $args[0] );
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			\WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}

		$status = array(
			'post_id'      => $post_id,
			'post_title'   => get_the_title( $post ),
			'post_status'  => $post->post_status,
			'is_published' => 'publish' === $post->post_status ? 'yes' : 'no',
		);

		// Check ActivityPub eligibility.
		$activitypub               = ActivityPub::get_instance();
		$status['ap_active']       = $activitypub->is_plugin_active() ? 'yes' : 'no';
		$status['ap_eligible']     = $activitypub->is_post_eligible( $post ) ? 'yes' : 'no';
		$status['activitypub_url'] = '';

		if ( $activitypub->is_post_eligible( $post ) ) {
			$ap_url                    = $activitypub->get_activitypub_url( $post );
			$status['activitypub_url'] = $ap_url ? $ap_url : 'unavailable';
		}

		// Check if boost is scheduled.
		$scheduled_time         = wp_next_scheduled( Boost::CRON_HOOK, array( $post_id ) );
		$status['scheduled']    = $scheduled_time ? 'yes' : 'no';
		$status['scheduled_at'] = $scheduled_time ? gmdate( 'Y-m-d H:i:s', $scheduled_time ) : 'n/a';

		// Check connected accounts.
		$accounts_helper              = Accounts::get_instance();
		$accounts                     = $accounts_helper->get_all_accounts();
		$connected_count              = count( $accounts_helper->get_connected_accounts() );
		$status['accounts']           = count( $accounts );
		$status['accounts_connected'] = $connected_count;

		if ( 'json' === $format || 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( $format, array( $status ), array_keys( $status ) );
		} else {
			// Table format - display as key-value pairs.
			$table_data = array();
			foreach ( $status as $key => $value ) {
				$table_data[] = array(
					'field' => $key,
					'value' => $value,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $table_data, array( 'field', 'value' ) );
		}
	}
}
