<?php
/**
 * Admin class for FediBoost.
 *
 * Handles admin menu, settings page, and account management UI.
 *
 * @package FediBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FediBoost_Admin class.
 *
 * Handles all admin functionality.
 */
class FediBoost_Admin {

	/**
	 * Single instance of the class.
	 *
	 * @var FediBoost_Admin|null
	 */
	private static $instance = null;

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook;

	/**
	 * Get singleton instance.
	 *
	 * @return FediBoost_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'display_reconnection_warning' ) );

		// Register OAuth handlers.
		add_action( 'admin_post_fediboost_connect', array( $this, 'handle_connect_request' ) );
		add_action( 'admin_post_fediboost_oauth_callback', array( $this, 'handle_oauth_callback' ) );

		// Handle disconnect action.
		add_action( 'admin_init', array( $this, 'handle_disconnect_action' ) );
	}

	/**
	 * Register admin menu under Settings.
	 */
	public function register_admin_menu() {
		$this->page_hook = add_options_page(
			__( 'FediBoost', 'fediboost' ),
			__( 'FediBoost', 'fediboost' ),
			'manage_options',
			'fediboost',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings using Settings API.
	 */
	public function register_settings() {
		// Register settings group.
		register_setting(
			'fediboost_settings',
			'fediboost_accounts',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_accounts' ),
				'default'           => array(),
			)
		);

		// Add main settings section.
		add_settings_section(
			'fediboost_accounts_section',
			__( 'Connected Mastodon Accounts', 'fediboost' ),
			array( $this, 'render_accounts_section' ),
			'fediboost'
		);
	}

	/**
	 * Sanitize accounts option.
	 *
	 * @param mixed $accounts The accounts value to sanitize.
	 * @return array Sanitized accounts array.
	 */
	public function sanitize_accounts( $accounts ) {
		if ( ! is_array( $accounts ) ) {
			return array();
		}
		return $accounts;
	}

	/**
	 * Render accounts section description.
	 */
	public function render_accounts_section() {
		echo '<p>' . esc_html__( 'Manage your connected Mastodon accounts. When you publish a post, it will automatically be boosted on all connected accounts.', 'fediboost' ) . '</p>';
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_styles( $hook_suffix ) {
		if ( 'settings_page_fediboost' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'fediboost-admin',
			FEDIBOOST_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			FEDIBOOST_VERSION
		);

		wp_enqueue_script( 'fediboost-admin', '', array(), FEDIBOOST_VERSION, true );
		wp_add_inline_script(
			'fediboost-admin',
			'function fediboostConfirmDisconnect() { return confirm( "' . esc_js( __( 'Are you sure you want to disconnect this account?', 'fediboost' ) ) . '" ); }'
		);
	}

	/**
	 * Display admin notices based on query parameters.
	 */
	public function display_admin_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'fediboost' !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( 'connected' === $notice ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Account connected successfully.', 'fediboost' ); ?></p>
			</div>
			<?php
		}

		if ( 'disconnected' === $notice ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Account disconnected.', 'fediboost' ); ?></p>
			</div>
			<?php
		}

		if ( $error ) {
			$error_messages = array(
				'invalid_nonce'        => __( 'Security check failed. Please try again.', 'fediboost' ),
				'permission_denied'    => __( 'You do not have permission to perform this action.', 'fediboost' ),
				'invalid_url'          => __( 'Please enter a valid Mastodon instance URL.', 'fediboost' ),
				'app_registration'     => __( 'Failed to register application with Mastodon instance. Please verify the URL and try again.', 'fediboost' ),
				'authorization_denied' => __( 'Authorization was denied. Please try connecting again.', 'fediboost' ),
				'invalid_state'        => __( 'Invalid or expired authorization request. Please try connecting again.', 'fediboost' ),
				'token_exchange'       => __( 'Failed to complete authorization. Please try connecting again.', 'fediboost' ),
				'verification'         => __( 'Failed to verify account credentials. Please try connecting again.', 'fediboost' ),
				'storage'              => __( 'Failed to save account credentials. Please try again.', 'fediboost' ),
			);

			$message = isset( $error_messages[ $error ] ) ? $error_messages[ $error ] : __( 'An error occurred. Please try again.', 'fediboost' );
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					/* translators: %s: Error message describing what went wrong */
					echo esc_html( sprintf( __( 'Failed to connect account: %s', 'fediboost' ), $message ) );
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Display warning notice for accounts that need reconnection.
	 */
	public function display_reconnection_warning() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$accounts_helper = FediBoost_Accounts::get_instance();
		$disconnected    = $accounts_helper->get_disconnected_accounts();

		if ( empty( $disconnected ) ) {
			return;
		}

		$count         = count( $disconnected );
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=fediboost' ) ) . '">' . esc_html__( 'settings page', 'fediboost' ) . '</a>';
		/* translators: %1$d: number of accounts, %2$s: settings page link */
		$message = _n(
			'FediBoost: %1$d account requires reconnection. Please visit the %2$s to reconnect.',
			'FediBoost: %1$d accounts require reconnection. Please visit the %2$s to reconnect.',
			$count,
			'fediboost'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					esc_html( $message ),
					esc_html( $count ),
					$settings_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the connect request form submission.
	 *
	 * Initiates the OAuth flow by registering the app and redirecting to authorization.
	 */
	public function handle_connect_request() {
		$security = FediBoost_Security::get_instance();

		// Verify nonce and capability - nonce is verified via verify_connect_nonce() below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST['fediboost_nonce'] ) ? wp_unslash( $_POST['fediboost_nonce'] ) : '';

		if ( ! $security->verify_connect_nonce( $nonce ) ) {
			$this->redirect_with_error( 'invalid_nonce' );
			return;
		}

		if ( ! $security->user_can_manage() ) {
			$this->redirect_with_error( 'permission_denied' );
			return;
		}

		// Sanitize and validate instance URL - nonce verified above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_url      = isset( $_POST['instance_url'] ) ? wp_unslash( $_POST['instance_url'] ) : '';
		$instance_url = $security->sanitize_instance_url( $raw_url );

		if ( false === $instance_url ) {
			$this->redirect_with_error( 'invalid_url' );
			return;
		}

		// Register OAuth app with the instance.
		$oauth       = FediBoost_OAuth::get_instance();
		$credentials = $oauth->register_app( $instance_url );

		if ( is_wp_error( $credentials ) ) {
			$this->log_error(
				'App registration failed',
				array(
					'instance' => $instance_url,
					'error'    => $credentials->get_error_message(),
				)
			);
			$this->redirect_with_error( 'app_registration' );
			return;
		}

		// Generate authorization URL and redirect.
		// External redirect to Mastodon instance is intentional for OAuth flow.
		$auth_url = $oauth->generate_authorization_url( $instance_url, $credentials['client_id'] );

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External OAuth redirect is intentional.
		wp_redirect( $auth_url );
		exit;
	}

	/**
	 * Handle the OAuth callback from Mastodon.
	 */
	public function handle_oauth_callback() {
		$security = FediBoost_Security::get_instance();

		// Check user capability.
		if ( ! $security->user_can_manage() ) {
			$this->redirect_with_error( 'permission_denied' );
			return;
		}

		// Check for authorization error.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_value = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error_desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$this->log_error(
				'Authorization denied by user',
				array(
					'error'       => $error_value,
					'description' => $error_desc,
				)
			);
			$this->redirect_with_error( 'authorization_denied' );
			return;
		}

		// Get authorization code and state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( empty( $code ) || empty( $state ) ) {
			$this->redirect_with_error( 'invalid_state' );
			return;
		}

		// Verify state and get instance URL.
		$oauth      = FediBoost_OAuth::get_instance();
		$state_data = $oauth->verify_state( $state );

		if ( false === $state_data ) {
			$this->redirect_with_error( 'invalid_state' );
			return;
		}

		$instance_url = $state_data['instance_url'];

		// Get cached app credentials.
		$credentials = $oauth->get_cached_app_credentials( $instance_url );

		if ( false === $credentials ) {
			$this->log_error(
				'Missing app credentials during callback',
				array( 'instance' => $instance_url )
			);
			$this->redirect_with_error( 'app_registration' );
			return;
		}

		// Exchange code for token.
		$token_data = $oauth->exchange_code_for_token(
			$instance_url,
			$code,
			$credentials['client_id'],
			$credentials['client_secret']
		);

		if ( is_wp_error( $token_data ) ) {
			$this->log_error(
				'Token exchange failed',
				array(
					'instance' => $instance_url,
					'error'    => $token_data->get_error_message(),
				)
			);
			$this->redirect_with_error( 'token_exchange' );
			return;
		}

		// Verify token and get account info.
		$account_info = $oauth->verify_credentials( $instance_url, $token_data['access_token'] );

		if ( is_wp_error( $account_info ) ) {
			$this->log_error(
				'Credential verification failed',
				array(
					'instance' => $instance_url,
					'error'    => $account_info->get_error_message(),
				)
			);
			$this->redirect_with_error( 'verification' );
			return;
		}

		// Store the connected account.
		$stored = $oauth->store_connected_account(
			$instance_url,
			$account_info['username'],
			$token_data['access_token']
		);

		if ( ! $stored ) {
			$this->redirect_with_error( 'storage' );
			return;
		}

		// Redirect with success notice.
		$redirect_url = add_query_arg(
			array(
				'page'   => 'fediboost',
				'notice' => 'connected',
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle the disconnect action.
	 */
	public function handle_disconnect_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'fediboost' !== $page || 'disconnect' !== $action ) {
			return;
		}

		$security = FediBoost_Security::get_instance();

		// Get account index - nonce verified below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$account_index = isset( $_GET['account'] ) ? intval( $_GET['account'] ) : -1;

		if ( $account_index < 0 ) {
			$this->redirect_with_error( 'invalid_nonce' );
			return;
		}

		// Verify nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';

		if ( ! $security->verify_disconnect_nonce( $nonce, $account_index ) ) {
			$this->redirect_with_error( 'invalid_nonce' );
			return;
		}

		// Verify capability.
		if ( ! $security->user_can_manage() ) {
			$this->redirect_with_error( 'permission_denied' );
			return;
		}

		// Get account data before removal for cache clearing.
		$accounts_helper = FediBoost_Accounts::get_instance();
		$account         = $accounts_helper->get_account_by_index( $account_index );

		if ( $account ) {
			// Clear cached data for the account.
			$accounts_helper->clear_account_cache( $account );
		}

		// Remove the account.
		$accounts_helper->remove_account( $account_index );

		// Redirect with success notice.
		$redirect_url = add_query_arg(
			array(
				'page'   => 'fediboost',
				'notice' => 'disconnected',
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect to settings page with error parameter.
	 *
	 * @param string $error_code The error code.
	 */
	private function redirect_with_error( $error_code ) {
		$redirect_url = add_query_arg(
			array(
				'page'  => 'fediboost',
				'error' => $error_code,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Log an error for debugging.
	 *
	 * @param string $message The error message.
	 * @param array  $context Additional context data.
	 */
	private function log_error( $message, $context = array() ) {
		$log_message = sprintf(
			'FediBoost Admin: %s - %s',
			$message,
			wp_json_encode( $context )
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'fediboost' ) );
		}

		$accounts_helper = FediBoost_Accounts::get_instance();
		$accounts        = $accounts_helper->get_all_accounts();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php $this->render_dependency_status(); ?>

			<h2><?php esc_html_e( 'Connected Accounts', 'fediboost' ); ?></h2>

			<?php if ( ! $accounts_helper->has_accounts() ) : ?>
				<?php $this->render_empty_state(); ?>
			<?php else : ?>
				<?php $this->render_accounts_table( $accounts ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connect New Account', 'fediboost' ); ?></h2>
			<?php $this->render_connect_form(); ?>
		</div>
		<?php
	}

	/**
	 * Render ActivityPub dependency status.
	 */
	private function render_dependency_status() {
		if ( ! fediboost_is_activitypub_active() ) {
			?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: ActivityPub plugin link */
						esc_html__( 'The %s plugin is required for FediBoost to function. Boost functionality is currently disabled.', 'fediboost' ),
						'<a href="https://wordpress.org/plugins/activitypub/" target="_blank">ActivityPub</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render empty state when no accounts are connected.
	 */
	private function render_empty_state() {
		?>
		<div class="fediboost-empty-state">
			<p><?php esc_html_e( 'No Mastodon accounts connected yet.', 'fediboost' ); ?></p>
			<p><?php esc_html_e( 'Connect your first account below to start automatically boosting your posts.', 'fediboost' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render connected accounts table.
	 *
	 * @param array $accounts Array of connected accounts.
	 */
	private function render_accounts_table( $accounts ) {
		$accounts_helper = FediBoost_Accounts::get_instance();
		?>
		<table class="wp-list-table widefat fixed striped fediboost-accounts-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Instance URL', 'fediboost' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Username', 'fediboost' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'fediboost' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'fediboost' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $accounts as $index => $account ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $account['instance_url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $account['instance_url'] ); ?>
							</a>
						</td>
						<td>
							<?php echo esc_html( $accounts_helper->format_username_display( $account ) ); ?>
						</td>
						<td>
							<?php if ( FediBoost_Accounts::STATUS_CONNECTED === $account['status'] ) : ?>
								<span class="fediboost-status fediboost-status-connected">
									<?php esc_html_e( 'Connected', 'fediboost' ); ?>
								</span>
							<?php else : ?>
								<span class="fediboost-status fediboost-status-disconnected">
									<?php esc_html_e( 'Disconnected', 'fediboost' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$disconnect_url = wp_nonce_url(
								add_query_arg(
									array(
										'page'    => 'fediboost',
										'action'  => 'disconnect',
										'account' => $index,
									),
									admin_url( 'options-general.php' )
								),
								'fediboost_disconnect_' . $index
							);
							?>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary" onclick="return fediboostConfirmDisconnect();" aria-label="<?php esc_attr_e( 'Disconnect this Mastodon account', 'fediboost' ); ?>">
								<?php esc_html_e( 'Disconnect', 'fediboost' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the connect new account form.
	 */
	private function render_connect_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fediboost_connect">
			<?php wp_nonce_field( 'fediboost_connect', 'fediboost_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="instance_url"><?php esc_html_e( 'Mastodon Instance URL', 'fediboost' ); ?></label>
					</th>
					<td>
						<input type="text" id="instance_url" name="instance_url" class="regular-text" placeholder="mastodon.social" required>
						<p class="description">
							<?php esc_html_e( 'Enter your Mastodon instance domain (e.g., mastodon.social, fosstodon.org).', 'fediboost' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Connect Account', 'fediboost' ), 'primary', 'submit', true ); ?>
		</form>
		<?php
	}
}
