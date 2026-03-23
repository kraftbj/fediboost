<?php
/**
 * Base test case for FediBoost tests.
 *
 * Extends WorDBless\BaseTestCase which provides clean WordPress state
 * between tests (hook backup/restore, clearing options/posts/meta/users).
 *
 * @package kraftbj/fediboost
 */

/**
 * FediBoost_TestCase class.
 *
 * @since 1.1.0
 */
abstract class FediBoost_TestCase extends \WorDBless\BaseTestCase {

	/**
	 * Counter for generating unique user logins.
	 *
	 * @var int
	 */
	private static $user_counter = 0;

	/**
	 * Counter for generating unique post titles.
	 *
	 * @var int
	 */
	private static $post_counter = 0;

	/**
	 * Create a test user.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Optional. User arguments. Default empty array.
	 * @return int User ID.
	 */
	protected function create_user( $args = array() ) {
		++self::$user_counter;
		$defaults = array(
			'user_login' => 'test_user_' . self::$user_counter,
			'user_pass'  => 'password',
			'user_email' => 'test_user_' . self::$user_counter . '@example.org',
			'role'       => 'subscriber',
		);
		return wp_insert_user( array_merge( $defaults, $args ) );
	}

	/**
	 * Create a test post.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Optional. Post arguments. Default empty array.
	 * @return int Post ID.
	 */
	protected function create_post( $args = array() ) {
		++self::$post_counter;
		$defaults = array(
			'post_title'  => 'Test Post ' . self::$post_counter,
			'post_status' => 'publish',
		);
		return wp_insert_post( array_merge( $defaults, $args ) );
	}
}
