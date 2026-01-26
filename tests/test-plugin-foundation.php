<?php
/**
 * Plugin Foundation Tests
 *
 * @package Auto_Tooter
 */

/**
 * Tests for plugin activation, deactivation, admin menu, and dependency checks.
 */
class Test_Plugin_Foundation extends WP_UnitTestCase {

	/**
	 * Test that activation hook initializes default options.
	 */
	public function test_activation_hook_initializes_options() {
		delete_option( 'auto_tooter_activated' );
		delete_option( 'auto_tooter_accounts' );

		auto_tooter_activate();

		$this->assertEquals( '1', get_option( 'auto_tooter_activated' ) );
		$this->assertIsArray( get_option( 'auto_tooter_accounts' ) );
	}

	/**
	 * Test that deactivation hook clears scheduled events.
	 */
	public function test_deactivation_hook_clears_scheduled_events() {
		wp_schedule_single_event( time() + 3600, 'auto_tooter_boost_post', array( 123 ) );
		wp_schedule_single_event( time() + 3600, 'auto_tooter_boost_post', array( 456 ) );

		$this->assertNotFalse( wp_next_scheduled( 'auto_tooter_boost_post', array( 123 ) ) );

		auto_tooter_deactivate();

		$this->assertFalse( wp_next_scheduled( 'auto_tooter_boost_post', array( 123 ) ) );
		$this->assertFalse( wp_next_scheduled( 'auto_tooter_boost_post', array( 456 ) ) );
	}

	/**
	 * Test that admin menu is registered under Settings for users with manage_options.
	 */
	public function test_admin_menu_registered_for_authorized_users() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		global $submenu;
		$submenu = array();

		do_action( 'admin_menu' );

		$this->assertArrayHasKey( 'options-general.php', $submenu );

		$menu_found = false;
		foreach ( $submenu['options-general.php'] as $menu_item ) {
			if ( in_array( 'auto-tooter', $menu_item, true ) ) {
				$menu_found = true;
				break;
			}
		}
		$this->assertTrue( $menu_found, 'Auto Tooter menu should appear under Settings' );
	}

	/**
	 * Test that settings page requires manage_options capability.
	 */
	public function test_settings_page_requires_manage_options_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( current_user_can( 'manage_options' ) );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * Test that admin notice displays when ActivityPub plugin is missing.
	 */
	public function test_activitypub_dependency_notice_when_missing() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		set_current_screen( 'dashboard' );

		ob_start();
		auto_tooter_check_activitypub_dependency();
		auto_tooter_activitypub_missing_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ActivityPub', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * Test that plugin constants are defined correctly.
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'AUTO_TOOTER_VERSION' ) );
		$this->assertTrue( defined( 'AUTO_TOOTER_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'AUTO_TOOTER_PLUGIN_URL' ) );
		$this->assertTrue( defined( 'AUTO_TOOTER_PLUGIN_FILE' ) );
	}
}
