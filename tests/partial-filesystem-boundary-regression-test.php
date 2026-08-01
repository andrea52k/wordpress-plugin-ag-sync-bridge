<?php
declare( strict_types=1 );

namespace {
	$agsb_fixture_root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/agsb-partial-boundary-' . bin2hex( random_bytes( 6 ) );
	mkdir( $agsb_fixture_root . '/site', 0777, true );

	define( 'ABSPATH', $agsb_fixture_root . '/site/' );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	define( 'AG_SYNC_BRIDGE_VERSION', 'test' );

	function __( $message ) { return $message; }
	function normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
	function ensure_directory( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function wp_generate_password( $length = 12 ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function get_bloginfo( $key ) { unset( $key ); return 'test'; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_cache_flush() { return true; }
	function do_action( $hook ) { unset( $hook ); }

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}

	function agsb_create_directory_alias( $link, $target ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$command = 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '"';
			$output  = array();
			$status  = 1;
			exec( $command, $output, $status );
			return 0 === $status && is_dir( $link );
		}

		return symlink( $target, $link );
	}

	function agsb_remove_test_tree( $path ) {
		if ( is_link( $path ) ) {
			unlink( $path );
			return;
		}
		if ( is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			agsb_remove_test_tree( normalize_path( $item->getPathname() ) );
		}
		rmdir( $path );
	}

	function agsb_remove_directory_alias( $path ) {
		if ( '\\' === DIRECTORY_SEPARATOR && is_dir( $path ) ) {
			return rmdir( $path );
		}
		if ( is_link( $path ) ) {
			return unlink( $path );
		}
		return ! file_exists( $path );
	}
}

namespace AGSyncBridge {
	class Config {
		public function get_plugin_basename() { return 'ag-sync-bridge/ag-sync-bridge.php'; }
		public function get_exclude_patterns() {
			return array(
				'wp-content/ag-sync-bridge-data/*',
				'wp-content/plugins/ag-sync-bridge/*',
				'wp-content/cache/*',
				'*.log',
				'*.tmp',
			);
		}
	}

	class Logger {
		public function info( $message, array $context = array() ) { unset( $message, $context ); }
	}

	class Database_Service {}

	require_once dirname( __DIR__ ) . '/includes/class-file-system-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-archive-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-import-service.php';

