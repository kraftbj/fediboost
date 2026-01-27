<?php
/**
 * Boost functionality for FediBoost.
 *
 * Handles scheduling and executing boosts on Mastodon accounts.
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
 * Boost class.
 *
 * Manages automatic boosting of posts on connected Mastodon accounts.
 *
 * @since 1.0.0
 */
class Boost {

	/**
	 * Cron action hook name.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const CRON_HOOK = 'fediboost_boost_post';

	/**
	 * Boost delay in seconds (30 seconds to allow ActivityPub federation).
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const BOOST_DELAY = 30;

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @var Boost|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Boost
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		// Hook into post publish event at priority 50 (after ActivityPub's priority 33).
		add_action( 'wp_after_insert_post', array( $this, 'on_post_publish' ), 50, 4 );

		// Register cron hook handler.
		add_action( self::CRON_HOOK, array( $this, 'execute_boost' ) );
	}

	/**
	 * Handle post publish event.
	 *
	 * @since 1.0.0
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before update, or null for new posts.
	 */
	public function on_post_publish( $post_id, $post, $update, $post_before ) {
		// Only process posts transitioning to 'publish' status.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Only process if this is a new publish (not an update to already published post).
		if ( null !== $post_before && 'publish' === $post_before->post_status ) {
			return;
		}

		// Check if post is eligible for boosting via ActivityPub.
		$activitypub = ActivityPub::get_instance();
		if ( ! $activitypub->is_post_eligible( $post ) ) {
			$this->log_info( 'Post not eligible for boost', array( 'post_id' => $post_id ) );
			return;
		}

		// Allow programmatic exclusion of individual posts.
		/**
		 * Filters whether a specific post should be boosted.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $should_boost Whether to boost the post. Default true.
		 * @param \WP_Post $post         The post object.
		 */
		if ( ! apply_filters( 'fediboost_should_boost_post', true, $post ) ) {
			$this->log_info( 'Post excluded by filter', array( 'post_id' => $post_id ) );
			return;
		}

		// Check if there are any connected accounts.
		$accounts = Accounts::get_instance();
		if ( ! $accounts->has_accounts() ) {
			$this->log_info( 'No connected accounts, skipping boost', array( 'post_id' => $post_id ) );
			return;
		}

		// Schedule the boost.
		$this->schedule_boost( $post_id );
	}

	/**
	 * Schedule a delayed boost for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID to boost.
	 * @return bool True if scheduled, false if already scheduled or failed.
	 */
	public function schedule_boost( $post_id ) {
		// Check if already scheduled (prevent duplicates).
		if ( wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			$this->log_info( 'Boost already scheduled', array( 'post_id' => $post_id ) );
			return false;
		}

		/**
		 * Filters the delay in seconds before a boost is executed.
		 *
		 * @since 1.0.0
		 *
		 * @param int $delay Delay in seconds. Default 30.
		 */
		$delay          = apply_filters( 'fediboost_boost_delay', self::BOOST_DELAY );
		$scheduled_time = time() + $delay;

		$result = wp_schedule_single_event( $scheduled_time, self::CRON_HOOK, array( $post_id ) );

		if ( false === $result ) {
			$this->log_error( 'Failed to schedule boost', array( 'post_id' => $post_id ) );
			return false;
		}

		$this->log_info(
			'Boost scheduled',
			array(
				'post_id'        => $post_id,
				'scheduled_time' => $scheduled_time,
			)
		);

		return true;
	}

	/**
	 * Execute boost for a post on all connected accounts.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID to boost.
	 */
	public function execute_boost( $post_id ) {
		// Verify this is a legitimate cron, admin, or WP-CLI context.
		if ( ! defined( 'DOING_CRON' ) && ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$this->log_info( 'Executing boost', array( 'post_id' => $post_id ) );

		// Verify post exists and is still published.
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			$this->log_error( 'Post not found or not published', array( 'post_id' => $post_id ) );
			return;
		}

		// Get ActivityPub URL for the post.
		$activitypub     = ActivityPub::get_instance();
		$activitypub_url = $activitypub->get_activitypub_url( $post );

		if ( false === $activitypub_url ) {
			$this->log_error( 'Could not get ActivityPub URL', array( 'post_id' => $post_id ) );
			return;
		}

		$this->log_info(
			'Got ActivityPub URL',
			array(
				'post_id' => $post_id,
				'url'     => $activitypub_url,
			)
		);

		// Get all connected accounts.
		$accounts_helper = Accounts::get_instance();
		$accounts        = $accounts_helper->get_all_accounts();

		if ( empty( $accounts ) ) {
			$this->log_info( 'No accounts to boost to', array( 'post_id' => $post_id ) );
			return;
		}

		$encryption = Encryption::get_instance();

		// Process each account.
		foreach ( $accounts as $account ) {
			$account_key = Accounts::generate_account_key( $account['instance_url'], $account['username'] );

			// Skip disconnected accounts.
			if ( Accounts::STATUS_CONNECTED !== $account['status'] ) {
				$this->log_info(
					'Skipping disconnected account',
					array(
						'instance' => $account['instance_url'],
						'username' => $account['username'],
					)
				);
				continue;
			}

			// Decrypt the access token.
			$access_token = $encryption->decrypt( $account['encrypted_token'] );

			if ( false === $access_token ) {
				$this->log_error(
					'Failed to decrypt token',
					array(
						'instance' => $account['instance_url'],
						'username' => $account['username'],
					)
				);
				$this->handle_boost_error( $account_key, 401, $account['instance_url'] );
				continue;
			}

			// Search for the status on this instance.
			$status_id = $this->search_for_status( $account['instance_url'], $activitypub_url, $access_token );

			if ( false === $status_id ) {
				$this->log_info(
					'Status not found on instance, skipping',
					array(
						'instance' => $account['instance_url'],
						'post_id'  => $post_id,
					)
				);
				continue;
			}

			// Reblog the status.
			$result = $this->reblog_status( $account['instance_url'], $status_id, $access_token );

			if ( is_wp_error( $result ) ) {
				$error_data  = $result->get_error_data();
				$status_code = isset( $error_data['status_code'] ) ? $error_data['status_code'] : 0;

				$this->log_error(
					'Reblog failed',
					array(
						'instance'    => $account['instance_url'],
						'status_id'   => $status_id,
						'error'       => $result->get_error_message(),
						'status_code' => $status_code,
					)
				);

				$this->handle_boost_error( $account_key, $status_code, $account['instance_url'] );
				continue;
			}

			$this->log_info(
				'Reblog successful',
				array(
					'instance'  => $account['instance_url'],
					'username'  => $account['username'],
					'status_id' => $status_id,
					'post_id'   => $post_id,
				)
			);
		}
	}

