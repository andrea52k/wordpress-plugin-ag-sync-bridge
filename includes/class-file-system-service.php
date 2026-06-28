<?php
namespace AGSyncBridge;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_System_Service {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function prepare_runtime_dirs() {
		$dirs = array(
			$this->config->get_storage_dir(),
			$this->get_snapshot_dir(),
			$this->get_backup_dir(),
			$this->get_temp_dir(),
			$this->get_upload_chunks_dir(),
			$this->get_incoming_dir(),
			$this->config->get_data_dir( 'logs' ),
		);

		foreach ( $dirs as $dir ) {
			ensure_directory( $dir );
			$this->write_protection_files( $dir );
		}
	}

	public function get_snapshot_dir() {
		return $this->config->get_data_dir( 'snapshots' );
	}

	public function get_backup_dir() {
		return $this->config->get_data_dir( 'backups' );
	}

	public function get_temp_dir() {
		return $this->config->get_data_dir( 'temp' );
	}

	public function get_incoming_dir() {
		return $this->config->get_data_dir( 'incoming' );
	}

	public function get_upload_chunks_dir() {
		return normalize_path( $this->get_temp_dir() . '/upload-chunks' );
	}

	public function create_temp_dir( $prefix = 'job' ) {
		$this->prepare_runtime_dirs();

		$prefix = sanitize_key( $prefix );
		$dir    = $this->get_temp_dir() . '/' . $prefix . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );

		if ( ! ensure_directory( $dir ) ) {
			return new \WP_Error( 'ag_sync_bridge_temp_dir_failed', __( 'Unable to create temporary working directory.', 'ag-sync-bridge' ) );
		}

