<?php
/**
 * Base test case for FediBoost tests that run against SQLite.
 *
 * @package kraftbj/fediboost
 */

/**
 * FediBoost_SQLite_TestCase class.
 *
 * Wraps each test in a database transaction that is rolled back afterwards,
 * so data written to SQLite does not leak between tests. Replaces the
 * WorDBless teardown, which clears its in-memory stores and would register
 * in-memory option handling on top of the real database.
 *
 * @since 1.1.0
 */
abstract class FediBoost_SQLite_TestCase extends FediBoost_TestCase {

	/**
	 * Start a transaction before each test.
	 *
	 * @before
	 */
	public function start_transaction() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Roll back the transaction and restore hooks after each test.
	 *
	 * Overrides WorDBless\BaseTestCase::tear_down_wordbless().
	 *
	 * @after
	 */
	public function tear_down_wordbless() {
		global $wpdb;

		$this->_restore_hooks();
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
}
