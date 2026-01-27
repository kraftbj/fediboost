<?php
/**
 * Autoloader for FediBoost classes.
 *
 * @since 1.0.0
 *
 * @package kraftbj/fediboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'FediBoost\\';

		if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );

		$class_map = array(
			'Plugin'      => 'includes/class-plugin.php',
			'Encryption'  => 'includes/class-encryption.php',
			'Security'    => 'includes/class-security.php',
			'OAuth'       => 'includes/class-oauth.php',
			'Accounts'    => 'includes/class-accounts.php',
			'ActivityPub' => 'includes/class-activitypub.php',
			'Boost'       => 'includes/class-boost.php',
			'CLI'         => 'includes/class-cli.php',
			'Admin'       => 'admin/class-admin.php',
		);

		if ( isset( $class_map[ $relative_class ] ) ) {
			$file = FEDIBOOST_PLUGIN_DIR . $class_map[ $relative_class ];
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);
