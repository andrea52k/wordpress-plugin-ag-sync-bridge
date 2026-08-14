<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_import_unlock( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	class Import_Unlock_Wpdb {
		public $last_error = '';
		public $queries = array();
		public $dbh;
		public $fail_next_insert = false;

		public function __construct() {
			$this->dbh = (object) array( 'errno' => 0, 'error' => '' );
		}

		public function query( $query ) {
			$this->queries[] = trim( (string) $query );
			if ( $this->fail_next_insert && 0 === strpos( trim( (string) $query ), 'INSERT INTO' ) ) {
				$this->fail_next_insert = false;
				$this->dbh->errno = 1114;
				$this->dbh->error = 'The table is full';
				return false;
			}
			return true;
		}
	}

	global $wpdb;
	$wpdb = new Import_Unlock_Wpdb();
	$sql = tempnam( sys_get_temp_dir(), 'agsb-unlock-' );
	file_put_contents( $sql, "LOCK TABLES `wp_options` WRITE;\nINSERT INTO `wp_options` VALUES (1, 'siteurl', 'https://example.test', 'yes');\n" );

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$method = $reflection->getMethod( 'import_via_php' );
	$method->setAccessible( true );
	$result = $method->invoke( $service, $sql, 'wp_' );
	unlink( $sql );

	expect_import_unlock( true === $result, 'PHP import should complete when the final dump statement omits UNLOCK TABLES.' );
	expect_import_unlock( 'UNLOCK TABLES' === end( $wpdb->queries ), 'PHP import must release table locks before returning to prefix remap.' );

	$wpdb->queries = array();
	$truncated = tempnam( sys_get_temp_dir(), 'agsb-truncated-' );
	file_put_contents( $truncated, "INSERT INTO `wp_options` VALUES (1, 'unterminated\n" );
	$result = $method->invoke( $service, $truncated, 'wp_' );
	unlink( $truncated );
	expect_import_unlock( $result instanceof \WP_Error, 'PHP import must reject an incomplete final SQL statement.' );
	expect_import_unlock( 'ag_sync_bridge_import_incomplete_statement' === $result->code, 'Incomplete SQL must return the dedicated fail-closed error.' );
	expect_import_unlock( 'UNLOCK TABLES' === end( $wpdb->queries ), 'Incomplete SQL rejection must release table locks.' );

	$wpdb->queries = array();
	$wpdb->last_error = '';
	$wpdb->fail_next_insert = true;
	$query_failure = tempnam( sys_get_temp_dir(), 'agsb-query-failure-' );
	file_put_contents( $query_failure, "INSERT INTO `wp_options` VALUES (1, 'siteurl', 'https://example.test', 'yes');\n" );
	$result = $method->invoke( $service, $query_failure, 'wp_' );
	unlink( $query_failure );
	expect_import_unlock( $result instanceof \WP_Error, 'A failed import statement must return a WP_Error.' );
	expect_import_unlock( 'ag_sync_bridge_import_query_failed' === $result->code, 'A failed import statement must use the dedicated error code.' );
	expect_import_unlock( 1114 === $result->data['mysql_errno'], 'The native MySQL errno must be preserved before UNLOCK TABLES.' );
	expect_import_unlock( 'The table is full' === $result->data['mysql_error'], 'The native MySQL error must be preserved before UNLOCK TABLES.' );
	expect_import_unlock( 'The table is full' === $result->message, 'The native MySQL error should be returned as the diagnostic message.' );
	expect_import_unlock( 'UNLOCK TABLES' === end( $wpdb->queries ), 'Query failure must still release table locks.' );

	echo "php import unlock regression: ok\n";
}
