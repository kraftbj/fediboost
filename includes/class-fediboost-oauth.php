<?php
/**
 * OAuth class for FediBoost.
 *
 * Handles Mastodon OAuth 2.0 authentication flow.
 *
 * @package kraftbj/fediboost
 */

namespace FediBoost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth class.
 *
 * Manages OAuth app registration, authorization, and token exchange with Mastodon instances.
 */
class OAuth {

	/**
	 * OAuth scopes required for the plugin.
	 *
	 * @var string
	 */
	const SCOPES = 'read write:statuses';

	/**
	 * Client name for OAuth app registration.
	 *
	 * @var string
	 */
	const CLIENT_NAME = 'FediBoost for WordPress';

	/**
	 * Transient prefix for OAuth state.
	 *
	 * @var string
	 */
	const STATE_TRANSIENT_PREFIX = 'fediboost_oauth_state_';

	/**
	 * Single instance of the class.
	 *
	 * @var OAuth|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return OAuth
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
	protected function __construct() {
		// Protected constructor for singleton pattern.
	}

	/**
	 * Get the OAuth callback URL.
	 *
	 * @return string The callback URL.
	 */
	public function get_callback_url() {
		return admin_url( 'admin-post.php?action=fediboost_oauth_callback' );
	}

	/**
	 * Send an HTTP request pinned to the IP validated by Security.
	 *
	 * When a pinned IP is available the hostname in the URL is replaced with
	 * the resolved IP address and the original hostname is sent via the Host
	 * header so that TLS SNI and server routing continue to work.
	 *
	 * @param string $method       HTTP method: 'GET' or 'POST'.
	 * @param string $instance_url The base instance URL (scheme + host).
	 * @param string $endpoint_path The path appended to the instance URL.
	 * @param array  $args         Arguments passed to wp_remote_get/post.
	 * @return array|\WP_Error Response array or WP_Error on failure.
	 */
	private function make_pinned_request( $method, $instance_url, $endpoint_path, $args ) {
		$security  = Security::get_instance();
		$pinned_ip = $security->get_pinned_ip();
		$endpoint  = $instance_url . $endpoint_path;

		if ( null !== $pinned_ip ) {
			$host = wp_parse_url( $instance_url, PHP_URL_HOST );
			$endpoint = str_replace( '://' . $host, '://' . $pinned_ip, $endpoint );
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Host'] = $host;
		}

		if ( 'POST' === $method ) {
			return wp_remote_post( $endpoint, $args );
		}
		return wp_remote_get( $endpoint, $args );
	}

	/**
	 * Register an OAuth application with a Mastodon instance.
	 *
	 * @param string $instance_url The sanitized Mastodon instance URL.
	 * @return array|\WP_Error App credentials on success, WP_Error on failure.
	 */
	public function register_app( $instance_url ) {
		// Check for cached app credentials first.
		$cached = $this->get_cached_app_credentials( $instance_url );
		if ( false !== $cached ) {
			return $cached;
		}

		// Validate URL resolves to external host.
		$security = Security::get_instance();
		if ( ! $security->is_external_url( $instance_url ) ) {
			return new \WP_Error(
				'invalid_host',
				__( 'The instance URL could not be validated.', 'fediboost' )
			);
		}

		// Rate limit: max 5 registration attempts per hour per instance.
		$rate_limit_key = 'fediboost_oauth_rate_' . md5( $instance_url );
		$attempts       = get_transient( $rate_limit_key );
		if ( false !== $attempts && $attempts >= 5 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Too many registration attempts. Please try again later.', 'fediboost' )
			);
		}

		// Increment attempt counter.
		$attempts = false === $attempts ? 1 : $attempts + 1;
		set_transient( $rate_limit_key, $attempts, HOUR_IN_SECONDS );

		$body = array(
			'client_name'   => self::CLIENT_NAME,
			'redirect_uris' => $this->get_callback_url(),
			'scopes'        => self::SCOPES,
			'website'       => home_url(),
		);