	/**
	 * Search for a status on a Mastodon instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url    The Mastodon instance URL.
	 * @param string $activitypub_url The ActivityPub URL to search for.
	 * @param string $access_token    The OAuth access token.
	 * @return string|false The local status ID or false if not found.
	 */
	public function search_for_status( $instance_url, $activitypub_url, $access_token ) {
		$request = $this->build_search_request( $instance_url, $activitypub_url );

		$response = wp_remote_get(
			$request['url'],
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error(
				'Search request failed',
				array(
					'instance' => $instance_url,
					'error'    => $response->get_error_message(),
				)
			);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$this->log_error(
				'Search returned non-200 status',
				array(
					'instance'    => $instance_url,
					'status_code' => $status_code,
				)
			);
			return false;
		}

		// Check if statuses were found.
		if ( empty( $data['statuses'] ) ) {
			return false;
		}

		$status_id = $data['statuses'][0]['id'];

		// Validate status ID format (numeric or alphanumeric only).
		if ( ! is_string( $status_id ) || ! preg_match( '/^[a-zA-Z0-9]+$/', $status_id ) ) {
			$this->log_error(
				'Invalid status ID format from remote instance',
				array(
					'instance'  => $instance_url,
					'status_id' => is_string( $status_id ) ? $status_id : gettype( $status_id ),
				)
			);
			return false;
		}

		return $status_id;
	}

	/**
	 * Build the search API request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url    The Mastodon instance URL.
	 * @param string $activitypub_url The ActivityPub URL to search for.
	 * @return array Request data with 'url' key.
	 */
	public function build_search_request( $instance_url, $activitypub_url ) {
		$params = array(
			'q'       => $activitypub_url,
			'resolve' => 'true',
			'type'    => 'statuses',
		);

		return array(
			'url' => $instance_url . '/api/v2/search?' . http_build_query( $params ),
		);
	}

	/**
	 * Reblog a status on a Mastodon instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $status_id    The local status ID to reblog.
	 * @param string $access_token The OAuth access token.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function reblog_status( $instance_url, $status_id, $access_token ) {
		$request = $this->build_reblog_request( $instance_url, $status_id, $access_token );

		$response = wp_remote_post(
			$request['url'],
			array(
				'headers' => $request['headers'],
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code ) {
			return true;
		}

		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );
		$error_message = isset( $data['error'] ) ? $data['error'] : 'Unknown error';

		return new \WP_Error(
			'reblog_failed',
			$error_message,
			array( 'status_code' => $status_code )
		);
	}

	/**
	 * Build the reblog API request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $status_id    The local status ID to reblog.
	 * @param string $access_token The OAuth access token.
	 * @return array Request data with 'url' and 'headers' keys.
	 */
	public function build_reblog_request( $instance_url, $status_id, $access_token ) {
		return array(
			'url'     => $instance_url . '/api/v1/statuses/' . $status_id . '/reblog',
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		);
	}

	/**
	 * Handle boost errors and update account status if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account_key  The stable account key.
	 * @param int    $status_code  The HTTP status code.
	 * @param string $instance_url The instance URL for logging.
	 */
	public function handle_boost_error( $account_key, $status_code, $instance_url ) {
		// On 401/403 (auth failure), mark account as disconnected.
		if ( 401 === $status_code || 403 === $status_code ) {
			$accounts = Accounts::get_instance();
			$accounts->update_account_status( $account_key, Accounts::STATUS_DISCONNECTED );

			$this->log_error(
				'Account marked as disconnected due to auth failure',
				array(
					'instance'    => $instance_url,
					'status_code' => $status_code,
				)
			);
		}
		// 404 is handled silently (status not federated to instance yet).
		// Network errors are logged but don't change account status.
	}

	/**
	 * Log an info message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The message.
	 * @param array  $context Additional context data.
	 */
	private function log_info( $message, $context = array() ) {
		$log_message = sprintf(
			'FediBoost Boost: %s - %s',
			$message,
			wp_json_encode( $context )
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}
	}

	/**
	 * Log an error message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The message.
	 * @param array  $context Additional context data.
	 */
	private function log_error( $message, $context = array() ) {
		$log_message = sprintf(
			'FediBoost Boost ERROR: %s - %s',
			$message,
			wp_json_encode( $context )
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}
	}
}
