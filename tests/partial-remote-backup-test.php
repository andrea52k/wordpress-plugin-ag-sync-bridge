<?php
declare( strict_types=1 );

namespace {
	$root = sys_get_temp_dir() . '/agsync-partial-backup-' . bin2hex( random_bytes( 4 ) ) . '/';
	if ( ! mkdir( $root . 'wp-content/mu-plugins', 0777, true ) && ! is_dir( $root . 'wp-content/mu-plugins' ) ) {
		throw new \RuntimeException( 'Unable to create the partial backup test root.' );
	}
	define( 'ABSPATH', str_replace( '\\', '/', $root ) );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );

	function __( $message ) { return $message; }
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function ensure_directory( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_file_name( $value ) { return basename( str_replace( '\\', '/', (string) $value ) ); }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function wp_generate_password( $length ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
	function site_url() { return 'https://remote.example'; }
	function home_url() { return 'https://remote.example'; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_list_pluck( array $items, $field ) { return array_map( static function ( $item ) use ( $field ) { return $item[ $field ] ?? null; }, $items ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

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
}

namespace AGSyncBridge {
	class Config {
		public $state = array();
		public function get_plugin_basename() { return 'ag-sync-bridge/ag-sync-bridge.php'; }
		public function get_exclude_patterns() { return array(); }
		public function get_role() { return 'remote'; }
		public function get_storage_dir() { return ABSPATH . 'wp-content/ag-sync-bridge-data'; }
		public function get_data_dir( $name ) { return $this->get_storage_dir() . '/' . $name; }
		public function get( $key, $default = null ) { return 'retention_count' === $key ? 3 : $default; }
		public function set_state_value( $key, $value ) { $this->state[ $key ] = $value; }
	}

	class Logger {
		public function info( $message, $context = array() ) {}
	}
	class Database_Service {
		public function get_table_prefix() { return 'wp_'; }
	}
	class Archive_Service {
		public $database_path = null;
		public $manifest = array();

		public function create_package( $package_path, $database_path, array $manifest, array $entries ) {
			unset( $entries );
			$this->database_path       = $database_path;
			$manifest['database']      = array( 'included' => false );
			$this->manifest            = $manifest;
			ensure_directory( dirname( $package_path ) );
			file_put_contents( $package_path, 'partial backup archive fixture' );
			return array(
				'package_path' => $package_path,
				'size_bytes'   => filesize( $package_path ),
				'sha256'       => hash_file( 'sha256', $package_path ),
				'manifest'     => $manifest,
			);
		}
	}

	function array_get( array $values, $key, $default = null ) {
		return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
	}

	require_once dirname( __DIR__ ) . '/includes/class-file-system-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-import-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-export-service.php';
	require_once dirname( __DIR__ ) . '/includes/class-remote-backup-result.php';

	function expect_partial_backup( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	file_put_contents( ABSPATH . '.htaccess', "RewriteEngine On\n" );
	file_put_contents( ABSPATH . 'wp-content/mu-plugins/existing.php', "<?php\n" );

	$file_system = new File_System_Service( new Config(), new Logger() );
	$data        = $file_system->get_partial_backup_export_data(
		array(
			'wp-content/mu-plugins/missing.php',
			'.htaccess',
			'.htaccess',
		)
	);

	expect_partial_backup( ! \is_wp_error( $data ), 'Remote partial backup paths must pass through the shared normalizer.' );
	expect_partial_backup(
		array( '.htaccess', 'wp-content/mu-plugins/missing.php' ) === $data['paths'],
		'Remote partial backup paths must be deterministic, unique and canonical.'
	);
	expect_partial_backup( 1 === count( $data['entries'] ), 'Only existing remote paths may be added to the archive.' );
	expect_partial_backup( 2 === count( $data['partial_entries'] ), 'Every requested path must be represented in rollback metadata.' );
	expect_partial_backup( false === $data['partial_entries'][1]['exists'], 'A path absent before deploy must be stored as a rollback tombstone.' );

	$config   = new Config();
	$archive_service = new Archive_Service();
	$exporter = new Export_Service( $config, new Logger(), $file_system, new Database_Service(), $archive_service );
	$backup   = $exporter->create_partial_backup( array( '.htaccess', 'wp-content/mu-plugins/missing.php' ) );
	expect_partial_backup( ! \is_wp_error( $backup ), 'Partial backup export must complete for existing and absent protected paths.' );
	expect_partial_backup( '' === $archive_service->database_path, 'Partial backup archive must be created without database.sql.' );
	expect_partial_backup( false === $archive_service->manifest['database']['included'], 'Partial backup manifest must attest that the database is excluded.' );
	expect_partial_backup( 'partial' === $backup['snapshot_scope'], 'Partial backup metadata must expose scope=partial.' );

	$deployed_after_backup = ABSPATH . 'wp-content/mu-plugins/missing.php';
	file_put_contents( $deployed_after_backup, "<?php\n// deployed after backup\n" );
	$importer   = new Import_Service( new Config(), new Logger(), $file_system, new Database_Service(), new Archive_Service() );
	$reflection = new \ReflectionClass( Import_Service::class );
	$restore    = $reflection->getMethod( 'import_partial_entry' );
	$restore->setAccessible( true );
	$restored = $restore->invoke(
		$importer,
		ABSPATH . 'unused-files-root',
		array(
			'path'   => 'wp-content/mu-plugins/missing.php',
			'type'   => 'missing',
			'exists' => false,
		)
	);
	expect_partial_backup( ! \is_wp_error( $restored ), 'Rollback tombstone must remain importable.' );
	expect_partial_backup( ! file_exists( $deployed_after_backup ), 'Rollback tombstone must remove a path that did not exist before deploy.' );
	expect_partial_backup( 'restore-absence' === $restored['action'], 'Rollback tombstone result must report restoration of absence.' );

	$missing_normal_push = $file_system->normalize_partial_export_paths( array( 'wp-content/mu-plugins/missing.php' ) );
	expect_partial_backup( \is_wp_error( $missing_normal_push ), 'Normal partial snapshots must still reject missing source paths.' );

	$unsafe = $file_system->get_partial_backup_export_data( array( '../wp-config.php' ) );
	expect_partial_backup( \is_wp_error( $unsafe ) && 'ag_sync_bridge_partial_path_unsafe' === $unsafe->get_error_code(), 'Traversal must fail before backup creation.' );

	$invalid_type = $file_system->get_partial_backup_export_data( array( array( '.htaccess' ) ) );
	expect_partial_backup( \is_wp_error( $invalid_type ) && 'ag_sync_bridge_partial_path_invalid_type' === $invalid_type->get_error_code(), 'Nested or non-string path values must fail validation.' );

	$core = $file_system->get_partial_backup_export_data( array( 'wp-admin/index.php' ) );
	expect_partial_backup( \is_wp_error( $core ) && 'ag_sync_bridge_partial_path_forbidden' === $core->get_error_code(), 'WordPress core paths must remain forbidden.' );

	$runtime_data = $file_system->get_partial_backup_export_data( array( 'wp-content/ag-sync-bridge-data/backups/example.zip' ) );
	expect_partial_backup( \is_wp_error( $runtime_data ) && 'ag_sync_bridge_partial_path_forbidden' === $runtime_data->get_error_code(), 'AG Sync runtime data must never be included in a partial backup.' );

	$archive = tempnam( sys_get_temp_dir(), 'agsync-partial-proof-' );
	if ( false === $archive ) {
		throw new \RuntimeException( 'Unable to create the partial proof fixture.' );
	}
	$zip_path = $archive . '.zip';
	rename( $archive, $zip_path );
	file_put_contents( $zip_path, 'verified partial backup fixture' );

	$paths    = array( '.htaccess', 'wp-content/mu-plugins/missing.php' );
	$verified = Remote_Backup_Result::completed_from_archive(
		array(
			'path'          => $zip_path,
			'basename'      => basename( $zip_path ),
			'size_bytes'    => filesize( $zip_path ),
			'sha256'        => hash_file( 'sha256', $zip_path ),
			'type'          => 'pre-push-backup',
			'snapshot_scope'=> 'partial',
			'partial_paths' => $paths,
		),
		'partial',
		$paths
	);

	expect_partial_backup( ! \is_wp_error( $verified ), 'A verified partial archive with exact scope and paths must be accepted.' );
	expect_partial_backup( 'partial' === $verified['scope'], 'Partial proof must attest scope=partial.' );
	expect_partial_backup( $paths === $verified['paths'], 'Partial proof must attest the exact protected path list.' );
	expect_partial_backup(
		$verified === Remote_Backup_Result::require_completed( $verified, 'partial', $paths ),
		'Push-side verification must accept the exact remote partial backup contract.'
	);

	$mismatch = Remote_Backup_Result::require_completed( $verified, 'partial', array( '.htaccess' ) );
	expect_partial_backup( \is_wp_error( $mismatch ), 'A partial backup covering more or fewer paths than the deploy plan must fail closed.' );
	expect_partial_backup( 'backup_paths_mismatch' === $mismatch->get_error_data()['reason'], 'Path mismatch must have an explicit verification reason.' );

	$scope_mismatch = Remote_Backup_Result::require_completed( $verified, 'full', array() );
	expect_partial_backup( \is_wp_error( $scope_mismatch ), 'A partial backup must never satisfy a full deployment backup.' );
	expect_partial_backup( 'backup_scope_mismatch' === $scope_mismatch->get_error_data()['reason'], 'Scope mismatch must have an explicit verification reason.' );

	$http_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-http-client.php' );
	expect_partial_backup( false !== strpos( $http_source, "'scope' => (string) \$scope" ), 'The signed backup request body must include scope.' );
	expect_partial_backup( false !== strpos( $http_source, "'paths' => array_values( \$paths )" ), 'The signed backup request body must include paths.' );

	$rest_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );
	expect_partial_backup( false !== strpos( $rest_source, 'normalize_partial_export_paths( $paths, false )' ), 'The remote route must use the shared partial path normalizer.' );
	expect_partial_backup( false !== strpos( $rest_source, 'create_partial_backup( $paths, $type, $context )' ), 'The remote route must create a file-only partial backup.' );

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-sync-service.php' );
	expect_partial_backup( false !== strpos( $sync_source, 'require_completed( $remote_backup, $backup_scope, $backup_paths )' ), 'The push must verify backup scope and exact paths.' );
	expect_partial_backup( false !== strpos( $sync_source, 'run_remote_preflight( $use_existing_snapshot, $is_partial_push, ! $skip_remote_backup )' ), 'Preflight must require the signed backup protocol before any protected push.' );

	$export_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-export-service.php' );
	expect_partial_backup( false !== strpos( $export_source, "create_package(\n\t\t\t\$package_data['path'],\n\t\t\t''," ), 'Partial backup export must omit database.sql.' );

	@unlink( $zip_path );
	@unlink( ABSPATH . '.htaccess' );
	@unlink( ABSPATH . 'wp-content/mu-plugins/existing.php' );
	foreach ( glob( ABSPATH . 'wp-content/ag-sync-bridge-data/backups/*.zip' ) ?: array() as $generated_backup ) {
		@unlink( $generated_backup );
	}
	@rmdir( ABSPATH . 'wp-content/ag-sync-bridge-data/backups' );
	@rmdir( ABSPATH . 'wp-content/ag-sync-bridge-data' );
	@rmdir( ABSPATH . 'wp-content/mu-plugins' );
	@rmdir( ABSPATH . 'wp-content' );
	@rmdir( ABSPATH );

	echo "partial remote backup: ok\n";
}
