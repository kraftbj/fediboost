<?php
/**
 * Autoloader for FediBoost classes.
 *
 * @package kraftbj/fediboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class ) {
		$prefix = 'FediBoost\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );

		$class_map = array(
			'Plugin'      => 'includes/class-fediboost.php',
			'Encryption'  => 'includes/class-fediboost-encryption.php',
			'Security'    => 'includes/class-fediboost-security.php',
			'OAuth'       => 'includes/class-fediboost-oauth.php',
			'Accounts'    => 'includes/class-fediboost-accounts.php',
			'ActivityPub' => 'includes/class-fediboost-activitypub.php',
			'Boost'       => 'includes/class-fediboost-boost.php',
			'Admin'       => 'admin/class-fediboost-admin.php',
		);

		if ( isset( $class_map[ $relative_class ] ) ) {
			$file = FEDIBOOST_PLUGIN_DIR . $class_map[ $relative_class ];
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);
