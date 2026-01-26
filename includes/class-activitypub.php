<?php
/**
 * ActivityPub integration wrapper for FediBoost.
 *
 * Provides safe wrappers around ActivityPub plugin functions.
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
 * ActivityPub class.
 *
 * Wraps ActivityPub plugin functions with availability checks and fallbacks.
 *
 * @since 1.0.0
 */
class ActivityPub {

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @var ActivityPub|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return ActivityPub
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
		// Protected constructor for singleton pattern.
	}

	/**
	 * Check if ActivityPub plugin is active and available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if ActivityPub is active.
	 */
	public function is_plugin_active() {
		return fediboost_is_activitypub_active();
	}

	/**
	 * Check if a post is disabled from ActivityPub federation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post The post to check.
	 * @return bool True if post is disabled, false if enabled for federation.
	 */
	public function is_post_disabled( $post ) {
		if ( ! $this->is_plugin_active() ) {
			return true; // Consider disabled when plugin unavailable.
		}

		if ( ! function_exists( '\Activitypub\is_post_disabled' ) ) {
			return true;
		}

		return \Activitypub\is_post_disabled( $post );
	}

	/**
	 * Get the content visibility for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return string|false Visibility constant or false if unavailable.
	 */
	public function get_content_visibility( $post_id ) {
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		if ( ! function_exists( '\Activitypub\get_content_visibility' ) ) {
			return false;
		}

		return \Activitypub\get_content_visibility( $post_id );
	}

	/**
	 * Check if post visibility allows federation (public or quiet_public).
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return bool True if visibility allows federation.
	 */
	public function is_visibility_public( $post_id ) {
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		$visibility = $this->get_content_visibility( $post_id );

		if ( false === $visibility ) {
			return false;
		}

		// Check for public visibility constants.
		// ActivityPub plugin uses ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC and ACTIVITYPUB_CONTENT_VISIBILITY_QUIET_PUBLIC.
		$public_visibilities = array( 'public', 'quiet_public' );

		// Handle both string values and potential constant names.
		if ( in_array( $visibility, $public_visibilities, true ) ) {
			return true;
		}

		// Check defined constants if they exist.
		if ( defined( 'ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC' ) && ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC === $visibility ) {
			return true;
		}

		if ( defined( 'ACTIVITYPUB_CONTENT_VISIBILITY_QUIET_PUBLIC' ) && ACTIVITYPUB_CONTENT_VISIBILITY_QUIET_PUBLIC === $visibility ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the ActivityPub URL for a post using the transformer factory.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post The post object.
	 * @return string|false The ActivityPub URL or false if unavailable.
	 */
	public function get_activitypub_url( $post ) {
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		// Check if post is disabled from federation first.
		if ( $this->is_post_disabled( $post ) ) {
			return false;
		}

		// Check visibility.
		if ( ! $this->is_visibility_public( $post->ID ) ) {
			return false;
		}

		// Use the transformer factory to get the ActivityPub object.
		if ( ! class_exists( '\Activitypub\Transformer\Factory' ) ) {
			$this->log_error( 'ActivityPub Transformer Factory class not found' );
			return false;
		}

		try {
			$transformer = \Activitypub\Transformer\Factory::get_transformer( $post );

			if ( ! $transformer || is_wp_error( $transformer ) ) {
				$this->log_error(
					'Failed to transform post',
					array(
						'post_id' => $post->ID,
						'error'   => is_wp_error( $transformer ) ? $transformer->get_error_message() : 'Unknown error',
					)
				);
				return false;
			}

			$activity_object = $transformer->to_object();

			if ( ! $activity_object || is_wp_error( $activity_object ) ) {
				$this->log_error(
					'Failed to get ActivityPub object',
					array(
						'post_id' => $post->ID,
					)
				);
				return false;
			}

			$url = $activity_object->get_id();

			if ( empty( $url ) ) {
				return false;
			}

			return $url;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Exception getting ActivityPub URL',
				array(
					'post_id' => $post->ID,
					'message' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Check if a post is eligible for boosting.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post The post object.
	 * @return bool True if post should be boosted.
	 */
	public function is_post_eligible( $post ) {
		// ActivityPub plugin must be active.
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		// Post must not be disabled from federation.
		if ( $this->is_post_disabled( $post ) ) {
			return false;
		}

		// Visibility must be public or quiet_public.
		if ( ! $this->is_visibility_public( $post->ID ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Log an error for debugging.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The error message.
	 * @param array  $context Additional context data.
	 */
	private function log_error( $message, $context = array() ) {
		$log_message = sprintf(
			'FediBoost ActivityPub: %s - %s',
			$message,
			wp_json_encode( $context )
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}
	}
}
