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
		public $version_digest;
		public $digest_fault = '';
		public function __construct() {
			$digest = hash_init( 'sha256' );
			for ( $row = 0; $row < 501; $row++ ) {
				$this->values[ $row ] = '["http://localhost/disinfestazione/page-' . $row . '"]';
				$this->hashes[ $row ] = hash( 'sha256', $this->values[ $row ] );
				hash_update( $digest, $row . "\0/service-" . $row . "/\0" . $this->hashes[$row] . "\n" );
			}
			$this->version_digest = hash_final( $digest );
		}
		public function get_row( $query ) {
			if ( strpos( $query, 'SELECT dataset_sha256' ) === 0 ) { return array( 'dataset_sha256' => $this->version_digest ); }
			return array( 'id' => 7, 'project_id' => 1, 'row_count' => 'count' === $this->digest_fault ? 502 : 501, 'column_count' => 1, 'dataset_sha256' => $this->version_digest );
		}
		public function update( $table, $updates, $where ) {
			if ( 'write' === $this->digest_fault ) { return false; }
			if ( $where['id'] !== 7 || $where['dataset_sha256'] !== $this->version_digest ) { return 0; }
			$this->version_digest = $updates['dataset_sha256'];
			return 1;
		}
		public function esc_like( $value ) { return $value; }
		public function prepare( $query, ...$values ) {
			if ( 1 === count( $values ) && is_array( $values[0] ) ) { $values = $values[0]; }
			foreach ( $values as $value ) { $query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 ); }
			return $query;
		}
		public function get_col( $query ) { return 'SHOW TABLES' === $query ? array( 'wp_mpg_runtime_dataset_rows' ) : array(); }
		public function get_results( $query ) {
			if ( strpos( $query, 'SELECT row_index,project_id,url_path,row_data,row_sha256' ) === 0 ) {
				preg_match( '/row_index>=([0-9]+)/', $query, $offset );
				$result = array();
				for ( $index = (int) $offset[1]; $index < min( 501, (int) $offset[1] + 500 ); $index++ ) {
					$result[] = array( 'row_index' => $index, 'project_id' => 'project' === $this->digest_fault ? 2 : 1,
						'url_path' => '/service-' . $index . '/', 'row_data' => $this->values[$index],
						'row_sha256' => 'hash' === $this->digest_fault ? str_repeat( '0', 64 ) : $this->hashes[$index] );
				}
				return $result;
			}
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
	$before_version_digest = $wpdb->version_digest;
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
	$digest = hash_init( 'sha256' );
	foreach ( $wpdb->hashes as $index => $hash ) { hash_update( $digest, $index . "\0/service-" . $index . "/\0" . $hash . "\n" ); }
	expect_runtime_url_replace( $wpdb->version_digest === hash_final( $digest ) && $wpdb->version_digest !== $before_version_digest, 'Full URL replacement left the version dataset digest stale.' );
	expect_runtime_url_replace( 1 === $result['runtime_versions_refreshed'], 'Changed runtime version was not reported.' );
	foreach ( array( 'hash', 'project', 'count', 'write', 'cancel' ) as $fault ) {
		$wpdb = new \AGSB_Runtime_WPDB();
		$wpdb->digest_fault = $fault;
		$before = $wpdb->version_digest;
		$cancel = false;
		$options = array(
			'progress_callback' => static function ( $event ) use ( &$cancel, $fault ) { if ( 'cancel' === $fault && 'runtime-digest-batch-complete' === $event['phase'] ) { $cancel = true; } },
			'cancellation_check' => static function () use ( &$cancel ) { return $cancel; },
		);
		$failed = $service->replace_urls( array( 'http://localhost/disinfestazione' => 'https://live.test' ), 'wp_', $options );
		expect_runtime_url_replace( \is_wp_error( $failed ) && $wpdb->version_digest === $before, 'Invalid full runtime replacement published a dataset digest: ' . $fault );
	}

	echo "runtime url replace behavior: ok\n";
}