	function expect_partial_boundary( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$config      = new Config();
	$logger      = new Logger();
	$file_system = new File_System_Service( $config, $logger );
	$archive     = new Archive_Service( $config, $logger );
	$importer    = new Import_Service( $config, $logger, $file_system, new Database_Service(), $archive );

	$fixture_root = dirname( rtrim( ABSPATH, '/' ) );
	$outside      = $fixture_root . '/outside';
	$link_parent  = ABSPATH . 'wp-content/uploads';
	$link_path    = $link_parent . '/external';
	$nested_link  = '';

	try {
		ensure_directory( $outside );
		ensure_directory( $link_parent );
		file_put_contents( $outside . '/probe.txt', 'outside-original' );
		expect_partial_boundary( \agsb_create_directory_alias( $link_path, $outside ), 'Test fixture must create a directory junction/symlink.' );

		$linked_selection = $file_system->normalize_partial_export_paths( array( 'wp-content/uploads/external/probe.txt' ), false );
		expect_partial_boundary(
			is_wp_error( $linked_selection ) && 'ag_sync_bridge_partial_path_link_forbidden' === $linked_selection->get_error_code(),
			'Partial selection through a linked ancestor must fail closed.'
		);

		$sanitize = new \ReflectionMethod( Import_Service::class, 'sanitize_partial_entry_path' );
		$sanitize->setAccessible( true );
		$linked_import = $sanitize->invoke( $importer, 'wp-content/uploads/external/probe.txt' );
		expect_partial_boundary(
			is_wp_error( $linked_import ) && 'ag_sync_bridge_partial_path_link_forbidden' === $linked_import->get_error_code(),
			'Partial import through a linked ancestor must fail before mutation.'
		);
		expect_partial_boundary( 'outside-original' === file_get_contents( $outside . '/probe.txt' ), 'Rejected linked import must not alter the outside target.' );

		$link_container = ABSPATH . 'wp-content/uploads/link-container';
		ensure_directory( $link_container );
		$nested_link = $link_container . '/nested-external';
		expect_partial_boundary( \agsb_create_directory_alias( $nested_link, $outside ), 'Test fixture must create a nested directory alias.' );
		$linked_package = $fixture_root . '/linked-partial.zip';
		$linked_archive = $archive->create_package(
			$linked_package,
			'',
			array(
				'snapshot_scope' => 'partial',
				'partial_paths'  => array( 'wp-content/uploads/link-container' ),
			),
			array(
				array(
					'component'    => 'partial:wp-content/uploads/link-container',
					'source'       => $link_container,
					'archive'      => 'files/wp-content/uploads/link-container',
					'type'         => 'directory',
					'partial_path' => 'wp-content/uploads/link-container',
				),
			),
			static function () { return false; }
		);
		expect_partial_boundary(
			is_wp_error( $linked_archive ) && 'ag_sync_bridge_partial_source_link_forbidden' === $linked_archive->get_error_code(),
			'Partial snapshot traversal must reject a nested link before archiving outside content.'
		);

		$plugins_target = ABSPATH . 'wp-content/plugins';
		$plugins_source = $fixture_root . '/partial-source/plugins';
		ensure_directory( $plugins_target . '/ag-sync-bridge' );
		ensure_directory( $plugins_target . '/old-plugin' );
		ensure_directory( $plugins_source . '/new-plugin' );
		file_put_contents( $plugins_target . '/ag-sync-bridge/bridge.php', 'protected' );
		file_put_contents( $plugins_target . '/old-plugin/old.php', 'old' );
		file_put_contents( $plugins_source . '/new-plugin/new.php', 'new' );

		$blocked_replace = $file_system->replace_directory( $plugins_source, $plugins_target, array(), null, true );
		expect_partial_boundary(
			is_wp_error( $blocked_replace ) && 'ag_sync_bridge_partial_directory_contains_excluded' === $blocked_replace->get_error_code(),
			'A broad partial directory must fail before deleting protected descendants.'
		);
		expect_partial_boundary( file_exists( $plugins_target . '/ag-sync-bridge/bridge.php' ), 'Protected plugin file must survive a rejected directory replacement.' );
		expect_partial_boundary( file_exists( $plugins_target . '/old-plugin/old.php' ), 'No ordinary sibling may be deleted after a fail-closed preflight.' );
		expect_partial_boundary( ! file_exists( $plugins_target . '/new-plugin/new.php' ), 'Rejected directory replacement must not copy new payload files.' );

		$safe_target = ABSPATH . 'wp-content/uploads/safe-project';
		$safe_source = $fixture_root . '/partial-source/safe-project';
		ensure_directory( $safe_target );
		ensure_directory( $safe_source );
		file_put_contents( $safe_target . '/old.txt', 'old' );
		file_put_contents( $safe_source . '/new.txt', 'new' );
		$safe_replace = $file_system->replace_directory( $safe_source, $safe_target, array(), null, true );
		expect_partial_boundary( true === $safe_replace, 'A regular partial directory without exclusions must remain supported.' );
		expect_partial_boundary( ! file_exists( $safe_target . '/old.txt' ) && 'new' === file_get_contents( $safe_target . '/new.txt' ), 'Safe partial directory replacement must retain normal replacement semantics.' );

		$safe_partial_path = 'wp-content/uploads/safe-project';
		$safe_package = $fixture_root . '/safe-partial.zip';
		$safe_archive = $archive->create_package(
			$safe_package,
			'',
			array(
				'snapshot_scope'  => 'partial',
				'partial_paths'   => array( $safe_partial_path ),
				'partial_entries' => array(
					array(
						'path'    => $safe_partial_path,
						'type'    => 'directory',
						'exists'  => true,
						'archive' => 'files/' . $safe_partial_path,
					),
				),
			),
			array(
				array(
					'component'    => 'partial:' . $safe_partial_path,
					'source'       => $safe_source,
					'archive'      => 'files/' . $safe_partial_path,
					'type'         => 'directory',
					'partial_path' => $safe_partial_path,
				),
			),
			static function () { return false; }
		);
		expect_partial_boundary( is_array( $safe_archive ), 'A safe partial directory must still produce a package.' );
		$safe_inspection = $archive->inspect_package( $safe_package, array( 'expected_partial_paths' => array( $safe_partial_path ) ) );
		expect_partial_boundary( is_array( $safe_inspection ) && 'partial' === $safe_inspection['snapshot_scope'], 'A package produced from a safe partial directory must pass pre-extraction inventory validation.' );

		$files_root = $fixture_root . '/import/files';
		$mpg_source = $files_root . '/wp-content/mpg-uploads/selected.csv';
		$mpg_target = ABSPATH . 'wp-content/mpg-uploads';
		ensure_directory( dirname( $mpg_source ) );
		ensure_directory( $mpg_target );
		file_put_contents( $mpg_source, "url\nhttps://remote.example/selected\n" );
		file_put_contents( $mpg_target . '/selected.csv', "url\nhttps://remote.example/old\n" );
		file_put_contents( $mpg_target . '/sibling.csv', "url\nhttps://remote.example/sibling\n" );

		$import_entry = new \ReflectionMethod( Import_Service::class, 'import_partial_entry' );
		$import_entry->setAccessible( true );
		$mpg_result = $import_entry->invoke(
			$importer,
			$files_root,
			array(
				'path'    => 'wp-content/mpg-uploads/selected.csv',
				'type'    => 'file',
				'exists'  => true,
				'archive' => 'files/wp-content/mpg-uploads/selected.csv',
			),
			array( 'https://remote.example' => 'https://local.example' ),
			array()
		);
		expect_partial_boundary( ! is_wp_error( $mpg_result ), 'A selected MPG file must import successfully.' );
		expect_partial_boundary( false !== strpos( file_get_contents( $mpg_target . '/selected.csv' ), 'https://local.example/selected' ), 'Selected MPG file must receive URL replacement.' );
		expect_partial_boundary( false !== strpos( file_get_contents( $mpg_target . '/sibling.csv' ), 'https://remote.example/sibling' ), 'Unselected MPG sibling must remain byte-for-byte outside the replacement scope.' );

		echo "partial filesystem boundary regression: ok\n";
	} finally {
		foreach ( array( $nested_link, $link_path ) as $alias ) {
			\agsb_remove_directory_alias( $alias );
		}
		\agsb_remove_test_tree( $fixture_root );
	}
}
