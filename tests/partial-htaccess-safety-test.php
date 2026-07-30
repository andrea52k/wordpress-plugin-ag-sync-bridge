<?php
declare( strict_types=1 );

namespace {
	$root = sys_get_temp_dir() . '/agsync-partial-htaccess-' . bin2hex( random_bytes( 4 ) ) . '/';
	if ( ! mkdir( $root, 0777, true ) && ! is_dir( $root ) ) {
		throw new \RuntimeException( 'Unable to create the test root.' );
	}
	define( 'ABSPATH', str_replace( '\\', '/', $root ) );

	function __( $message ) { return $message; }
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

namespace AGSyncBridge {
	class Config {
		public function get_plugin_basename() { return 'ag-sync-bridge/ag-sync-bridge.php'; }
		public function get_exclude_patterns() { return array(); }
	}

	class Logger {}
	class Database_Service {}
	class Archive_Service {}

	require_once dirname( __DIR__ ) . '/includes/class-file-system-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-import-service.php';

	function expect_partial_htaccess( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	file_put_contents( ABSPATH . '.htaccess', "RewriteEngine On\n" );
	file_put_contents( ABSPATH . 'secret.ini', "secret=true\n" );

	$file_system = new File_System_Service( new Config(), new Logger() );
	$normalized  = $file_system->normalize_partial_export_paths( array( '.htaccess' ) );
	expect_partial_htaccess( ! \is_wp_error( $normalized ), 'Exporter must accept an explicit .htaccess path.' );
	expect_partial_htaccess( array( '.htaccess' ) === $normalized, 'Exporter must preserve the canonical .htaccess path.' );

	$rejected = $file_system->normalize_partial_export_paths( array( 'secret.ini' ) );
	expect_partial_htaccess( \is_wp_error( $rejected ), 'Exporter must keep rejecting unapproved root files.' );
	expect_partial_htaccess( 'ag_sync_bridge_partial_path_not_supported' === $rejected->get_error_code(), 'Exporter rejection must be explicit.' );

	$importer   = new Import_Service(
		new Config(),
		new Logger(),
		$file_system,
		new Database_Service(),
		new Archive_Service()
	);
	$reflection = new \ReflectionClass( Import_Service::class );
	$sanitize   = $reflection->getMethod( 'sanitize_partial_entry_path' );
	$sanitize->setAccessible( true );

	$accepted = $sanitize->invoke( $importer, '.htaccess' );
	expect_partial_htaccess( '.htaccess' === $accepted, 'Importer must accept the same .htaccess path as the exporter.' );

	$rejected = $sanitize->invoke( $importer, 'secret.ini' );
	expect_partial_htaccess( \is_wp_error( $rejected ), 'Importer must reject unapproved root files.' );
	expect_partial_htaccess( 'ag_sync_bridge_partial_entry_root_forbidden' === $rejected->get_error_code(), 'Importer rejection must be explicit.' );

	@unlink( ABSPATH . '.htaccess' );
	@unlink( ABSPATH . 'secret.ini' );
	@rmdir( ABSPATH );

	echo "partial htaccess safety: ok\n";
}
