<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', str_replace( '\\', '/', dirname( __DIR__ ) ) . '/' );
	define( 'AG_SYNC_BRIDGE_VERSION', 'test' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function get_bloginfo( $key ) { return 'test'; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}
	require_once dirname( __DIR__ ) . '/includes/class-archive-service.php';
	function expect_database_retention( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

	$root = normalize_path( sys_get_temp_dir() . '/agsb-full-db-' . bin2hex( random_bytes( 5 ) ) );
	mkdir( $root, 0777, true );
	$archive = new Archive_Service( new Config(), new Logger() );
	$export_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-export-service.php' );
	expect_database_retention( false !== strpos( $export_source, "\$package_data['path'] . '.database.sql'" ), 'Database export is not moved beside the snapshot package.' );
	expect_database_retention( false !== strpos( $export_source, 'ag_sync_bridge_database_export_protection_failed' ), 'Protected database move does not fail closed.' );
	expect_database_retention( false !== strpos( $export_source, 'finally {' ) && false !== strpos( $export_source, 'database_cleanup_failed' ), 'Protected database cleanup is not guaranteed.' );
	$manifest = array( 'id' => 'full-db-test', 'snapshot_scope' => 'full' );
	$target = $root . '/snapshot.zip';
	try {
		$missing = $archive->create_package( $target, $root . '/missing.sql', $manifest, array(), static function () { return false; } );
		expect_database_retention( is_wp_error( $missing ) && 'ag_sync_bridge_full_snapshot_database_missing' === $missing->get_error_code(), 'A missing database dump was accepted for a full snapshot.' );
		expect_database_retention( ! file_exists( $target ), 'Rejected full snapshot created a ZIP.' );

		$db = $root . '/protected.database.sql';
		file_put_contents( $db, "CREATE TABLE retained (id INT);\n" );
		$created = $archive->create_package( $target, $db, $manifest, array(), static function () { return false; } );
		expect_database_retention( is_array( $created ) && is_file( $target ), 'Valid protected database dump did not create a snapshot.' );
		$zip = new \ZipArchive();
		expect_database_retention( true === $zip->open( $target ), 'Unable to inspect created snapshot.' );
		try {
			expect_database_retention( false !== $zip->locateName( 'database.sql' ), 'Created full snapshot omitted database.sql.' );
			$stored = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
			expect_database_retention( ! empty( $stored['database']['filename'] ) && 'database.sql' === $stored['database']['filename'], 'Manifest does not bind database.sql.' );
		} finally {
			$zip->close();
		}
		echo "full snapshot database retention: ok\n";
	} finally {
		@unlink( $target );
		@unlink( $root . '/protected.database.sql' );
		@rmdir( $root );
	}
}