		return normalize_path( $dir );
	}

	public function diagnose_runtime_storage( $required_bytes = 0, $deep = false ) {
		$required_bytes = max( 0, (int) $required_bytes );
		$directories    = array(
			'storage'       => $this->config->get_storage_dir(),
			'snapshots'     => $this->get_snapshot_dir(),
			'backups'       => $this->get_backup_dir(),
			'temp'          => $this->get_temp_dir(),
			'upload_chunks' => $this->get_upload_chunks_dir(),
			'incoming'      => $this->get_incoming_dir(),
			'logs'          => $this->config->get_data_dir( 'logs' ),
		);
		$results        = array();
		$ok             = true;

		foreach ( $directories as $scope => $path ) {
			$results[ $scope ] = $this->diagnose_directory( $scope, $path, $required_bytes );
			if ( empty( $results[ $scope ]['ok'] ) ) {
				$ok = false;
			}
		}

		$state            = $this->config->get_state();
		$current_operation = array_get( $state, 'current_operation', array() );
		$remote_import     = array_get( $state, 'remote_import_operation', array() );

		$result = array(
			'checked_at'          => gmdate( 'c' ),
			'ok'                  => $ok,
			'required_free_bytes' => $required_bytes,
			'required_free_human' => format_bytes( $required_bytes ),
			'storage_dir'         => $this->config->get_storage_dir(),
			'directories'         => $results,
			'operation'           => array(
				'current'       => is_array( $current_operation ) ? $current_operation : array(),
				'remote_import' => is_array( $remote_import ) ? $remote_import : array(),
			),
			'php'                 => array(
				'upload_tmp_dir'     => (string) ini_get( 'upload_tmp_dir' ),
				'open_basedir'       => (string) ini_get( 'open_basedir' ),
				'memory_limit'       => (string) ini_get( 'memory_limit' ),
				'max_execution_time' => (string) ini_get( 'max_execution_time' ),
			),
		);

		if ( $deep ) {
			$latest_snapshot = $this->list_packages( 'snapshots', 1, true );
			$latest_snapshot = empty( $latest_snapshot ) ? array() : $latest_snapshot[0];

			if ( ! empty( $latest_snapshot ) ) {
				$latest_snapshot['full_snapshot_validation'] = $this->validate_full_snapshot_manifest( array_get( $latest_snapshot, 'manifest', array() ) );
			}

			$sitemap_integrity = $this->get_root_sitemap_integrity( ABSPATH );
			if ( empty( $sitemap_integrity['ok'] ) ) {
				$ok = false;
			}

			$result['ok']                 = $ok;
			$result['plugin_version']     = AG_SYNC_BRIDGE_VERSION;
			$result['ziparchive']         = array(
				'available' => class_exists( 'ZipArchive' ),
			);
			$result['latest_snapshot']    = $latest_snapshot;
			$result['sitemap_integrity']  = $sitemap_integrity;
		}

		return $result;
	}

	public function get_new_package_path( $type = 'snapshot' ) {
		$is_backup = false !== strpos( $type, 'backup' );
		$dir       = $is_backup ? $this->get_backup_dir() : $this->get_snapshot_dir();
		$basename  = sanitize_file_name( $type . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.zip' );

		return array(
			'dir'      => $dir,
			'basename' => $basename,
			'path'     => normalize_path( $dir . '/' . $basename ),
		);
	}

	public function get_meta_path_for_package( $package_path ) {
		return preg_replace( '/\.zip$/', '.meta.json', $package_path );
	}

	public function write_json_file( $path, array $data ) {
		ensure_directory( dirname( $path ) );
		return false !== file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public function read_json_file( $path ) {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	public function find_package( $basename, $type = 'snapshots' ) {
		$basename = sanitize_file_name( basename( (string) $basename ) );
		$base_dir = 'backups' === $type ? $this->get_backup_dir() : $this->get_snapshot_dir();
		$path     = normalize_path( $base_dir . '/' . $basename );

		if ( file_exists( $path ) ) {
			return $path;
		}

		return '';
	}

	public function list_packages( $type = 'snapshots', $limit = 10, $include_hash = false ) {
		$base_dir = 'backups' === $type ? $this->get_backup_dir() : $this->get_snapshot_dir();

		if ( ! is_dir( $base_dir ) ) {
			return array();
		}

		$items = glob( $base_dir . '/*.zip' );
		$items = is_array( $items ) ? $items : array();

		usort(
			$items,
			static function ( $left, $right ) {
				return filemtime( $right ) <=> filemtime( $left );
			}
		);

		$items = array_slice( $items, 0, absint( $limit ) );
		$list  = array();

		foreach ( $items as $package_path ) {
			$list[] = $this->build_package_metadata( $package_path, $include_hash );
		}

		return $list;
	}

	public function list_restore_candidates( $limit = 50 ) {
		$candidates = array();

		foreach ( $this->list_packages( 'backups', $limit ) as $package ) {
			$package['source_label'] = __( 'Cartella backup del plugin', 'ag-sync-bridge' );
			$package['reference']    = 'backup:' . array_get( $package, 'basename', '' );
			$package['package_type'] = 'backup';
			$candidates[]            = $package;
		}

		foreach ( $this->list_packages( 'snapshots', $limit ) as $package ) {
			$package['source_label'] = __( 'Cartella snapshot del plugin', 'ag-sync-bridge' );
			$package['reference']    = 'snapshot:' . array_get( $package, 'basename', '' );
			$package['package_type'] = 'snapshot';
			$candidates[]            = $package;
		}

		foreach ( $this->config->get_external_backup_dirs() as $dir ) {
			$label = sprintf(
				/* translators: %s: directory path */
				__( 'Cartella esterna: %s', 'ag-sync-bridge' ),
				$dir
			);

			foreach ( $this->scan_zip_directory( $dir, $limit ) as $package ) {
				$package['source_label'] = $label;
				$package['reference']    = array_get( $package, 'path', '' );
				$package['package_type'] = 'external';
				$candidates[]            = $package;
			}
		}

		usort(
			$candidates,
			static function ( $left, $right ) {
				return strtotime( array_get( $right, 'created_at', '' ) ) <=> strtotime( array_get( $left, 'created_at', '' ) );
			}
		);

		return array_slice( $candidates, 0, absint( $limit ) );
	}

	public function resolve_restore_package( $reference = '', $custom_path = '' ) {
		$custom_path = $this->normalize_manual_path( $custom_path );

		if ( $custom_path ) {
			return $this->resolve_manual_backup_path( $custom_path );
		}

		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return '';
		}

		if ( 0 === strpos( $reference, 'backup:' ) ) {
			return $this->find_package( substr( $reference, 7 ), 'backups' );
		}

		if ( 0 === strpos( $reference, 'snapshot:' ) ) {
			return $this->find_package( substr( $reference, 9 ), 'snapshots' );
		}

		if ( path_is_absolute( $reference ) || false !== strpos( $reference, '/' ) || false !== strpos( $reference, '\\' ) ) {
			return $this->resolve_manual_backup_path( $this->normalize_manual_path( $reference ) );
		}

		$package = $this->find_package( $reference, 'backups' );

		if ( $package ) {
			return $package;
		}

		return $this->find_package( $reference, 'snapshots' );
	}

	public function cleanup_old_packages( $type = 'snapshots', $retention_override = null ) {
		$retention = null === $retention_override ? max( 1, absint( $this->config->get( 'retention_count', 3 ) ) ) : max( 0, absint( $retention_override ) );
		$packages  = $this->list_packages( $type, 999, false );
		$summary   = $this->empty_cleanup_summary( $type );

		if ( 'snapshots' === $type ) {
			$packages = $this->get_stale_snapshot_packages_by_scope( $packages, $retention );
		} elseif ( count( $packages ) > $retention ) {
			$packages = array_slice( $packages, $retention );
		} else {
			$packages = array();
		}

		foreach ( $packages as $package ) {
			$path = array_get( $package, 'path', '' );

			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$summary = $this->merge_cleanup_summary( $summary, $this->cleanup_path_with_summary( $path ) );
			$summary = $this->merge_cleanup_summary( $summary, $this->cleanup_path_with_summary( $this->get_meta_path_for_package( $path ) ) );
		}

		return $summary;
	}

	private function get_stale_snapshot_packages_by_scope( array $packages, $retention ) {
		$groups = array();

		foreach ( $packages as $package ) {
			$scope = (string) array_get( $package, 'snapshot_scope', 'unknown' );
			$scope = $scope ? $scope : 'unknown';

			if ( ! isset( $groups[ $scope ] ) ) {
				$groups[ $scope ] = array();
			}

			$groups[ $scope ][] = $package;
		}

		$stale = array();
		foreach ( $groups as $scope_packages ) {
			if ( count( $scope_packages ) > $retention ) {
				$stale = array_merge( $stale, array_slice( $scope_packages, $retention ) );
			}
		}

		return $stale;
	}

	public function cleanup_runtime_storage( $snapshot_retention = null, $backup_retention = null, $temp_hours = 6 ) {
		$this->prepare_runtime_dirs();

		$temp_hours      = null === $temp_hours ? 6 : $temp_hours;
		$min_age_seconds = max( 0, absint( $temp_hours ) * HOUR_IN_SECONDS );
		$state           = $this->config->get_state();
		$operation       = array_get( $state, 'current_operation', array() );
		$remote_import   = array_get( $state, 'remote_import_operation', array() );
		$operation_status = is_array( $operation ) ? (string) array_get( $operation, 'status', '' ) : '';
		$operation_open  = is_array( $operation ) && ! empty( $operation ) && in_array( $operation_status, array( 'queued', 'running' ), true );
		$operation_open  = $operation_open || ( is_array( $remote_import ) && in_array( array_get( $remote_import, 'status', '' ), array( 'queued', 'running' ), true ) );
		$results         = array(
			'snapshots'       => $this->cleanup_old_packages( 'snapshots', $snapshot_retention ),
			'backups'         => $this->cleanup_old_packages( 'backups', $backup_retention ),
			'temp'            => $operation_open ? $this->skipped_cleanup_summary( 'temp', 'operation_running' ) : $this->cleanup_directory_children(
				$this->get_temp_dir(),
				$min_age_seconds,
				array( 'index.php', '.htaccess', 'operation.lock', 'upload-chunks' ),
				'temp'
			),
			'incoming'        => $operation_open ? $this->skipped_cleanup_summary( 'incoming', 'operation_running' ) : $this->cleanup_directory_children(
				$this->get_incoming_dir(),
				$min_age_seconds,
				array( 'index.php', '.htaccess' ),
				'incoming'
			),
			'upload_chunks'   => $operation_open ? $this->skipped_cleanup_summary( 'upload_chunks', 'operation_running' ) : $this->cleanup_directory_children(
				$this->get_upload_chunks_dir(),
				$min_age_seconds,
				array( 'index.php', '.htaccess' ),
				'upload_chunks'
			),
		);

		$total = $this->empty_cleanup_summary( 'total' );
		foreach ( $results as $result ) {
			$total = $this->merge_cleanup_summary( $total, $result );
		}

		return array(
			'cleaned_at'          => gmdate( 'c' ),
			'temp_min_age_hours'  => absint( $temp_hours ),
			'snapshot_retention'  => null === $snapshot_retention ? max( 1, absint( $this->config->get( 'retention_count', 3 ) ) ) : max( 0, absint( $snapshot_retention ) ),
			'backup_retention'    => null === $backup_retention ? max( 1, absint( $this->config->get( 'retention_count', 3 ) ) ) : max( 0, absint( $backup_retention ) ),
			'results'             => $results,
			'total'               => $total,
		);
	}

	public function cleanup_path( $path ) {
		$path = normalize_path( $path );

		if ( ! file_exists( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/** @var SplFileInfo $item */
		foreach ( $items as $item ) {
			$item_path = normalize_path( $item->getPathname() );
			if ( $item->isDir() ) {
				rmdir( $item_path );
			} else {
				unlink( $item_path );
			}
		}

		return rmdir( $path );
	}

	private function cleanup_directory_children( $dir, $min_age_seconds = 0, array $preserve = array(), $scope = 'directory' ) {
		$summary = $this->empty_cleanup_summary( $scope );
		$dir     = normalize_path( $dir );

		if ( ! is_dir( $dir ) ) {
			return $summary;
		}

		$items = new \DirectoryIterator( $dir );
		foreach ( $items as $item ) {
			if ( $item->isDot() ) {
				continue;
			}

			$name = $item->getFilename();
			if ( in_array( $name, $preserve, true ) ) {
				continue;
			}

			$path = normalize_path( $item->getPathname() );
			if ( ! $this->is_path_old_enough( $path, $min_age_seconds ) ) {
				continue;
			}

			$summary = $this->merge_cleanup_summary( $summary, $this->cleanup_path_with_summary( $path ) );
		}

		return $summary;
	}

	private function cleanup_path_with_summary( $path ) {
		$summary = $this->empty_cleanup_summary( 'path' );
		$path    = normalize_path( $path );

		if ( ! file_exists( $path ) ) {
			return $summary;
		}

		$summary['deleted_bytes'] = $this->get_path_size( $path );
		$summary['deleted_files'] = $this->count_path_files( $path );
		$summary['deleted_dirs']  = is_dir( $path ) && ! is_link( $path ) ? $this->count_path_dirs( $path ) + 1 : 0;

		if ( ! $this->cleanup_path( $path ) ) {
			$summary['errors'][] = $path;
		}

		return $summary;
	}

	private function diagnose_directory( $scope, $path, $required_bytes = 0 ) {
		$path           = normalize_path( $path );
		$errors         = array();
		$created        = ensure_directory( $path );
		$exists         = file_exists( $path );
		$is_dir         = is_dir( $path );
		$writable       = $is_dir && is_writable( $path );
		$write_test     = false;
		$free_bytes     = $this->safe_disk_free_space( $path );
		$total_bytes    = $this->safe_disk_total_space( $path );
		$required_bytes = max( 0, (int) $required_bytes );

		if ( ! $created ) {
			$errors[] = 'directory_create_failed';
		}

		if ( ! $exists ) {
			$errors[] = 'directory_missing';
		} elseif ( ! $is_dir ) {
			$errors[] = 'path_is_not_directory';
		}

		if ( $is_dir && ! $writable ) {
			$errors[] = 'directory_not_writable';
		}

		if ( $is_dir && $writable ) {
			$test_path = normalize_path( $path . '/agsb-write-test-' . wp_generate_password( 8, false, false ) . '.tmp' );
			$written   = @file_put_contents( $test_path, 'ok', LOCK_EX );
			$write_test = false !== $written && file_exists( $test_path );

			if ( file_exists( $test_path ) ) {
				@unlink( $test_path );
			}

			if ( ! $write_test ) {
				$errors[] = 'write_test_failed';
			}
		}

		if ( null !== $free_bytes && $required_bytes > 0 && $free_bytes < $required_bytes ) {
			$errors[] = 'free_space_below_required';
		}

		return array(
			'scope'               => $scope,
			'path'                => $path,
			'ok'                  => empty( $errors ),
			'exists'              => $exists,
			'is_dir'              => $is_dir,
			'writable'            => $writable,
			'write_test'          => $write_test,
			'free_bytes'          => $free_bytes,
			'free_human'          => null === $free_bytes ? '' : format_bytes( $free_bytes ),
			'total_bytes'         => $total_bytes,
			'total_human'         => null === $total_bytes ? '' : format_bytes( $total_bytes ),
			'required_free_bytes' => $required_bytes,
			'required_free_human' => format_bytes( $required_bytes ),
			'errors'              => $errors,
		);
	}

	private function safe_disk_free_space( $path ) {
		$target = is_dir( $path ) ? $path : dirname( $path );
		$value  = @disk_free_space( $target );

		return false === $value ? null : (int) $value;
	}

	private function safe_disk_total_space( $path ) {
		$target = is_dir( $path ) ? $path : dirname( $path );
		$value  = @disk_total_space( $target );

		return false === $value ? null : (int) $value;
	}

	private function is_path_old_enough( $path, $min_age_seconds ) {
		if ( $min_age_seconds <= 0 ) {
			return true;
		}

		$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;
		return $mtime > 0 && ( time() - $mtime ) >= $min_age_seconds;
	}

	private function get_path_size( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return (int) filesize( $path );
		}

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$size  = 0;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isFile() || $item->isLink() ) {
				$size += (int) $item->getSize();
			}
		}

		return $size;
	}

	private function count_path_files( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return 1;
		}

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$count = 0;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isFile() || $item->isLink() ) {
				$count++;
			}
		}

		return $count;
	}

	private function count_path_dirs( $path ) {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			return 0;
		}

		$count = 0;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				$count++;
			}
		}

		return $count;
	}

	private function empty_cleanup_summary( $scope ) {
		return array(
			'scope'         => $scope,
			'deleted_files' => 0,
			'deleted_dirs'  => 0,
			'deleted_bytes' => 0,
			'errors'        => array(),
		);
	}

	private function skipped_cleanup_summary( $scope, $reason ) {
		$summary             = $this->empty_cleanup_summary( $scope );
		$summary['skipped']  = true;
		$summary['reason']   = $reason;

		return $summary;
	}

	private function merge_cleanup_summary( array $left, array $right ) {
		$left['deleted_files'] += (int) array_get( $right, 'deleted_files', 0 );
		$left['deleted_dirs']  += (int) array_get( $right, 'deleted_dirs', 0 );
		$left['deleted_bytes'] += (int) array_get( $right, 'deleted_bytes', 0 );
		$left['errors']         = array_merge( array_get( $left, 'errors', array() ), array_get( $right, 'errors', array() ) );

		return $left;
	}

	public function get_export_entries() {
		$entries = array(
			array(
				'component' => 'uploads',
				'source'    => WP_CONTENT_DIR . '/uploads',
				'archive'   => 'files/wp-content/uploads',
				'type'      => 'directory',
			),
			array(
				'component' => 'mpg-uploads',
				'source'    => WP_CONTENT_DIR . '/mpg-uploads',
				'archive'   => 'files/wp-content/mpg-uploads',
				'type'      => 'directory',
			),
			array(
				'component' => 'plugins',
				'source'    => WP_CONTENT_DIR . '/plugins',
				'archive'   => 'files/wp-content/plugins',
				'type'      => 'directory',
			),
			array(
				'component' => 'themes',
				'source'    => WP_CONTENT_DIR . '/themes',
				'archive'   => 'files/wp-content/themes',
				'type'      => 'directory',
			),
		);

		if ( is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			$entries[] = array(
				'component' => 'mu-plugins',
				'source'    => WP_CONTENT_DIR . '/mu-plugins',
				'archive'   => 'files/wp-content/mu-plugins',
				'type'      => 'directory',
			);
		}

		$entries[] = array(
			'component' => 'wp-config.php',
			'source'    => ABSPATH . 'wp-config.php',
			'archive'   => 'files/root/wp-config.php',
			'type'      => 'file',
		);

		foreach ( $this->get_root_sync_files( ABSPATH ) as $basename => $source_path ) {
			$entries[] = array(
				'component' => $basename,
				'source'    => $source_path,
				'archive'   => 'files/root/' . $basename,
				'type'      => 'file',
			);
		}

		if ( $this->config->get( 'include_htaccess', false ) && file_exists( ABSPATH . '.htaccess' ) ) {
			$entries[] = array(
				'component' => '.htaccess',
				'source'    => ABSPATH . '.htaccess',
				'archive'   => 'files/root/.htaccess',
				'type'      => 'file',
			);
		}

		return array_values(
			array_filter(
				$entries,
				static function ( $entry ) {
					return file_exists( $entry['source'] );
				}
			)
		);
	}

	public function normalize_partial_export_paths( array $paths ) {
		$normalized = array();

		foreach ( $paths as $path ) {
			$relative = $this->normalize_partial_export_path( $path );
			if ( is_wp_error( $relative ) ) {
				return $relative;
			}

			if ( '' !== $relative ) {
				$normalized[] = $relative;
			}
		}

		$normalized = array_values( array_unique( array_filter( $normalized ) ) );
		usort(
			$normalized,
			static function ( $left, $right ) {
				return strlen( $left ) <=> strlen( $right );
			}
		);

		$collapsed = array();
		foreach ( $normalized as $relative ) {
			foreach ( $collapsed as $existing ) {
				if ( $relative === $existing || 0 === strpos( $relative, rtrim( $existing, '/' ) . '/' ) ) {
					continue 2;
				}
			}
			$collapsed[] = $relative;
		}

		return $collapsed;
	}

	public function get_partial_export_entries( array $paths ) {
		$relative_paths = $this->normalize_partial_export_paths( $paths );
		if ( is_wp_error( $relative_paths ) ) {
			return $relative_paths;
		}

		if ( empty( $relative_paths ) ) {
			return new \WP_Error( 'ag_sync_bridge_partial_paths_empty', __( 'No valid partial push paths were provided.', 'ag-sync-bridge' ) );
		}

		$entries = array();
		foreach ( $relative_paths as $relative ) {
			$source = normalize_path( ABSPATH . $relative );
			$type   = is_dir( $source ) ? 'directory' : 'file';

			$entries[] = array(
				'component'    => 'partial:' . $relative,
				'source'       => $source,
				'archive'      => $this->get_partial_archive_path( $relative ),
				'type'         => $type,
				'partial_path' => $relative,
				'partial_type' => $type,
			);
		}

		return $entries;
	}

	public function get_snapshot_integrity_for_export( array $entries ) {
		$included_root_files = array();

		foreach ( $entries as $entry ) {
			$archive = (string) array_get( $entry, 'archive', '' );
			if ( 0 === strpos( $archive, 'files/root/' ) ) {
				$included_root_files[] = basename( $archive );
			}
		}

		$included_root_files = array_values( array_unique( array_filter( $included_root_files ) ) );
		natcasesort( $included_root_files );
		$included_root_files = array_values( $included_root_files );

		$sitemap_integrity = $this->get_root_sitemap_integrity( ABSPATH, $included_root_files );

		return array(
			'root_sync_files'   => $included_root_files,
			'sitemap_integrity' => $sitemap_integrity,
		);
	}

	public function get_root_sitemap_integrity( $root_dir, array $included_root_files = null ) {
		$root_dir = rtrim( normalize_path( $root_dir ), '/\\' );

		if ( null === $included_root_files ) {
			$included_root_files = array_keys( $this->get_root_sync_files( $root_dir ) );
		}

		$included_root_files = array_values( array_unique( array_map( 'basename', $included_root_files ) ) );
		$required_files      = $this->get_required_root_sitemap_files( $root_dir );
		$missing_files       = array_values( array_diff( $required_files, $included_root_files ) );

		return array(
			'ok'                          => empty( $missing_files ),
			'root_dir'                    => $root_dir,
			'included_root_files'         => $included_root_files,
			'required_root_sitemap_files' => $required_files,
			'missing_root_sitemap_files'  => $missing_files,
		);
	}

	public function validate_full_snapshot_package( $package_path ) {
		$manifest = $this->read_package_manifest( $package_path );

		if ( empty( $manifest ) ) {
			return new \WP_Error(
				'ag_sync_bridge_snapshot_manifest_missing',
				__( 'Snapshot cannot be reused for push because manifest.json is missing or unreadable.', 'ag-sync-bridge' )
			);
		}

		$validation = $this->validate_full_snapshot_manifest( $manifest );

		if ( empty( $validation['ok'] ) ) {
			return new \WP_Error(
				'ag_sync_bridge_snapshot_not_full',
				__( 'Snapshot cannot be reused for push because it is not marked as a complete AG Sync snapshot.', 'ag-sync-bridge' ),
				$validation
			);
		}

		return $validation;
	}

	public function validate_full_snapshot_manifest( array $manifest ) {
		$errors     = array();
		$scope      = $this->get_snapshot_scope_from_manifest( $manifest );
		$components = array_get( $manifest, 'components', array() );
		$entries    = array_get( $manifest, 'entries_included', array() );

		$components = is_array( $components ) ? array_keys( $components ) : array();
		$entries    = is_array( $entries ) ? $entries : array();
		$present    = array_values( array_unique( array_filter( array_merge( $components, $entries ) ) ) );

		if ( 'full' !== $scope ) {
			$errors[] = 'snapshot_scope_not_full';
		}

		if ( empty( $manifest['database'] ) || empty( $manifest['database']['filename'] ) ) {
			$errors[] = 'database_missing';
		}

		$requirements       = array_get( $manifest, 'full_snapshot_requirements', array() );
		$required_components = array_get(
			is_array( $requirements ) ? $requirements : array(),
			'required_components',
			array( 'uploads', 'mpg-uploads', 'plugins', 'themes', 'wp-config.php' )
		);
		$required_components = is_array( $required_components ) ? $required_components : array();

		foreach ( $required_components as $component ) {
			if ( ! in_array( $component, $present, true ) ) {
				$errors[] = 'component_missing:' . $component;
			}
		}

		$sitemap_integrity = array_get( $manifest, 'sitemap_integrity', array() );
		if ( is_array( $sitemap_integrity ) ) {
			foreach ( array_get( $sitemap_integrity, 'missing_root_sitemap_files', array() ) as $missing_file ) {
				$errors[] = 'root_sitemap_missing:' . $missing_file;
			}
		}

		return array(
			'ok'                  => empty( $errors ),
			'snapshot_scope'      => $scope ?: 'unknown',
			'errors'              => array_values( array_unique( $errors ) ),
			'components_present'  => $present,
			'sitemap_integrity'   => is_array( $sitemap_integrity ) ? $sitemap_integrity : array(),
		);
	}

	public function get_snapshot_scope_from_manifest( array $manifest ) {
		$scope = (string) array_get( $manifest, 'snapshot_scope', '' );
		if ( '' === $scope ) {
			$scope = (string) array_get( $manifest, 'scope', '' );
		}

		if ( '' === $scope && ! empty( $manifest['is_full_snapshot'] ) ) {
			$scope = 'full';
		}

		return sanitize_key( $scope );
	}

	public function should_exclude( $path ) {
		$path      = normalize_path( $path );
		$relative  = ltrim( str_replace( normalize_path( ABSPATH ), '', $path ), '/' );
		$relative  = ltrim( $relative, '/' );
		$basename  = basename( $relative );
		$patterns  = $this->config->get_exclude_patterns();
		$candidate = strtolower( $relative );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( normalize_path( $pattern ) );
			$pattern = ltrim( $pattern, '/' );

			if ( '' === $pattern ) {
				continue;
			}

			$pattern_lc = strtolower( $pattern );

			if ( fnmatch( $pattern_lc, $candidate ) || fnmatch( $pattern_lc, strtolower( $basename ) ) ) {
				return true;
			}

			if ( false !== strpos( $pattern_lc, '/*' ) ) {
				$prefix = substr( $pattern_lc, 0, -1 );
				if ( 0 === strpos( $candidate, rtrim( $prefix, '/' ) . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public function replace_directory( $source_dir, $target_dir, array $preserve_root_items = array() ) {
		if ( ! file_exists( $source_dir ) ) {
			return true;
		}

		$source_dir = normalize_path( $source_dir );
		$target_dir = normalize_path( $target_dir );

		if ( ! ensure_directory( $target_dir ) ) {
			return new \WP_Error( 'ag_sync_bridge_target_dir_failed', __( 'Unable to prepare target directory.', 'ag-sync-bridge' ) );
		}

		$existing = scandir( $target_dir );
		$existing = is_array( $existing ) ? $existing : array();

		foreach ( $existing as $item ) {
			if ( in_array( $item, array( '.', '..' ), true ) || in_array( $item, $preserve_root_items, true ) ) {
				continue;
			}

			$this->cleanup_path( $target_dir . '/' . $item );
		}

		return $this->copy_directory_contents( $source_dir, $target_dir, $preserve_root_items );
	}

	public function copy_directory_contents( $source_dir, $target_dir, array $preserve_root_items = array() ) {
		$source_dir = normalize_path( $source_dir );
		$target_dir = normalize_path( $target_dir );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/** @var SplFileInfo $item */
		foreach ( $iterator as $item ) {
			$source_path = normalize_path( $item->getPathname() );
			$relative    = ltrim( str_replace( $source_dir, '', $source_path ), '/' );
			$first_part  = strtok( $relative, '/' );

			if ( $first_part && in_array( $first_part, $preserve_root_items, true ) ) {
				continue;
			}

			$target_path = normalize_path( $target_dir . '/' . $relative );

			if ( $item->isDir() ) {
				ensure_directory( $target_path );
				continue;
			}

			ensure_directory( dirname( $target_path ) );
			copy( $source_path, $target_path );
		}

		return true;
	}

	public function copy_file( $source_path, $target_path ) {
		if ( ! file_exists( $source_path ) ) {
			return true;
		}

		ensure_directory( dirname( $target_path ) );
		return copy( $source_path, $target_path );
	}

	public function copy_text_file_with_replacements( $source_path, $target_path, array $replacements = array() ) {
		if ( ! file_exists( $source_path ) ) {
			return true;
		}

		$content = (string) file_get_contents( $source_path );

		if ( ! empty( $replacements ) ) {
			$content = strtr( $content, $replacements );
		}

		ensure_directory( dirname( $target_path ) );
		return false !== file_put_contents( $target_path, $content, LOCK_EX );
	}

	public function sync_root_text_files( $source_root, $target_root, array $replacements = array() ) {
		$source_files = $this->get_root_sync_files( $source_root );
		$target_files = $this->get_root_sync_files( $target_root );

		foreach ( $target_files as $basename => $target_path ) {
			if ( isset( $source_files[ $basename ] ) ) {
				continue;
			}

			$this->cleanup_path( $target_path );
		}

		foreach ( $source_files as $basename => $source_path ) {
			$result = $this->copy_text_file_with_replacements(
				$source_path,
				normalize_path( rtrim( $target_root, '/\\' ) . '/' . $basename ),
				$replacements
			);

			if ( false === $result ) {
				return new \WP_Error(
					'ag_sync_bridge_root_text_sync_failed',
					sprintf(
						/* translators: %s: file basename */
						__( 'Unable to synchronize root text file: %s', 'ag-sync-bridge' ),
						$basename
					)
				);
			}
		}

		return true;
	}

	public function replace_urls_in_dataset_files( $target_dir, array $replacements = array() ) {
		$summary = array(
			'files_scanned'   => 0,
			'files_updated'   => 0,
			'entries_updated' => 0,
		);

		if ( empty( $replacements ) || ! is_dir( $target_dir ) ) {
			return $summary;
		}

		$target_dir = normalize_path( $target_dir );
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		/** @var SplFileInfo $item */
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path = normalize_path( $item->getPathname() );
			$summary['files_scanned']++;

			$result = $this->replace_urls_in_dataset_file( $path, $replacements );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $result['updated'] ) ) {
				$summary['files_updated']++;
				$summary['entries_updated'] += (int) array_get( $result, 'entries_updated', 0 );
			}
		}

		if ( $summary['files_updated'] > 0 ) {
			$this->logger->info( 'Dataset URLs replaced after file import.', $summary );
		}

		return $summary;
	}

	private function replace_urls_in_dataset_file( $path, array $replacements ) {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'xlsx' === $extension ) {
			return $this->replace_urls_in_xlsx_file( $path, $replacements );
		}

		if ( in_array( $extension, array( 'csv', 'tsv', 'txt', 'json', 'xml', 'html', 'htm' ), true ) ) {
			return $this->replace_urls_in_plain_dataset_file( $path, $replacements );
		}

		return array(
			'updated'         => false,
			'entries_updated' => 0,
		);
	}

	private function replace_urls_in_plain_dataset_file( $path, array $replacements ) {
		$content = file_get_contents( $path );

		if ( false === $content ) {
			return new \WP_Error(
				'ag_sync_bridge_dataset_read_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to read dataset file for URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		$updated = strtr( $content, $replacements );
		if ( $updated === $content ) {
			return array(
				'updated'         => false,
				'entries_updated' => 0,
			);
		}

		if ( false === file_put_contents( $path, $updated, LOCK_EX ) ) {
			return new \WP_Error(
				'ag_sync_bridge_dataset_write_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to write dataset file after URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		return array(
			'updated'         => true,
			'entries_updated' => 1,
		);
	}

	private function replace_urls_in_xlsx_file( $path, array $replacements ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
		}

		$temp_path = $path . '.ag-sync-url-replace-' . wp_generate_password( 8, false, false ) . '.tmp';
		if ( ! copy( $path, $temp_path ) ) {
			return new \WP_Error(
				'ag_sync_bridge_xlsx_copy_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to prepare XLSX dataset for URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_path ) ) {
			$this->cleanup_path( $temp_path );
			return new \WP_Error(
				'ag_sync_bridge_xlsx_open_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to open XLSX dataset for URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		$entries_updated = 0;
		$entry_temp_paths = array();

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			$name = is_array( $stat ) ? (string) array_get( $stat, 'name', '' ) : '';

			if ( ! $this->should_replace_xlsx_entry_urls( $name ) ) {
				continue;
			}

			$content = $zip->getFromIndex( $index );
			if ( ! is_string( $content ) ) {
				continue;
			}

			if ( ! $this->contains_replacement_source( $content, $replacements ) ) {
				unset( $content );
				continue;
			}

			$updated = strtr( $content, $replacements );
			$changed = ( $updated !== $content );
			unset( $content );

			if ( ! $changed ) {
				unset( $updated );
				continue;
			}

			$entry_temp_path = $temp_path . '.entry-' . $entries_updated . '.tmp';
			if ( false === file_put_contents( $entry_temp_path, $updated, LOCK_EX ) ) {
				unset( $updated );
				$zip->close();
				$this->cleanup_path( $temp_path );
				$this->cleanup_dataset_temp_paths( $entry_temp_paths );
				return new \WP_Error(
					'ag_sync_bridge_xlsx_entry_temp_write_failed',
					sprintf(
						/* translators: %s: file path */
						__( 'Unable to prepare XLSX dataset entry during URL replacement: %s', 'ag-sync-bridge' ),
						$path
					)
				);
			}

			unset( $updated );
			$entry_temp_paths[] = $entry_temp_path;

			if ( ! $zip->addFile( $entry_temp_path, $name ) ) {
				$zip->close();
				$this->cleanup_path( $temp_path );
				$this->cleanup_dataset_temp_paths( $entry_temp_paths );
				return new \WP_Error(
					'ag_sync_bridge_xlsx_entry_write_failed',
					sprintf(
						/* translators: %s: file path */
						__( 'Unable to update XLSX dataset entry during URL replacement: %s', 'ag-sync-bridge' ),
						$path
					)
				);
			}

			$entries_updated++;
		}

		if ( false === $zip->close() ) {
			$this->cleanup_path( $temp_path );
			$this->cleanup_dataset_temp_paths( $entry_temp_paths );
			return new \WP_Error(
				'ag_sync_bridge_xlsx_close_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to finalize XLSX dataset after URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		if ( 0 === $entries_updated ) {
			$this->cleanup_path( $temp_path );
			$this->cleanup_dataset_temp_paths( $entry_temp_paths );
			return array(
				'updated'         => false,
				'entries_updated' => 0,
			);
		}

		if ( ! copy( $temp_path, $path ) ) {
			$this->cleanup_path( $temp_path );
			$this->cleanup_dataset_temp_paths( $entry_temp_paths );
			return new \WP_Error(
				'ag_sync_bridge_xlsx_replace_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Unable to replace XLSX dataset after URL replacement: %s', 'ag-sync-bridge' ),
					$path
				)
			);
		}

		$this->cleanup_path( $temp_path );
		$this->cleanup_dataset_temp_paths( $entry_temp_paths );

		return array(
			'updated'         => true,
			'entries_updated' => $entries_updated,
		);
	}

	private function contains_replacement_source( $content, array $replacements ) {
		foreach ( $replacements as $source => $target ) {
			if ( '' !== (string) $source && false !== strpos( $content, (string) $source ) ) {
				return true;
			}
		}

		return false;
	}

	private function cleanup_dataset_temp_paths( array $paths ) {
		foreach ( $paths as $path ) {
			$this->cleanup_path( $path );
		}
	}

	private function should_replace_xlsx_entry_urls( $name ) {
		$name = strtolower( (string) $name );

		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'xml', 'rels', 'txt', 'csv' ), true );
	}

	public function merge_wp_config( $source_path, $target_path ) {
		if ( ! file_exists( $source_path ) ) {
			return true;
		}

		if ( ! file_exists( $target_path ) ) {
			return $this->copy_file( $source_path, $target_path );
		}

		$source = (string) file_get_contents( $source_path );
		$target = (string) file_get_contents( $target_path );

		$target_defines = $this->extract_wp_config_defines( $target );
		foreach ( $target_defines as $name => $value ) {
			if ( $this->should_preserve_wp_config_constant( $name ) ) {
				$source = $this->replace_or_inject_define( $source, $name, $value );
			}
		}

		$table_prefix = $this->extract_table_prefix( $target );
		if ( $table_prefix ) {
			$source = preg_replace(
				'/\$table_prefix\s*=\s*[\'"][^\'"]+[\'"]\s*;/',
				'$table_prefix = ' . var_export( $table_prefix, true ) . ';',
				$source,
				1
			);
		}

		return false !== file_put_contents( $target_path, $source, LOCK_EX );
	}

	private function should_preserve_wp_config_constant( $name ) {
		$preserve = array(
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'DB_HOST',
			'DB_CHARSET',
			'DB_COLLATE',
			'WP_HOME',
			'WP_SITEURL',
			'WP_CONTENT_DIR',
			'WP_CONTENT_URL',
			'WP_DEBUG',
			'WP_DEBUG_LOG',
			'WP_DEBUG_DISPLAY',
		);

		return in_array( $name, $preserve, true ) || 0 === strpos( $name, 'AG_SYNC_BRIDGE_' );
	}

	private function get_root_sync_files( $root_dir ) {
		$root_dir = rtrim( normalize_path( $root_dir ), '/\\' );
		$files    = array();
		$robots   = $root_dir . '/robots.txt';

		if ( is_file( $robots ) ) {
			$files['robots.txt'] = normalize_path( $robots );
		}

		$xml_files = glob( $root_dir . '/*.xml' );
		$xml_files = is_array( $xml_files ) ? $xml_files : array();

		natcasesort( $xml_files );

		foreach ( $xml_files as $xml_file ) {
			if ( is_file( $xml_file ) ) {
				$files[ basename( $xml_file ) ] = normalize_path( $xml_file );
			}
		}

		return $files;
	}

	private function normalize_partial_export_path( $path ) {
		$relative = trim( str_replace( '\\', '/', (string) $path ) );
		$relative = preg_replace( '#/+#', '/', $relative );
		$relative = ltrim( $relative, '/' );
		$relative = rtrim( $relative, '/' );

		if ( '' === $relative ) {
			return '';
		}

		if ( false !== strpos( $relative, "\0" ) || preg_match( '#^[a-zA-Z]:/#', $relative ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_unsafe',
				sprintf(
					/* translators: %s: requested path */
					__( 'Partial push path is unsafe or absolute: %s', 'ag-sync-bridge' ),
					(string) $path
				)
			);
		}

		$relative_lc = strtolower( $relative );

		if ( 'wp-config.php' === $relative_lc || 0 === strpos( $relative_lc, 'wp-admin/' ) || 0 === strpos( $relative_lc, 'wp-includes/' ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_forbidden',
				sprintf(
					/* translators: %s: requested path */
					__( 'Partial push cannot include WordPress core files or wp-config.php: %s', 'ag-sync-bridge' ),
					$relative
				)
			);
		}

		if ( ! $this->is_allowed_partial_root_path( $relative ) && 0 !== strpos( $relative_lc, 'wp-content/' ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_not_supported',
				sprintf(
					/* translators: %s: requested path */
					__( 'Partial push supports wp-content paths plus root robots.txt/XML files only: %s', 'ag-sync-bridge' ),
					$relative
				)
			);
		}

		$plugin_dir = dirname( $this->config->get_plugin_basename() );
		$plugin_dir = '.' === $plugin_dir ? 'ag-sync-bridge' : trim( str_replace( '\\', '/', $plugin_dir ), '/' );
		$plugin_rel = strtolower( 'wp-content/plugins/' . $plugin_dir );

		if ( $relative_lc === $plugin_rel || 0 === strpos( $relative_lc, $plugin_rel . '/' ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_plugin_self',
				__( 'Partial push cannot update AG Sync Bridge itself. Release and install the plugin update instead.', 'ag-sync-bridge' )
			);
		}

		$absolute = normalize_path( ABSPATH . $relative );
		$root     = rtrim( normalize_path( ABSPATH ), '/' );

		if ( 0 !== strpos( $absolute . '/', $root . '/' ) ) {
			return new \WP_Error( 'ag_sync_bridge_partial_path_outside_root', __( 'Partial push path resolves outside the WordPress root.', 'ag-sync-bridge' ) );
		}

		if ( ! file_exists( $absolute ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_missing',
				sprintf(
					/* translators: %s: requested path */
					__( 'Partial push path does not exist locally: %s', 'ag-sync-bridge' ),
					$relative
				)
			);
		}

		if ( $this->should_exclude( $absolute ) ) {
			return new \WP_Error(
				'ag_sync_bridge_partial_path_excluded',
				sprintf(
					/* translators: %s: requested path */
					__( 'Partial push path is excluded by AG Sync settings: %s', 'ag-sync-bridge' ),
					$relative
				)
			);
		}

		return $relative;
	}

	private function is_allowed_partial_root_path( $relative ) {
		if ( false !== strpos( $relative, '/' ) ) {
			return false;
		}

		$basename = strtolower( basename( $relative ) );
		return 'robots.txt' === $basename || (bool) preg_match( '/\.xml$/i', $basename );
	}

	private function get_partial_archive_path( $relative ) {
		$relative = trim( str_replace( '\\', '/', (string) $relative ), '/' );

		if ( false === strpos( $relative, '/' ) ) {
			return 'files/root/' . basename( $relative );
		}

		return 'files/' . $relative;
	}

	private function get_required_root_sitemap_files( $root_dir ) {
		$root_dir = rtrim( normalize_path( $root_dir ), '/\\' );
		$files    = array();
		$index    = $root_dir . '/sitemap_index.xml';

		if ( is_file( $index ) ) {
			foreach ( $this->extract_sitemap_locs_from_file( $index ) as $loc ) {
				$basename = $this->root_xml_basename_from_url( $loc );
				if ( $basename ) {
					$files[] = $basename;
				}
			}
		}

		foreach ( $this->get_mpg_project_sitemap_files() as $basename ) {
			$files[] = $basename;
		}

		$files = array_values( array_unique( array_filter( $files ) ) );
		natcasesort( $files );

		return array_values( $files );
	}

	private function extract_sitemap_locs_from_file( $path ) {
		$content = (string) file_get_contents( $path );
		$locs    = array();

		if ( '' === trim( $content ) ) {
			return $locs;
		}

		if ( function_exists( 'simplexml_load_string' ) ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $content );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( $xml ) {
				$nodes = $xml->xpath( '//*[local-name()="loc"]' );
				if ( is_array( $nodes ) ) {
					foreach ( $nodes as $node ) {
						$locs[] = trim( (string) $node );
					}
				}
			}
		}

		if ( empty( $locs ) && preg_match_all( '#<loc>\s*([^<]+)\s*</loc>#i', $content, $matches ) ) {
			$locs = array_map( 'trim', $matches[1] );
		}

		return array_values( array_filter( $locs ) );
	}

	private function get_mpg_project_sitemap_files() {
		global $wpdb;

		if ( empty( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'mpg_projects';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $found !== $table ) {
			return array();
		}

		$table_sql = str_replace( '`', '``', $table );
		$values    = $wpdb->get_col( "SELECT sitemap_url FROM `{$table_sql}` WHERE sitemap_url <> ''" );
		$values    = is_array( $values ) ? $values : array();
		$files     = array();

		foreach ( $values as $value ) {
			$basename = $this->root_xml_basename_from_url( $value );
			if ( $basename ) {
				$files[] = $basename;
			}
		}

		$files = array_values( array_unique( $files ) );
		natcasesort( $files );

		return array_values( $files );
	}

	private function root_xml_basename_from_url( $value ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value ) {
			return '';
		}

		$path = wp_parse_url( $value, PHP_URL_PATH );
		$path = $path ? $path : $value;
		$name = basename( strtok( $path, '?' ) );

		if ( ! preg_match( '/^[a-zA-Z0-9._-]+\.xml$/', $name ) ) {
			return '';
		}

		return $name;
	}

	private function extract_wp_config_defines( $content ) {
		$defines = array();

		if ( preg_match_all( '/define\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*\)\s*;/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name  = $match[1];
				$value = trim( $match[2] );
				$value = $this->evaluate_php_literal( $value );

				if ( null !== $value ) {
					$defines[ $name ] = $value;
				}
			}
		}

		return $defines;
	}

	private function extract_table_prefix( $content ) {
		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	private function evaluate_php_literal( $literal ) {
		$literal = trim( (string) $literal );

		if ( preg_match( '/^[\'"](.*)[\'"]$/s', $literal, $matches ) ) {
			return stripcslashes( $matches[1] );
		}

		if ( 'true' === strtolower( $literal ) ) {
			return true;
		}

		if ( 'false' === strtolower( $literal ) ) {
			return false;
		}

		if ( is_numeric( $literal ) ) {
			return false !== strpos( $literal, '.' ) ? (float) $literal : (int) $literal;
		}

		return null;
	}

	private function replace_or_inject_define( $content, $name, $value ) {
		$replacement = "define( '" . $name . "', " . var_export( $value, true ) . ' );';
		$pattern     = '/define\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]\s*,\s*.+?\)\s*;/';

		if ( preg_match( $pattern, $content ) ) {
			return preg_replace( $pattern, $replacement, $content, 1 );
		}

		$needle = "/* That's all, stop editing! Happy publishing. */";

		if ( false !== strpos( $content, $needle ) ) {
			return str_replace( $needle, $replacement . "\n\n" . $needle, $content );
		}

		return $content . "\n" . $replacement . "\n";
	}

	private function write_protection_files( $dir ) {
		$index_file = normalize_path( $dir . '/index.php' );
		$htaccess   = normalize_path( $dir . '/.htaccess' );

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
		}
	}

	private function scan_zip_directory( $dir, $limit = 50 ) {
		$dir = $this->normalize_manual_path( $dir );

		if ( ! $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$items = glob( $dir . '/*.zip' );
		$items = is_array( $items ) ? $items : array();

		usort(
			$items,
			static function ( $left, $right ) {
				return filemtime( $right ) <=> filemtime( $left );
			}
		);

		$list = array();

		foreach ( array_slice( $items, 0, absint( $limit ) ) as $package_path ) {
			$list[] = $this->build_package_metadata( $package_path, false );
		}

		return $list;
	}

	private function build_package_metadata( $package_path, $include_hash = false ) {
		$package_path = normalize_path( $package_path );
		$meta         = array(
			'basename'   => basename( $package_path ),
			'path'       => $package_path,
			'size_bytes' => file_exists( $package_path ) ? filesize( $package_path ) : 0,
			'created_at' => file_exists( $package_path ) ? gmdate( 'c', (int) filemtime( $package_path ) ) : '',
		);

		$legacy_meta = $this->read_json_file( $this->get_meta_path_for_package( $package_path ) );
		if ( ! empty( $legacy_meta ) ) {
			unset( $legacy_meta['meta_path'] );
			$meta = array_merge( $meta, $legacy_meta );
		}

		$manifest = $this->read_package_manifest( $package_path );
		if ( ! empty( $manifest ) ) {
			$meta['manifest'] = $manifest;
			$meta['snapshot_scope'] = $this->get_snapshot_scope_from_manifest( $manifest );
			$meta['is_full_snapshot'] = 'full' === $meta['snapshot_scope'];
			$meta['full_snapshot_validation'] = $this->validate_full_snapshot_manifest( $manifest );

			if ( ! empty( $manifest['type'] ) ) {
				$meta['type'] = $manifest['type'];
			}

			if ( ! empty( $manifest['created_at'] ) ) {
				$meta['created_at'] = $manifest['created_at'];
			}
		}

		if ( $include_hash && empty( $meta['sha256'] ) && file_exists( $package_path ) ) {
			$meta['sha256'] = hash_file( 'sha256', $package_path );
		}

		unset( $meta['meta_path'] );

		return $meta;
	}

	private function read_package_manifest( $package_path ) {
		if ( ! file_exists( $package_path ) || ! class_exists( 'ZipArchive' ) ) {
			return array();
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $package_path ) ) {
			return array();
		}

		$manifest = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( ! is_string( $manifest ) || '' === $manifest ) {
			return array();
		}

		$data = json_decode( $manifest, true );
		return is_array( $data ) ? $data : array();
	}

	private function normalize_manual_path( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		if ( ! path_is_absolute( $path ) ) {
			$path = ABSPATH . ltrim( str_replace( '\\', '/', $path ), '/' );
		}

		return normalize_path( $path );
	}

	private function resolve_manual_backup_path( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		if ( is_file( $path ) ) {
			return 'zip' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ? $path : '';
		}

		if ( is_dir( $path ) ) {
			$matches = $this->scan_zip_directory( $path, 1 );
			return empty( $matches ) ? '' : array_get( $matches[0], 'path', '' );
		}

		return '';
	}
}
