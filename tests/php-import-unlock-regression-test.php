<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = null ) {}
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

	echo "php import unlock regression: ok\n";
}
