<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; }
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

		public function query( $query ) {
			$this->queries[] = trim( (string) $query );
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

	echo "php import unlock regression: ok\n";
}
