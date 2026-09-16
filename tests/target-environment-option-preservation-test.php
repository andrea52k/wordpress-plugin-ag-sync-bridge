<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	class AGSB_Target_Environment_Wpdb {
		public $options = 'wp_options';
		public $last_error = '';
		public $option_rows = array();
		private $transaction_snapshot = null;
		public function esc_like( $value ) { return addcslashes( (string) $value, '_%\\' ); }
		public function prepare( $query, ...$values ) {
			foreach ( $values as $value ) {
				$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 );
			}
			return $query;
		}
		public function get_var( $query ) { return false !== strpos( $query, 'SHOW TABLES LIKE' ) ? $this->options : null; }
		public function get_results( $query, $output = null ) {
			$rows = array();
			foreach ( $this->option_rows as $name => $option ) {
				if ( self::protected_name( $name ) ) {
					$rows[] = array( 'option_name' => $name, 'option_value' => $option['option_value'], 'autoload' => $option['autoload'] );
				}
			}
			return $rows;
		}
		public function query( $query ) {
			if ( 'START TRANSACTION' === $query ) { $this->transaction_snapshot = $this->option_rows; return true; }
			if ( 'ROLLBACK' === $query ) { $this->option_rows = $this->transaction_snapshot; return true; }
			if ( 'COMMIT' === $query ) { $this->transaction_snapshot = null; return true; }
			if ( 0 === strpos( $query, 'DELETE FROM `wp_options`' ) ) {
				$this->option_rows = array_filter( $this->option_rows, static function ( $name ) { return ! self::protected_name( $name ); }, ARRAY_FILTER_USE_KEY );
				return 1;
			}
			if ( preg_match( "/INSERT INTO `wp_options` .* VALUES \\('([^']*)', '([^']*)', '([^']*)'\\)/", $query, $match ) ) {
				$this->option_rows[ $match[1] ] = array( 'option_value' => $match[2], 'autoload' => $match[3] );
				return 1;
			}
			return false;
		}
		private static function protected_name( $name ) {
			return 'fluentmail-settings' === $name || 1 === preg_match( '/^(?:_fluentmail_|_fluentsmtp_|_fluent_smtp_|_fsmtp_|litespeed[._])/', $name );
		}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}
	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';
	function expect_target_environment( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

	global $wpdb;
	$wpdb = new \AGSB_Target_Environment_Wpdb();
	$wpdb->option_rows = array(
		'fluentmail-settings' => array( 'option_value' => 'live-smtp-secret', 'autoload' => 'yes' ),
		'_fsmtp_health' => array( 'option_value' => 'live-health', 'autoload' => 'no' ),
		'litespeed.conf.cache' => array( 'option_value' => 'live-cache-config', 'autoload' => 'yes' ),
		'litespeed_admin' => array( 'option_value' => 'live-admin-config', 'autoload' => 'no' ),
		'unrelated_option' => array( 'option_value' => 'live-unrelated', 'autoload' => 'yes' ),
	);

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$captured = $service->capture_target_environment_options();
	expect_target_environment( is_array( $captured ) && 4 === count( $captured ), 'All target SMTP and LiteSpeed options must be captured.' );

	$wpdb->option_rows = array(
		'fluentmail-settings' => array( 'option_value' => 'local-smtp-secret', 'autoload' => 'yes' ),
		'litespeed.conf.cache' => array( 'option_value' => 'local-cache-config', 'autoload' => 'yes' ),
		'_fluentsmtp_local_only' => array( 'option_value' => 'remove-me', 'autoload' => 'no' ),
		'unrelated_option' => array( 'option_value' => 'imported-unrelated', 'autoload' => 'yes' ),
	);
	$restored = $service->restore_target_environment_options( $captured );
	expect_target_environment( ! is_wp_error( $restored ) && 4 === $restored['restored'], 'Target environment options must restore atomically.' );
	expect_target_environment( 'live-smtp-secret' === $wpdb->option_rows['fluentmail-settings']['option_value'], 'Live SMTP credentials must survive an import.' );
	expect_target_environment( 'live-cache-config' === $wpdb->option_rows['litespeed.conf.cache']['option_value'], 'Live LiteSpeed configuration must survive an import.' );
	expect_target_environment( ! isset( $wpdb->option_rows['_fluentsmtp_local_only'] ), 'Source-only protected options must be removed.' );
	expect_target_environment( 'imported-unrelated' === $wpdb->option_rows['unrelated_option']['option_value'], 'Unrelated imported options must remain untouched.' );

	$import_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	$db_import = strpos( $import_source, '$this->database->import_from_file(' );
	$restore = strpos( $import_source, '$this->database->restore_target_environment_options(', $db_import );
	$checkpoint = strpos( $import_source, "check_cancellation( \$args, 'after_database_import'", $db_import );
	expect_target_environment( false !== $db_import && false !== $restore && false !== $checkpoint && $db_import < $restore && $restore < $checkpoint, 'Target environment settings must restore before the post-import checkpoint.' );

	echo "target environment option preservation: ok\n";
}
