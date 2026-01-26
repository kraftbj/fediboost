<?php
/**
 * Tests for the multi-account management interface.
 *
 * @package Auto_Tooter
 */

/**
 * Admin UI Tests
 */
class Test_Admin_UI extends WP_UnitTestCase {

	/**
	 * Accounts helper instance.
	 *
	 * @var Auto_Tooter_Accounts
	 */
	private $accounts;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->accounts = Auto_Tooter_Accounts::get_instance();
		// Clear accounts before each test.
		update_option( 'auto_tooter_accounts', array() );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		// Clean up accounts after each test.
		update_option( 'auto_tooter_accounts', array() );
		parent::tear_down();
	}

	/**
	 * Test that connected accounts table renders with correct columns.
	 */
	public function test_accounts_table_renders_with_correct_columns() {
		// Add a test account.
		$this->accounts->add_account(
			'https://mastodon.social',
			'testuser',
			'encrypted_token_here'
		);

		// Create admin user and set as current.
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Get admin instance and capture output.
		$admin = Auto_Tooter_Admin::get_instance();
		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Verify table structure exists.
		$this->assertStringContainsString( 'wp-list-table', $output );
		$this->assertStringContainsString( 'Instance URL', $output );
		$this->assertStringContainsString( 'Username', $output );
		$this->assertStringContainsString( 'Status', $output );
		$this->assertStringContainsString( 'Actions', $output );

		// Verify account data is rendered.
		$this->assertStringContainsString( 'mastodon.social', $output );
		$this->assertStringContainsString( '@testuser@mastodon.social', $output );
	}

	/**
	 * Test that disconnect action removes account data.
	 */
	public function test_disconnect_removes_account_data() {
		// Add two test accounts.
		$this->accounts->add_account(
			'https://mastodon.social',
			'user1',
			'token1'
		);
		$this->accounts->add_account(
			'https://fosstodon.org',
			'user2',
			'token2'
		);

		// Verify both accounts exist.
		$this->assertEquals( 2, $this->accounts->get_account_count() );

		// Remove the first account.
		$result = $this->accounts->remove_account( 0 );

		$this->assertTrue( $result );
		$this->assertEquals( 1, $this->accounts->get_account_count() );

		// Verify remaining account is the second one.
		$remaining = $this->accounts->get_account_by_index( 0 );
		$this->assertEquals( 'user2', $remaining['username'] );
	}

	/**
	 * Test that connect form contains required fields and initiates OAuth.
	 */
	public function test_connect_form_contains_required_fields() {
		// Create admin user.
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Get admin instance and capture output.
		$admin = Auto_Tooter_Admin::get_instance();
		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Verify form structure.
		$this->assertStringContainsString( 'action="', $output );
		$this->assertStringContainsString( 'auto_tooter_connect', $output );
		$this->assertStringContainsString( 'auto_tooter_nonce', $output );
		$this->assertStringContainsString( 'instance_url', $output );
		$this->assertStringContainsString( 'placeholder="mastodon.social"', $output );
		$this->assertStringContainsString( 'Connect Account', $output );
	}

	/**
	 * Test that admin notices display for connection success/failure.
	 */
	public function test_admin_notices_display_correctly() {
		// Create admin user.
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$admin = Auto_Tooter_Admin::get_instance();

		// Test success notice.
		$_GET['page']   = 'auto-tooter';
		$_GET['notice'] = 'connected';
		$_GET['error']  = '';

		ob_start();
		$admin->display_admin_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Account connected successfully', $output );

		// Test error notice.
		$_GET['notice'] = '';
		$_GET['error']  = 'invalid_url';

		ob_start();
		$admin->display_admin_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Failed to connect account', $output );

		// Test disconnect notice.
		$_GET['notice'] = 'disconnected';
		$_GET['error']  = '';

		ob_start();
		$admin->display_admin_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'Account disconnected', $output );

		// Clean up.
		unset( $_GET['page'], $_GET['notice'], $_GET['error'] );
	}

	/**
	 * Test that empty state displays when no accounts connected.
	 */
	public function test_empty_state_displays_when_no_accounts() {
		// Ensure no accounts exist.
		$this->accounts->clear_all_accounts();

		// Create admin user.
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Get admin instance and capture output.
		$admin = Auto_Tooter_Admin::get_instance();
		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Verify empty state message.
		$this->assertStringContainsString( 'auto-tooter-empty-state', $output );
		$this->assertStringContainsString( 'No Mastodon accounts connected yet', $output );
		$this->assertStringContainsString( 'Connect your first account', $output );

		// Verify table is NOT rendered.
		$this->assertStringNotContainsString( '<tbody>', $output );
	}

	/**
	 * Test that warning notice displays for disconnected accounts.
	 */
	public function test_reconnection_warning_displays_for_disconnected_accounts() {
		// Add an account and mark it as disconnected.
		$this->accounts->add_account(
			'https://mastodon.social',
			'testuser',
			'token'
		);
		$this->accounts->update_account_status( 0, Auto_Tooter_Accounts::STATUS_DISCONNECTED );

		// Create admin user.
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$admin = Auto_Tooter_Admin::get_instance();

		ob_start();
		$admin->display_reconnection_warning();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'requires reconnection', $output );
		$this->assertStringContainsString( 'settings page', $output );
	}
}
