<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_cache_flush() {}
	function is_serialized( $value ) { return false; }
	function maybe_unserialize( $value ) { return $value; }
	function sanitize_key( $value ) { return (string) $value; }

	class AGSB_Runtime_WPDB {
		public $last_error = '';
		public $values = array();
		public $hashes = array();
		public $queries = array();
		public function __construct() {
			for ( $row = 0; $row < 501; $row++ ) {
				$this->values[ $row ] = '["http://localhost/disinfestazione/page-' . $row . '"]';
				$this->hashes[ $row ] = hash( 'sha256', $this->values[ $row ] );
			}
		}
		public function esc_like( $value ) { return $value; }
		public function prepare( $query, ...$values ) {
			if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
			foreach ( $values as $value ) { $query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 ); }
			return $query;
		}
		public function get_col( $query ) { return 'SHOW TABLES' === $query ? array( 'wp_mpg_runtime_dataset_rows' ) : array(); }
		public function get_results( $query ) {
			if ( false !== strpos( $query, 'SHOW FULL COLUMNS' ) ) {
				return array(
					array( 'Field' => 'version_id', 'Type' => 'bigint' ),
					array( 'Field' => 'project_id', 'Type' => 'int' ),
					array( 'Field' => 'row_index', 'Type' => 'int' ),
					array( 'Field' => 'url_path', 'Type' => 'varchar(512)' ),
					array( 'Field' => 'city', 'Type' => 'varchar(190)' ),
					array( 'Field' => 'province', 'Type' => 'varchar(190)' ),
					array( 'Field' => 'row_data', 'Type' => 'longtext' ),
					array( 'Field' => 'row_sha256', 'Type' => 'char(64)' ),
				);
			}
			if ( false !== strpos( $query, 'SHOW KEYS' ) ) {
				return array( array( 'Column_name' => 'version_id' ), array( 'Column_name' => 'row_index' ) );
			}
			if ( false !== strpos( $query, 'SELECT `version_id`, `row_index` FROM `wp_mpg_runtime_dataset_rows`' ) ) {
				preg_match( "/`version_id` = '7' AND `row_index` > '([0-9]+)'/", $query, $match );
				$after = empty( $match ) ? -1 : (int) $match[1];
				$ids = array_slice( array_keys( array_filter( $this->values, static function ( $value, $row ) use ( $after ) { return $row > $after && false !== strpos( $value, 'localhost' ); }, ARRAY_FILTER_USE_BOTH ) ), 0, 500 );
				return array_map( static function ( $row ) { return array( 'version_id' => 7, 'row_index' => $row ); }, $ids );
			}
			return array();
		}
		public function query( $query ) {
			$this->queries[] = $query;
			preg_match_all( "/`version_id` = '7' AND `row_index` = '([0-9]+)'/", $query, $matches );
			if ( empty( $matches[1] ) || false === strpos( $query, '`row_sha256` = SHA2(REPLACE(`row_data`' ) ) { return false; }
			foreach ( $matches[1] as $row ) {
				$this->values[(int) $row] = str_replace( 'http://localhost/disinfestazione', 'https://live.test', $this->values[(int) $row] );
				$this->hashes[(int) $row] = hash( 'sha256', $this->values[(int) $row] );
			}
			return count( $matches[1] );
		}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger { public function info( $message, array $context = array() ) {} public function warning( $message, array $context = array() ) {} }
	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_runtime_url_replace( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

	global $wpdb;
	$wpdb = new \AGSB_Runtime_WPDB();
	$events = array();
	$service = new Database_Service( new Config(), new Logger() );
	$result = $service->replace_urls(
		array( 'http://localhost/disinfestazione' => 'https://live.test' ),
		'wp_',
		array( 'progress_callback' => static function ( $event ) use ( &$events ) { $events[] = $event; } )
	);

	expect_runtime_url_replace( ! \is_wp_error( $result ) && 501 === $result['rows_updated'], 'All runtime rows must be remapped.' );
	expect_runtime_url_replace( 2 === count( $wpdb->queries ), 'Runtime replacement must use two set-based batches, not per-row updates.' );
	expect_runtime_url_replace( 0 === count( array_filter( $wpdb->values, static function ( $value ) { return false !== strpos( $value, 'localhost' ); } ) ), 'Local URLs survived runtime replacement.' );
	expect_runtime_url_replace( 0 === count( array_filter( $wpdb->values, static function ( $value, $row ) use ( $wpdb ) { return ! hash_equals( hash( 'sha256', $value ), $wpdb->hashes[ $row ] ); }, ARRAY_FILTER_USE_BOTH ) ), 'Runtime row hashes were not recomputed.' );
	$batch_starts = array_values( array_filter( $events, static function ( $event ) { return 'fast-batch-start' === $event['phase']; } ) );
	expect_runtime_url_replace( isset( $batch_starts[1]['last_key']['version_id'], $batch_starts[1]['last_key']['row_index'] ) && 7 === $batch_starts[1]['last_key']['version_id'] && 499 === $batch_starts[1]['last_key']['row_index'], 'Composite key checkpoint was not applied to the second batch.' );
	expect_runtime_url_replace( 2 === count( array_filter( $events, static function ( $event ) { return 'fast-batch-complete' === $event['phase']; } ) ), 'Each runtime batch must emit a completion heartbeat.' );

	echo "runtime url replace behavior: ok\n";
}