		$response = $this->make_pinned_request(
			'POST',
			$instance_url,
			'/api/v1/apps',
			array(
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error(
				'App registration failed',
				array(
					'instance' => $instance_url,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$body_data   = json_decode( $body_raw, true );

		if ( 200 !== $status_code ) {
			$error_message = isset( $body_data['error'] ) ? $body_data['error'] : 'Unknown error';
			$this->log_error(
				'App registration failed with status ' . $status_code,
				array(
					'instance' => $instance_url,
					'error'    => $error_message,
				)
			);
			return new \WP_Error(
				'app_registration_failed',
				sprintf(
					/* translators: %s: Error message from Mastodon instance */
					__( 'Failed to register OAuth application: %s', 'fediboost' ),
					$error_message
				)
			);
		}

		if ( ! isset( $body_data['client_id'] ) || ! isset( $body_data['client_secret'] ) ) {
			$this->log_error(
				'Invalid app registration response',
				array(
					'instance' => $instance_url,
					'response' => $body_raw,
				)
			);
			return new \WP_Error(
				'invalid_response',
				__( 'Invalid response from Mastodon instance during app registration.', 'fediboost' )
			);
		}

		$credentials = array(
			'client_id'     => $body_data['client_id'],
			'client_secret' => $body_data['client_secret'],
			'created_at'    => time(),
		);

		// Cache the app credentials.
		$this->cache_app_credentials( $instance_url, $credentials );

		return $credentials;
	}

	/**
	 * Generate an authorization URL for OAuth flow.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $client_id    The OAuth client ID.
	 * @return string The full authorization URL.
	 */
	public function generate_authorization_url( $instance_url, $client_id ) {
		// Generate and store state parameter for CSRF protection.
		$state = $this->generate_state( $instance_url );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_callback_url(),
			'scope'         => self::SCOPES,
			'state'         => $state,
		);

		return $instance_url . '/oauth/authorize?' . http_build_query( $params );
	}

	/**
	 * Generate and store a state parameter for CSRF protection.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return string The generated state token.
	 */
	private function generate_state( $instance_url ) {
		$state = wp_generate_password( 32, false );

		$state_data = array(
			'instance_url' => $instance_url,
			'user_id'      => get_current_user_id(),
			'created_at'   => time(),
		);

		set_transient( self::STATE_TRANSIENT_PREFIX . $state, $state_data, HOUR_IN_SECONDS );

		return $state;
	}

	/**
	 * Verify and retrieve state data.
	 *
	 * @param string $state The state token to verify.
	 * @return array|false State data on success, false if invalid or expired.
	 */
	public function verify_state( $state ) {
		if ( empty( $state ) ) {
			return false;
		}

		$transient_key = self::STATE_TRANSIENT_PREFIX . $state;
		$state_data    = get_transient( $transient_key );

		if ( false === $state_data ) {
			return false;
		}

		// Verify the state belongs to the current user.
		if ( isset( $state_data['user_id'] ) && get_current_user_id() !== $state_data['user_id'] ) {
			return false;
		}

		// Delete the transient to prevent replay.
		delete_transient( $transient_key );

		return $state_data;
	}

	/**
	 * Exchange an authorization code for an access token.
	 *
	 * @param string $instance_url  The Mastodon instance URL.
	 * @param string $code          The authorization code.
	 * @param string $client_id     The OAuth client ID.
	 * @param string $client_secret The OAuth client secret.
	 * @return array|\WP_Error Token data on success, WP_Error on failure.
	 */
	public function exchange_code_for_token( $instance_url, $code, $client_id, $client_secret ) {
		// Validate URL resolves to external host.
		$security = Security::get_instance();
		if ( ! $security->is_external_url( $instance_url ) ) {
			return new \WP_Error(
				'invalid_host',
				__( 'The instance URL could not be validated.', 'fediboost' )
			);
		}

		$body = array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'redirect_uri'  => $this->get_callback_url(),
			'code'          => $code,
			'scope'         => self::SCOPES,
		);

		$response = $this->make_pinned_request(
			'POST',
			$instance_url,
			'/oauth/token',
			array(
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error(
				'Token exchange failed',
				array(
					'instance' => $instance_url,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$body_data   = json_decode( $body_raw, true );

		if ( 200 !== $status_code ) {
			$error_message = isset( $body_data['error'] ) ? $body_data['error'] : 'Unknown error';
			$error_desc    = isset( $body_data['error_description'] ) ? $body_data['error_description'] : '';

			$this->log_error(
				'Token exchange failed with status ' . $status_code,
				array(
					'instance'    => $instance_url,
					'error'       => $error_message,
					'description' => $error_desc,
				)
			);

			return new \WP_Error(
				'token_exchange_failed',
				sprintf(
					/* translators: %s: Error message from Mastodon instance */
					__( 'Failed to exchange authorization code for token: %s', 'fediboost' ),
					$error_desc ? $error_desc : $error_message
				)
			);
		}

		if ( ! isset( $body_data['access_token'] ) ) {
			$this->log_error(
				'Invalid token exchange response',
				array(
					'instance' => $instance_url,
					'response' => $body_raw,
				)
			);
			return new \WP_Error(
				'invalid_response',
				__( 'Invalid response from Mastodon instance during token exchange.', 'fediboost' )
			);
		}

		return array(
			'access_token' => $body_data['access_token'],
			'token_type'   => isset( $body_data['token_type'] ) ? $body_data['token_type'] : 'Bearer',
			'scope'        => isset( $body_data['scope'] ) ? $body_data['scope'] : self::SCOPES,
			'created_at'   => isset( $body_data['created_at'] ) ? $body_data['created_at'] : time(),
		);
	}

	/**
	 * Verify a token by fetching account credentials.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $access_token The OAuth access token.
	 * @return array|\WP_Error Account data on success, WP_Error on failure.
	 */
	public function verify_credentials( $instance_url, $access_token ) {
		// Validate URL resolves to external host.
		$security = Security::get_instance();
		if ( ! $security->is_external_url( $instance_url ) ) {
			return new \WP_Error(
				'invalid_host',
				__( 'The instance URL could not be validated.', 'fediboost' )
			);
		}

		$response = $this->make_pinned_request(
			'GET',
			$instance_url,
			'/api/v1/accounts/verify_credentials',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error(
				'Credential verification failed',
				array(
					'instance' => $instance_url,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$body_data   = json_decode( $body_raw, true );

		if ( 401 === $status_code || 403 === $status_code ) {
			return new \WP_Error(
				'unauthorized',
				__( 'Access token is invalid or has been revoked.', 'fediboost' )
			);
		}

		if ( 200 !== $status_code ) {
			$error_message = isset( $body_data['error'] ) ? $body_data['error'] : 'Unknown error';
			$this->log_error(
				'Credential verification failed with status ' . $status_code,
				array(
					'instance' => $instance_url,
					'error'    => $error_message,
				)
			);
			return new \WP_Error(
				'verification_failed',
				sprintf(
					/* translators: %s: Error message from Mastodon instance */
					__( 'Failed to verify account credentials: %s', 'fediboost' ),
					$error_message
				)
			);
		}

		if ( ! isset( $body_data['username'] ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'Invalid response from Mastodon instance during credential verification.', 'fediboost' )
			);
		}

		return array(
			'id'           => isset( $body_data['id'] ) ? $body_data['id'] : '',
			'username'     => $body_data['username'],
			'display_name' => isset( $body_data['display_name'] ) ? $body_data['display_name'] : $body_data['username'],
			'url'          => isset( $body_data['url'] ) ? $body_data['url'] : '',
		);
	}

	/**
	 * Revoke an OAuth token with a Mastodon instance.
	 *
	 * @param string $instance_url  The Mastodon instance URL.
	 * @param string $token         The access token to revoke.
	 * @param string $client_id     The OAuth client ID.
	 * @param string $client_secret The OAuth client secret.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function revoke_token( $instance_url, $token, $client_id, $client_secret ) {
		$security = Security::get_instance();
		if ( ! $security->is_external_url( $instance_url ) ) {
			return new \WP_Error(
				'invalid_host',
				__( 'The instance URL could not be validated.', 'fediboost' )
			);
		}

		$response = $this->make_pinned_request(
			'POST',
			$instance_url,
			'/oauth/revoke',
			array(
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'token'         => $token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error(
				'Token revocation failed',
				array(
					'instance' => $instance_url,
					'error'    => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'revocation_failed',
				sprintf(
					/* translators: %d: HTTP status code returned by the instance */
					__( 'Token revocation returned status %d.', 'fediboost' ),
					$status_code
				)
			);
		}

		return true;
	}

	/**
	 * Get cached app credentials for an instance.
	 *
	 * The client_secret is stored encrypted; this method decrypts it before
	 * returning. If decryption fails the cache entry is treated as a miss so
	 * the app is re-registered.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return array|false Cached credentials or false if not found.
	 */
	public function get_cached_app_credentials( $instance_url ) {
		$apps     = get_option( 'fediboost_instance_apps', array() );
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );

		if ( ! isset( $apps[ $hostname ] ) ) {
			return false;
		}

		$credentials = $apps[ $hostname ];

		// Decrypt the client_secret if it is present.
		if ( isset( $credentials['client_secret'] ) ) {
			$encryption = Encryption::get_instance();
			$decrypted  = $encryption->decrypt( $credentials['client_secret'] );

			if ( false === $decrypted ) {
				// Decryption failed; treat as cache miss so the app re-registers.
				return false;
			}

			$credentials['client_secret'] = $decrypted;
		}

		return $credentials;
	}

	/**
	 * Cache app credentials for an instance.
	 *
	 * The client_secret is encrypted before storage.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param array  $credentials  The app credentials.
	 */
	private function cache_app_credentials( $instance_url, $credentials ) {
		$apps     = get_option( 'fediboost_instance_apps', array() );
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );

		// Encrypt the client_secret before persisting.
		if ( isset( $credentials['client_secret'] ) ) {
			$encryption = Encryption::get_instance();
			$encrypted  = $encryption->encrypt( $credentials['client_secret'] );

			if ( false !== $encrypted ) {
				$credentials['client_secret'] = $encrypted;
			}
		}

		$apps[ $hostname ] = $credentials;

		update_option( 'fediboost_instance_apps', $apps, false );
	}

	/**
	 * Store a connected account.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $username     The account username.
	 * @param string $access_token The plaintext access token (will be encrypted).
	 * @return bool True on success, false on failure.
	 */
	public function store_connected_account( $instance_url, $username, $access_token ) {
		$encryption = Encryption::get_instance();

		$encrypted_token = $encryption->encrypt( $access_token );
		if ( false === $encrypted_token ) {
			$this->log_error(
				'Failed to encrypt access token',
				array(
					'instance' => $instance_url,
					'username' => $username,
				)
			);
			return false;
		}

		$accounts = get_option( 'fediboost_accounts', array() );

		// Check if account already exists for this instance.
		$hostname = wp_parse_url( $instance_url, PHP_URL_HOST );
		foreach ( $accounts as $index => $account ) {
			$existing_host = wp_parse_url( $account['instance_url'], PHP_URL_HOST );
			if ( $existing_host === $hostname && $account['username'] === $username ) {
				// Update existing account.
				$accounts[ $index ] = array(
					'instance_url'    => $instance_url,
					'username'        => $username,
					'encrypted_token' => $encrypted_token,
					'status'          => 'connected',
					'connected_at'    => time(),
				);
				update_option( 'fediboost_accounts', $accounts, false );
				return true;
			}
		}

		// Add new account.
		$accounts[] = array(
			'instance_url'    => $instance_url,
			'username'        => $username,
			'encrypted_token' => $encrypted_token,
			'status'          => 'connected',
			'connected_at'    => time(),
		);

		update_option( 'fediboost_accounts', $accounts, false );

		return true;
	}

	/**
	 * Mark an account as disconnected due to auth failure.
	 *
	 * @param string $account_key The stable account key from Accounts::generate_account_key().
	 * @return bool True on success, false on failure.
	 */
	public function mark_account_disconnected( $account_key ) {
		$accounts_helper = Accounts::get_instance();
		return $accounts_helper->update_account_status( $account_key, Accounts::STATUS_DISCONNECTED );
	}

	/**
	 * Log an error for debugging.
	 *
	 * @param string $message The error message.
	 * @param array  $context Additional context data.
	 */
	private function log_error( $message, $context = array() ) {
		$log_message = sprintf(
			'FediBoost OAuth: %s - %s',
			$message,
			wp_json_encode( $context )
		);
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}
	}

	/**
	 * Build app registration request data.
	 *
	 * This is primarily for testing purposes.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return array The request data.
	 */
	public function build_app_registration_request( $instance_url ) {
		return array(
			'endpoint' => $instance_url . '/api/v1/apps',
			'body'     => array(
				'client_name'   => self::CLIENT_NAME,
				'redirect_uris' => $this->get_callback_url(),
				'scopes'        => self::SCOPES,
				'website'       => home_url(),
			),
		);
	}

	/**
	 * Build token exchange request data.
	 *
	 * This is primarily for testing purposes.
	 *
	 * @param string $instance_url  The Mastodon instance URL.
	 * @param string $code          The authorization code.
	 * @param string $client_id     The OAuth client ID.
	 * @param string $client_secret The OAuth client secret.
	 * @return array The request data.
	 */
	public function build_token_exchange_request( $instance_url, $code, $client_id, $client_secret ) {
		return array(
			'endpoint' => $instance_url . '/oauth/token',
			'body'     => array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $this->get_callback_url(),
				'code'          => $code,
				'scope'         => self::SCOPES,
			),
		);
	}
}
