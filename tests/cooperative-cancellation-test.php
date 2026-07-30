<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'AG_SYNC_BRIDGE_VERSION', '0.1.38' );

	function __( $message ) { return $message; }
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

namespace AGSyncBridge {
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-archive-service.php';
}

namespace {
	function expect_cancel( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	$root = sys_get_temp_dir() . '/agsync-cancel-' . bin2hex( random_bytes( 4 ) );
	$source = $root . '/source';
	mkdir( $source, 0777, true );
	file_put_contents( $source . '/one.txt', 'one' );
	$package = $root . '/partial.zip';
	$checks = 0;

	$archive = new \AGSyncBridge\Archive_Service(
		new \AGSyncBridge\Config(),
		new \AGSyncBridge\Logger()
	);
	$result = $archive->create_package(
		$package,
		'',
		array( 'type' => 'test' ),
		array(
			array(
				'type'      => 'directory',
				'source'    => $source,
				'archive'   => 'files',
				'component' => 'files',
			),
		),
		static function () { return false; },
		null,
		static function () use ( &$checks ) {
			$checks++;
			return $checks > 1;
		}
	);

	expect_cancel( is_wp_error( $result ), 'Archive cancellation must return a WP_Error.' );
	expect_cancel( 'ag_sync_bridge_operation_cancelled' === $result->get_error_code(), 'Archive cancellation code must be stable.' );
	expect_cancel( ! file_exists( $package ), 'Cancelled archive must not leave a publishable partial ZIP.' );

	$import_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	expect_cancel( false !== strpos( $import_source, "report_mutation_started( \$args, 'database_import_started' )" ), 'Database mutation must be marked before import.' );
	expect_cancel( false !== strpos( $import_source, "with_failure_context( \$import_result, 'database_import', true )" ), 'Database failure must require rollback.' );
	expect_cancel( false !== strpos( $import_source, "report_mutation_started( \$args, 'files_import_started' )" ), 'Filesystem mutation must be marked before copy.' );

	$rest_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );
	expect_cancel( false !== strpos( $rest_source, "'recovery_artifacts'" ), 'Dirty import failures must retain recovery artifact metadata.' );
	expect_cancel( false !== strpos( $rest_source, "if ( ! \$rollback_required )" ), 'Dirty import failures must preserve recovery files until verification.' );

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-sync-service.php' );
	expect_cancel( false !== strpos( $sync_source, 'Runtime cleanup skipped because target recovery is required.' ), 'Push orchestration must not clean recovery artifacts after a dirty remote import.' );
	expect_cancel( false !== strpos( $sync_source, 'return ! $rollback_required && $this->lock_manager->is_cancel_requested();' ), 'Local imports must finish their mutation boundary instead of stopping dirty.' );

	@unlink( $source . '/one.txt' );
	@rmdir( $source );
	@rmdir( $root );

	echo "cooperative cancellation safety: ok\n";
}
