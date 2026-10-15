<?php
/**
 * Minimal WP-CLI stand-in for testing FediBoost's CLI commands.
 *
 * Records what a command prints instead of writing to STDOUT/STDERR, and
 * throws from error() where WP-CLI would exit. Only the API FediBoost uses
 * is provided.
 *
 * @package kraftbj/fediboost
 */

namespace {

	/**
	 * Recording WP_CLI stub.
	 */
	class WP_CLI {

		/**
		 * Everything the command printed, in order.
		 *
		 * Each entry is array( type, payload ), where type is success, warning,
		 * log, error, or items. Items payloads are array( format, rows, fields ).
		 *
		 * @var array
		 */
		public static $output = array();

		/**
		 * Commands are invoked directly in tests, so registration is a no-op.
		 *
		 * @param string   $name     Command name.
		 * @param callable $callable Command callback.
		 */
		public static function add_command( $name, $callable ) {}

		/**
		 * Record a success message.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ) {
			self::$output[] = array( 'success', $message );
		}

		/**
		 * Record a warning.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ) {
			self::$output[] = array( 'warning', $message );
		}

		/**
		 * Record a log line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ) {
			self::$output[] = array( 'log', $message );
		}

		/**
		 * Record an error and stop the command, as WP-CLI's exit would.
		 *
		 * @param string $message Message.
		 * @throws \WP_CLI\ExitException Always.
		 */
		public static function error( $message ) {
			self::$output[] = array( 'error', $message );
			throw new \WP_CLI\ExitException( $message, 1 );
		}

		/**
		 * Get the payloads of every recorded entry of one type.
		 *
		 * @param string $type Entry type.
		 * @return array Payloads.
		 */
		public static function get( $type ) {
			$payloads = array();
			foreach ( self::$output as $entry ) {
				if ( $type === $entry[0] ) {
					$payloads[] = $entry[1];
				}
			}
			return $payloads;
		}
	}
}

namespace WP_CLI {

	/**
	 * Thrown by the WP_CLI::error() stub, mirroring WP-CLI's own exit exception.
	 */
	class ExitException extends \Exception {}
}

namespace WP_CLI\Utils {

	/**
	 * Get a flag value, as WP-CLI does.
	 *
	 * @param array  $assoc_args Associative arguments.
	 * @param string $flag       Flag name.
	 * @param mixed  $default    Default value.
	 * @return mixed Flag value.
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
	}

	/**
	 * Record formatted output instead of printing it.
	 *
	 * @param string $format Output format.
	 * @param array  $items  Rows.
	 * @param array  $fields Fields.
	 */
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$output[] = array( 'items', array( $format, $items, $fields ) );
	}
}
