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

	class AGSB_Site_Kit_Wpdb {
		public $options = 'wp_options';
		public $last_error = '';
		public $option_rows = array();
		public $fail_capture = false;
		public $fail_next_insert = false;
		public $queries = array();
		private $transaction_snapshot = null;

		public function esc_like( $value ) { return addcslashes( (string) $value, '_%\\' ); }

		public function prepare( $query, ...$values ) {
			foreach ( $values as $value ) {
				$string_position = strpos( $query, '%s' );
				$integer_position = strpos( $query, '%d' );
				if ( false !== $integer_position && ( false === $string_position || $integer_position < $string_position ) ) {
					$query = preg_replace( '/%d/', (string) (int) $value, $query, 1 );
					continue;
				}
				$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 );
			}
			return $query;
		}

		public function get_var( $query ) {
			if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
				return $this->options;
			}
			return null;
		}

		public function get_results( $query, $output = null ) {
			if ( $this->fail_capture ) {
				$this->last_error = 'site kit capture failed';
				return null;
			}

			$rows = array();
			foreach ( $this->option_rows as $option_name => $option ) {
				if ( 0 === strpos( $option_name, 'googlesitekit_' ) ) {
					$rows[] = array(
						'option_name'  => $option_name,
						'option_value' => $option['option_value'],
						'autoload'     => $option['autoload'],
					);
				}
			}
			return $rows;
		}

		public function query( $query ) {
			$this->queries[] = $query;
			if ( 'START TRANSACTION' === $query ) {
				$this->transaction_snapshot = $this->option_rows;
				return true;
			}
			if ( 'ROLLBACK' === $query ) {
				$this->option_rows = $this->transaction_snapshot;
				return true;
			}
			if ( 'COMMIT' === $query ) {
				$this->transaction_snapshot = null;
				return true;
			}
			if ( 0 === strpos( $query, 'DELETE FROM `wp_options`' ) ) {
				$this->option_rows = array_filter(
					$this->option_rows,
					static function ( $option_name ) { return 0 !== strpos( $option_name, 'googlesitekit_' ); },
					ARRAY_FILTER_USE_KEY
				);
				return 1;
			}
			if ( preg_match( "/INSERT INTO `wp_options` .* VALUES \('([^']*)', '([^']*)', '([^']*)'\)/", $query, $match ) ) {
				if ( $this->fail_next_insert ) {
					$this->fail_next_insert = false;
					$this->last_error = 'site kit insert failed';
					return false;
				}
				$this->option_rows[ $match[1] ] = array( 'option_value' => $match[2], 'autoload' => $match[3] );
				return 1;
			}
			return false;
		}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_site_kit_preservation( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	global $wpdb;
	$wpdb = new \AGSB_Site_Kit_Wpdb();
	$wpdb->option_rows = array(
		'googlesitekit_credentials' => array( 'option_value' => 'live-secret-value', 'autoload' => 'no' ),
		'googlesitekit_connected_proxy_url' => array( 'option_value' => 'https://live.example', 'autoload' => 'auto-on' ),
		'unrelated_option' => array( 'option_value' => 'live-unrelated', 'autoload' => 'yes' ),
	);

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$captured = $service->capture_google_site_kit_options();
	expect_site_kit_preservation( is_array( $captured ) && 2 === count( $captured ), 'Every googlesitekit_ target option must be captured.' );
	expect_site_kit_preservation( 'live-secret-value' === $captured[0]['option_value'] && 'no' === $captured[0]['autoload'], 'Capture must preserve raw Site Kit values and autoload state.' );

	$wpdb->option_rows = array(
		'googlesitekit_credentials' => array( 'option_value' => 'local-secret-value', 'autoload' => 'yes' ),
		'googlesitekit_property' => array( 'option_value' => 'local-property', 'autoload' => 'yes' ),
		'unrelated_option' => array( 'option_value' => 'imported-unrelated', 'autoload' => 'yes' ),
	);
	$restored = $service->restore_google_site_kit_options( $captured );
	expect_site_kit_preservation( ! is_wp_error( $restored ) && 2 === $restored['restored'], 'Target Site Kit restore must succeed and report its row count.' );
	expect_site_kit_preservation( 'live-secret-value' === $wpdb->option_rows['googlesitekit_credentials']['option_value'], 'The live Site Kit connection must replace the imported local connection.' );
	expect_site_kit_preservation( 'https://live.example' === $wpdb->option_rows['googlesitekit_connected_proxy_url']['option_value'], 'The live Site Kit URL must survive the import.' );
	expect_site_kit_preservation( ! isset( $wpdb->option_rows['googlesitekit_property'] ), 'Source-only Site Kit properties must be removed.' );
	expect_site_kit_preservation( 'imported-unrelated' === $wpdb->option_rows['unrelated_option']['option_value'], 'Options outside the protected prefix must remain imported normally.' );

	$source_state = array(
		'googlesitekit_credentials' => array( 'option_value' => 'local-secret-value', 'autoload' => 'yes' ),
		'unrelated_option' => array( 'option_value' => 'imported-unrelated', 'autoload' => 'yes' ),
	);
	$wpdb->option_rows = $source_state;
	$wpdb->fail_next_insert = true;
	$failed_restore = $service->restore_google_site_kit_options( $captured );
	expect_site_kit_preservation( is_wp_error( $failed_restore ) && 'ag_sync_bridge_google_site_kit_restore_failed' === $failed_restore->get_error_code(), 'A failed Site Kit insert must fail closed with a dedicated error.' );
	expect_site_kit_preservation( $source_state === $wpdb->option_rows && 'ROLLBACK' === end( $wpdb->queries ), 'A failed Site Kit restore must roll back all option mutations.' );

	$wpdb->fail_capture = true;
	$failed_capture = $service->capture_google_site_kit_options();
	expect_site_kit_preservation( is_wp_error( $failed_capture ) && 'ag_sync_bridge_google_site_kit_capture_failed' === $failed_capture->get_error_code(), 'A Site Kit capture failure must stop before database mutation.' );

	$import_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	$database_import = strpos( $import_source, '$this->database->import_from_file(' );
	$site_kit_restore = strpos( $import_source, '$this->database->restore_google_site_kit_options(', $database_import );
	$after_import_checkpoint = strpos( $import_source, "check_cancellation( \$args, 'after_database_import'", $database_import );
	expect_site_kit_preservation( false !== $database_import && false !== $site_kit_restore && false !== $after_import_checkpoint && $database_import < $site_kit_restore && $site_kit_restore < $after_import_checkpoint, 'Target Site Kit state must be restored before any post-import checkpoint.' );

	echo "google site kit option preservation regression: ok\n";
}
