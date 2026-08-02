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

	class AGSB_Fake_WPDB {
		public $last_error = '';
		public $values = array();
		public $queries = array();
		public function __construct() { $this->values = array_fill( 1, 501, 'http://localhost/page' ); }
		public function esc_like( $value ) { return $value; }
		public function prepare( $query, ...$values ) { foreach ( $values as $value ) { $query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 ); } return $query; }
		public function get_col( $query ) {
			if ( 'SHOW TABLES' === $query ) { return array( 'wp_mpg_dataset_rows' ); }
			if ( false !== strpos( $query, 'SELECT `id` FROM `wp_mpg_dataset_rows`' ) ) { preg_match( "/`id` > '([0-9]+)'/", $query, $match ); $after = empty( $match ) ? 0 : (int) $match[1]; return array_slice( array_keys( array_filter( $this->values, static function ( $value, $id ) use ( $after ) { return $id > $after && false !== strpos( $value, 'localhost' ); }, ARRAY_FILTER_USE_BOTH ) ), 0, 500 ); }
			return array();
		}
		public function get_results( $query ) { if ( false !== strpos( $query, 'SHOW FULL COLUMNS' ) ) { return array( array( 'Field' => 'id', 'Type' => 'bigint' ), array( 'Field' => 'row_data', 'Type' => 'longtext' ) ); } if ( false !== strpos( $query, 'SHOW KEYS' ) ) { return array( array( 'Column_name' => 'id' ) ); } return array(); }
		public function query( $query ) { $this->queries[] = $query; preg_match( '/IN \(([^)]+)\)/', $query, $match ); if ( empty( $match ) ) { return false; } preg_match_all( '/\d+/', $match[1], $ids ); foreach ( $ids[0] as $id ) { $this->values[(int) $id] = str_replace( 'http://localhost', 'https://live.test', $this->values[(int) $id] ); } return count( $ids[0] ); }
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger { public function info( $message, array $context = array() ) {} public function warning( $message, array $context = array() ) {} }
	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_url_liveness( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }
	function run_url_replace( $cancel = null ) {
		global $wpdb; $wpdb = new \AGSB_Fake_WPDB(); $events = array(); $service = new Database_Service( new Config(), new Logger() );
		$result = $service->replace_urls( array( 'http://localhost' => 'https://live.test' ), 'wp_', array( 'progress_callback' => static function ( $event ) use ( &$events ) { $events[] = $event; }, 'cancellation_check' => $cancel ) );
		return array( $result, $events, $wpdb );
	}

	list( $result, $events, $wpdb ) = run_url_replace();
	expect_url_liveness( ! \is_wp_error( $result ) && 501 === $result['rows_updated'], 'All 501 rows must be replaced.' );
	expect_url_liveness( 2 === count( $wpdb->queries ) && 0 === count( array_filter( $wpdb->queries, static function ( $query ) { return false === strpos( $query, ' IN (' ); } ) ), 'Fast replacement must use exactly two keyset UPDATE ... IN batches.' );
expect_url_liveness( count( array_filter( $events, static function ( $event ) { return 'fast-batch-start' === $event['phase']; } ) ) >= 2 && 2 === count( array_filter( $events, static function ( $event ) { return 'fast-batch-complete' === $event['phase']; } ) ), 'Every non-empty fast batch must emit start and completion progress.' );

	$checks = 0;
	list( $cancelled, $cancel_events, $cancel_db ) = run_url_replace( static function () use ( &$checks ) { return ++$checks >= 3; } );
	expect_url_liveness( \is_wp_error( $cancelled ) && 'ag_sync_bridge_operation_cancelled' === $cancelled->get_error_code(), 'Cancellation before second batch must return the canonical error.' );
	expect_url_liveness( 1 === count( $cancel_db->queries ), 'Cancellation before second batch must prevent a second UPDATE.' );
	expect_url_liveness( 1 === count( array_filter( $cancel_events, static function ( $event ) { return 'fast-batch-complete' === $event['phase']; } ) ), 'The completed first batch must have emitted its heartbeat before cancellation.' );

	echo "url replace liveness behavior: ok\n";
}
