<?php
namespace AGSyncBridge;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Archive_Service {
	/*
	 * Hard extraction ceilings. They are intentionally generous enough for
	 * normal full-site snapshots while bounding central-directory and expanded
	 * data work before any archive member is written.
	 */
	const MAX_ARCHIVE_BYTES = 10737418240; // 10 GiB compressed.
	const MAX_ARCHIVE_ENTRIES = 200000;
	const MAX_ARCHIVE_ENTRY_BYTES = 5368709120; // 5 GiB per member.
	const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 21474836480; // 20 GiB expanded.
	const MAX_MANIFEST_BYTES = 1048576; // 1 MiB.

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

	public function create_package( $package_path, $database_path, array $manifest, array $entries, callable $exclude_callback, $progress_callback = null, $cancellation_check = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
		}
		if ( $this->is_cancel_requested( $cancellation_check, 'archive-prepare' ) ) {
			return $this->cancellation_error( 'archive-prepare' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $package_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_create', __( 'Unable to create snapshot archive.', 'ag-sync-bridge' ) );
		}

		$database_path = (string) $database_path;
		if ( $database_path && file_exists( $database_path ) ) {
			$manifest['database'] = array(
				'filename'    => 'database.sql',
				'size_bytes'  => filesize( $database_path ),
				'sha256'      => hash_file( 'sha256', $database_path ),
				'exported_at' => gmdate( 'c' ),
			);
			$zip->addFile( $database_path, 'database.sql' );
		} else {
			$manifest['database'] = array(
				'included' => false,
			);
		}
		$manifest['components'] = array();
		$entry_count            = max( 1, count( $entries ) );
		$entry_index            = 0;

		foreach ( $entries as $entry ) {
			if ( $this->is_cancel_requested( $cancellation_check, 'archive-component' ) ) {
				$zip->close();
				@unlink( $package_path );
				return $this->cancellation_error( 'archive-component' );
			}
			$is_partial_entry = array_key_exists( 'partial_path', $entry );
			if ( 'directory' === $entry['type'] ) {
				$summary = $this->add_directory( $zip, $entry['source'], $entry['archive'], $entry['component'], $exclude_callback, $progress_callback, $cancellation_check, $is_partial_entry );
			} else {
				$summary = $this->add_file( $zip, $entry['source'], $entry['archive'], $entry['component'], $exclude_callback, $is_partial_entry );
			}

			if ( is_wp_error( $summary ) ) {
				$zip->close();
				@unlink( $package_path );
				return $summary;
			}

			$manifest['components'][ $entry['component'] ] = $summary;
			$entry_index++;
			$this->report_progress(
				$progress_callback,
				'archive-component',
				(int) round( ( $entry_index / $entry_count ) * 100 ),
				array( 'component' => $entry['component'], 'components_complete' => $entry_index, 'components_total' => $entry_count )
			);
		}

		if ( $this->is_cancel_requested( $cancellation_check, 'archive-finalize' ) ) {
			$zip->close();
			@unlink( $package_path );
			return $this->cancellation_error( 'archive-finalize' );
		}

		$manifest['created_with'] = array(
			'plugin_version' => AG_SYNC_BRIDGE_VERSION,
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
		);

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();

		return array(
			'package_path' => normalize_path( $package_path ),
			'size_bytes'   => filesize( $package_path ),
			'sha256'       => hash_file( 'sha256', $package_path ),
			'manifest'     => $manifest,
		);
	}

	public function inspect_package( $package_path, array $options = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
		}

		$zip = new ZipArchive();
		$open_result = $zip->open( $package_path );
		if ( true !== $open_result ) {
			return new WP_Error(
				'ag_sync_bridge_zip_open',
				__( 'Unable to open snapshot archive.', 'ag-sync-bridge' ),
				array(
					'package' => normalize_path( $package_path ),
					'status'  => $open_result,
				)
			);
		}

		$result = $this->inspect_open_archive( $zip, $package_path, $options );
		$zip->close();
		return $result;
	}

	public function extract_package( $package_path, $target_dir, $progress_callback = null, array $options = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
		}

		$zip = new ZipArchive();
		$open_result = $zip->open( $package_path );
		if ( true !== $open_result ) {
			return new WP_Error(
				'ag_sync_bridge_zip_open',
				__( 'Unable to open snapshot archive.', 'ag-sync-bridge' ),
				array(
					'package' => normalize_path( $package_path ),
					'status'  => $open_result,
				)
			);
		}

		$inspection = $this->inspect_open_archive( $zip, $package_path, $options );
		if ( is_wp_error( $inspection ) ) {
			$zip->close();
			return $inspection;
		}

		if ( ! ensure_directory( $target_dir ) ) {
			$zip->close();
			return new WP_Error(
				'ag_sync_bridge_zip_extract_target',
				__( 'Unable to prepare snapshot extraction directory.', 'ag-sync-bridge' ),
				array(
					'package'    => normalize_path( $package_path ),
					'target_dir' => normalize_path( $target_dir ),
				)
			);
		}

		$result = $this->extract_entries( $zip, $package_path, $target_dir, $progress_callback, $inspection );
		$status = $zip->status;

		$zip->close();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 0 !== (int) $status ) {
			return new WP_Error(
				'ag_sync_bridge_zip_extract_status',
				__( 'Snapshot archive extraction completed with a ZIP status error.', 'ag-sync-bridge' ),
				array(
					'package'    => normalize_path( $package_path ),
					'target_dir' => normalize_path( $target_dir ),
					'zip_status' => $status,
				)
			);
		}

		return true;
	}

	private function inspect_open_archive( ZipArchive $zip, $package_path, array $options = array() ) {
		$limits       = $this->get_archive_limits( $options );
		$package_size = file_exists( $package_path ) ? (float) filesize( $package_path ) : 0;
		$entry_count  = (int) $zip->numFiles;

		if ( $package_size <= 0 || $package_size > $limits['archive_bytes'] ) {
			return new WP_Error(
				'ag_sync_bridge_zip_package_size',
				__( 'Snapshot archive compressed size exceeds the documented safety limit.', 'ag-sync-bridge' ),
				array(
					'size_bytes' => $package_size,
					'max_bytes'  => $limits['archive_bytes'],
				)
			);
		}

		if ( $entry_count <= 0 || $entry_count > $limits['entries'] ) {
			return new WP_Error(
				'ag_sync_bridge_zip_entry_limit',
				__( 'Snapshot archive contains an unsafe number of entries.', 'ag-sync-bridge' ),
				array(
					'entry_count' => $entry_count,
					'max_entries' => $limits['entries'],
				)
			);
		}

		$entries            = array();
		$entry_keys         = array();
		$total_uncompressed = 0.0;
		$manifest_index     = null;

		for ( $index = 0; $index < $entry_count; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) || ! isset( $stat['name'] ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_entry_stat',
					__( 'Unable to read snapshot archive entry metadata.', 'ag-sync-bridge' ),
					array(
						'package' => normalize_path( $package_path ),
						'index'   => $index,
					)
				);
			}

			$normalized = $this->normalize_zip_inventory_name( (string) $stat['name'] );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$collision_key = $this->zip_inventory_key( $normalized['canonical'] );
			if ( isset( $entry_keys[ $collision_key ] ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_entry_duplicate',
					__( 'Snapshot archive contains duplicate or case-colliding entry paths.', 'ag-sync-bridge' ),
					array( 'entry' => $normalized['canonical'] )
				);
			}
			$entry_keys[ $collision_key ] = $index;

			$size = isset( $stat['size'] ) ? (float) $stat['size'] : -1;
			if ( $size < 0 || $size > $limits['entry_bytes'] ) {
				return new WP_Error(
					'ag_sync_bridge_zip_entry_size',
					__( 'Snapshot archive entry exceeds the documented per-entry safety limit.', 'ag-sync-bridge' ),
					array(
						'entry'      => $normalized['canonical'],
						'size_bytes' => $size,
						'max_bytes'  => $limits['entry_bytes'],
					)
				);
			}

			$total_uncompressed += $size;
			if ( $total_uncompressed > $limits['uncompressed_bytes'] ) {
				return new WP_Error(
					'ag_sync_bridge_zip_expansion_limit',
					__( 'Snapshot archive expands beyond the documented safety limit.', 'ag-sync-bridge' ),
					array(
						'uncompressed_bytes' => $total_uncompressed,
						'max_bytes'          => $limits['uncompressed_bytes'],
					)
				);
			}

			if ( 'manifest.json' === $normalized['canonical'] ) {
				if ( $normalized['is_directory'] || $size > self::MAX_MANIFEST_BYTES ) {
					return new WP_Error( 'ag_sync_bridge_zip_manifest_size', __( 'Snapshot manifest is not a bounded regular file.', 'ag-sync-bridge' ) );
				}
				$manifest_index = $index;
			}

			$entries[ $index ] = array(
				'index'        => $index,
				'source_name'  => (string) $stat['name'],
				'name'         => $normalized['name'],
				'canonical'    => $normalized['canonical'],
				'is_directory' => $normalized['is_directory'],
				'size_bytes'   => $size,
			);
		}

		$tree_validation = $this->validate_zip_inventory_tree( $entries );
		if ( is_wp_error( $tree_validation ) ) {
			return $tree_validation;
		}

		if ( null === $manifest_index ) {
			return new WP_Error( 'ag_sync_bridge_zip_manifest_missing', __( 'Snapshot archive is missing manifest.json.', 'ag-sync-bridge' ) );
		}

		$manifest_json = $zip->getFromIndex( $manifest_index );
		$manifest      = is_string( $manifest_json ) ? json_decode( $manifest_json, true ) : null;
		unset( $manifest_json );

		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_manifest_invalid', __( 'Snapshot archive manifest is invalid.', 'ag-sync-bridge' ) );
		}

		$scope = $this->get_manifest_scope( $manifest );
		$expected_paths = isset( $options['expected_partial_paths'] ) && is_array( $options['expected_partial_paths'] )
			? array_values( $options['expected_partial_paths'] )
			: array();

		if ( ! empty( $expected_paths ) && 'partial' !== $scope ) {
			return new WP_Error( 'ag_sync_bridge_zip_partial_scope_mismatch', __( 'Expected a partial package but the archive declares a different scope.', 'ag-sync-bridge' ) );
		}

		if ( 'partial' === $scope ) {
			$partial_validation = $this->validate_partial_archive_inventory( $entries, $manifest, $expected_paths );
			if ( is_wp_error( $partial_validation ) ) {
				return $partial_validation;
			}
		}

		return array(
			'package_path'       => normalize_path( $package_path ),
			'archive_size_bytes' => $package_size,
			'entry_count'        => $entry_count,
			'uncompressed_bytes' => $total_uncompressed,
			'snapshot_scope'     => $scope,
			'manifest'           => $manifest,
			'entries'            => $entries,
			'limits'             => $limits,
		);
	}

	private function extract_entries( ZipArchive $zip, $package_path, $target_dir, $progress_callback, array $inspection ) {
		$last_report  = microtime( true );
		$total_written = 0.0;
		$entries      = $inspection['entries'];
		$limits       = $inspection['limits'];
		$entry_total  = count( $entries );

		foreach ( $entries as $index => $entry ) {
			$name   = $entry['name'];
			$target = $this->resolve_zip_entry_target( $target_dir, $name );

			if ( is_wp_error( $target ) ) {
				return $target;
			}

			if ( ! empty( $entry['is_directory'] ) ) {
				if ( ! ensure_directory( $target ) ) {
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_dir', $package_path, $target_dir, $name, $target );
				}
				continue;
			}

			if ( ! ensure_directory( dirname( $target ) ) ) {
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_parent', $package_path, $target_dir, $name, dirname( $target ) );
			}

			$source = $zip->getStream( $entry['source_name'] );
			if ( false === $source ) {
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_open', $package_path, $target_dir, $name, $target );
			}

			$destination = fopen( $target, 'wb' );
			if ( false === $destination ) {
				fclose( $source );
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_write_open', $package_path, $target_dir, $name, $target );
			}

			$entry_written = 0.0;
			while ( ! feof( $source ) ) {
				$buffer = fread( $source, 1048576 );
				if ( false === $buffer ) {
					fclose( $source );
					fclose( $destination );
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_read', $package_path, $target_dir, $name, $target );
				}

				$buffer_size = strlen( $buffer );
				if ( 0 === $buffer_size ) {
					continue;
				}

				$entry_written += $buffer_size;
				$total_written += $buffer_size;
				if ( $entry_written > $entry['size_bytes'] || $entry_written > $limits['entry_bytes'] || $total_written > $limits['uncompressed_bytes'] ) {
					fclose( $source );
					fclose( $destination );
					@unlink( $target );
					return new WP_Error(
						'ag_sync_bridge_zip_runtime_size_limit',
						__( 'Snapshot archive emitted more data than its validated inventory permits.', 'ag-sync-bridge' ),
						array( 'entry' => $name )
					);
				}

				$written = fwrite( $destination, $buffer );
				if ( false === $written || $buffer_size !== $written ) {
					fclose( $source );
					fclose( $destination );
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_write', $package_path, $target_dir, $name, $target );
				}
			}

			fclose( $source );
			fclose( $destination );

			if ( $entry_written !== (float) $entry['size_bytes'] ) {
				@unlink( $target );
				return new WP_Error(
					'ag_sync_bridge_zip_runtime_size_mismatch',
					__( 'Snapshot archive entry size changed between inventory validation and extraction.', 'ag-sync-bridge' ),
					array( 'entry' => $name )
				);
			}

			if ( 0 === ( (int) $index + 1 ) % 25 || ( microtime( true ) - $last_report ) >= 5 ) {
				$this->report_progress(
					$progress_callback,
					'package-extract',
					(int) round( ( ( (int) $index + 1 ) / max( 1, $entry_total ) ) * 100 ),
					array( 'entries_complete' => (int) $index + 1, 'entries_total' => $entry_total )
				);
				$last_report = microtime( true );
			}
		}

		return true;
	}

	private function get_archive_limits( array $options ) {
		return array(
			'archive_bytes'      => $this->bounded_archive_limit( $options, 'max_archive_bytes', self::MAX_ARCHIVE_BYTES ),
			'entries'            => (int) $this->bounded_archive_limit( $options, 'max_archive_entries', self::MAX_ARCHIVE_ENTRIES ),
			'entry_bytes'        => $this->bounded_archive_limit( $options, 'max_archive_entry_bytes', self::MAX_ARCHIVE_ENTRY_BYTES ),
			'uncompressed_bytes' => $this->bounded_archive_limit( $options, 'max_archive_uncompressed_bytes', self::MAX_ARCHIVE_UNCOMPRESSED_BYTES ),
		);
	}

	private function bounded_archive_limit( array $options, $key, $hard_limit ) {
		if ( ! isset( $options[ $key ] ) || ! is_numeric( $options[ $key ] ) || (float) $options[ $key ] <= 0 ) {
			return (float) $hard_limit;
		}

		return min( (float) $hard_limit, max( 1.0, (float) $options[ $key ] ) );
	}

	private function normalize_zip_inventory_name( $entry_name ) {
		$entry_name = str_replace( '\\', '/', (string) $entry_name );
		$is_directory = '/' === substr( $entry_name, -1 );

		if ( '' === $entry_name || false !== strpos( $entry_name, "\0" ) || false !== strpos( $entry_name, ':' ) || preg_match( '#^([a-zA-Z]:)?/#', $entry_name ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_entry_invalid',
				__( 'Snapshot archive contains an invalid entry path.', 'ag-sync-bridge' ),
				array( 'entry' => $entry_name )
			);
		}

		$canonical = rtrim( $entry_name, '/' );
		$parts     = explode( '/', $canonical );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return new WP_Error(
					'ag_sync_bridge_zip_entry_traversal',
					__( 'Snapshot archive contains an unsafe or non-canonical relative entry path.', 'ag-sync-bridge' ),
					array( 'entry' => $entry_name )
				);
			}
		}

		return array(
			'name'         => $canonical . ( $is_directory ? '/' : '' ),
			'canonical'    => $canonical,
			'is_directory' => $is_directory,
		);
	}

	private function validate_zip_inventory_tree( array $entries ) {
		$paths = array();
		foreach ( $entries as $entry ) {
			$paths[ $this->zip_inventory_key( $entry['canonical'] ) ] = ! empty( $entry['is_directory'] );
		}

		foreach ( $entries as $entry ) {
			$parts = explode( '/', $entry['canonical'] );
			array_pop( $parts );
			$ancestor = '';
			foreach ( $parts as $part ) {
				$ancestor = '' === $ancestor ? $part : $ancestor . '/' . $part;
				$key      = $this->zip_inventory_key( $ancestor );
				if ( isset( $paths[ $key ] ) && ! $paths[ $key ] ) {
					return new WP_Error(
						'ag_sync_bridge_zip_entry_tree_conflict',
						__( 'Snapshot archive uses a regular file as the parent of another entry.', 'ag-sync-bridge' ),
						array(
							'entry'  => $entry['canonical'],
							'parent' => $ancestor,
						)
					);
				}
			}
		}

		return true;
	}

	private function zip_inventory_key( $path ) {
		$path = (string) $path;
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function get_manifest_scope( array $manifest ) {
		$scope = isset( $manifest['snapshot_scope'] ) ? (string) $manifest['snapshot_scope'] : '';
		if ( '' === $scope && isset( $manifest['scope'] ) ) {
			$scope = (string) $manifest['scope'];
		}
		if ( '' === $scope && ! empty( $manifest['is_full_snapshot'] ) ) {
			$scope = 'full';
		}
		return sanitize_key( $scope );
	}

	private function validate_partial_archive_inventory( array $entries, array $manifest, array $expected_paths = array() ) {
		$declared_paths = isset( $manifest['partial_paths'] ) && is_array( $manifest['partial_paths'] )
			? array_values( $manifest['partial_paths'] )
			: array();
		$partial_entries = isset( $manifest['partial_entries'] ) && is_array( $manifest['partial_entries'] )
			? array_values( $manifest['partial_entries'] )
			: array();
		$database = isset( $manifest['database'] ) && is_array( $manifest['database'] ) ? $manifest['database'] : array();

		if ( empty( $declared_paths ) || empty( $partial_entries ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_partial_manifest_invalid', __( 'Partial snapshot manifest is missing its exact path inventory.', 'ag-sync-bridge' ) );
		}

		if ( ! empty( $expected_paths ) && $declared_paths !== $expected_paths ) {
			return new WP_Error(
				'ag_sync_bridge_zip_partial_paths_mismatch',
				__( 'Partial snapshot archive paths do not match the caller expectation.', 'ag-sync-bridge' ),
				array(
					'declared_paths' => $declared_paths,
					'expected_paths' => $expected_paths,
				)
			);
		}

		if ( ! empty( $database['filename'] ) || ! empty( $database['included'] ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_partial_database_forbidden', __( 'Partial snapshot archives cannot declare a database payload.', 'ag-sync-bridge' ) );
		}

		$rules       = array();
		$entry_paths = array();
		foreach ( $partial_entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				return new WP_Error( 'ag_sync_bridge_zip_partial_manifest_invalid', __( 'Partial snapshot contains invalid entry metadata.', 'ag-sync-bridge' ) );
			}

			$path    = isset( $entry['path'] ) ? (string) $entry['path'] : '';
			$type    = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			$exists  = array_key_exists( 'exists', $entry ) ? (bool) $entry['exists'] : 'missing' !== $type;
			$archive = isset( $entry['archive'] ) ? (string) $entry['archive'] : '';

			if ( '' === $path || in_array( $path, $entry_paths, true ) || ! in_array( $path, $declared_paths, true ) ) {
				return new WP_Error( 'ag_sync_bridge_zip_partial_manifest_invalid', __( 'Partial snapshot entry paths are missing, duplicated or outside partial_paths.', 'ag-sync-bridge' ) );
			}

			$expected_archive = false === strpos( $path, '/' )
				? 'files/root/' . basename( $path )
				: 'files/' . $path;
			$archive_name = $this->normalize_zip_inventory_name( $archive );
			if ( is_wp_error( $archive_name ) || $archive !== $archive_name['canonical'] || $archive !== $expected_archive ) {
				return new WP_Error(
					'ag_sync_bridge_zip_partial_manifest_invalid',
					__( 'Partial snapshot archive path does not match its declared WordPress path.', 'ag-sync-bridge' ),
					array( 'path' => $path )
				);
			}

			if ( ! in_array( $type, array( 'file', 'directory', 'missing' ), true ) || ( 'missing' === $type ) !== ( ! $exists ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_partial_manifest_invalid',
					__( 'Partial snapshot entry type or tombstone metadata is invalid.', 'ag-sync-bridge' ),
					array( 'path' => $path )
				);
			}

			$entry_paths[] = $path;
			if ( $exists ) {
				$rules[] = array(
					'archive' => $archive,
					'type'    => $type,
					'found'   => false,
				);
			}
		}

		if ( $entry_paths !== $declared_paths ) {
			return new WP_Error( 'ag_sync_bridge_zip_partial_manifest_invalid', __( 'Partial snapshot entries do not exactly match partial_paths.', 'ag-sync-bridge' ) );
		}

		foreach ( $entries as $archive_entry ) {
			if ( 'manifest.json' === $archive_entry['canonical'] ) {
				continue;
			}

			$matched = false;
			foreach ( $rules as $rule_index => $rule ) {
				if ( 'file' === $rule['type'] && $archive_entry['canonical'] === $rule['archive'] && empty( $archive_entry['is_directory'] ) ) {
					$rules[ $rule_index ]['found'] = true;
					$matched = true;
					break;
				}
				if ( 'directory' === $rule['type'] && ( ( $archive_entry['canonical'] === $rule['archive'] && ! empty( $archive_entry['is_directory'] ) ) || 0 === strpos( $archive_entry['canonical'], $rule['archive'] . '/' ) ) ) {
					$rules[ $rule_index ]['found'] = true;
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				return new WP_Error(
					'ag_sync_bridge_zip_partial_inventory',
					__( 'Partial snapshot archive contains a member outside its manifest path contract.', 'ag-sync-bridge' ),
					array( 'entry' => $archive_entry['canonical'] )
				);
			}
		}

		foreach ( $rules as $rule ) {
			if ( empty( $rule['found'] ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_partial_inventory',
					__( 'Partial snapshot archive is missing a declared file or directory member.', 'ag-sync-bridge' ),
					array( 'entry' => $rule['archive'] )
				);
			}
		}

		return true;
	}

	private function resolve_zip_entry_target( $target_dir, $entry_name ) {
		$entry_name = str_replace( '\\', '/', (string) $entry_name );

		if ( '' === $entry_name || false !== strpos( $entry_name, "\0" ) || preg_match( '#^([a-zA-Z]:)?/#', $entry_name ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_entry_invalid',
				__( 'Snapshot archive contains an invalid entry path.', 'ag-sync-bridge' ),
				array(
					'entry'      => $entry_name,
					'target_dir' => normalize_path( $target_dir ),
				)
			);
		}

		$parts = explode( '/', $entry_name );
		if ( in_array( '..', $parts, true ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_entry_traversal',
				__( 'Snapshot archive contains an unsafe relative entry path.', 'ag-sync-bridge' ),
				array(
					'entry'      => $entry_name,
					'target_dir' => normalize_path( $target_dir ),
				)
			);
		}

		$root   = rtrim( normalize_path( $target_dir ), '/' );
		$target = normalize_path( $root . '/' . ltrim( $entry_name, '/' ) );

		if ( 0 !== strpos( $target . '/', $root . '/' ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_entry_outside_target',
				__( 'Snapshot archive entry resolves outside the extraction directory.', 'ag-sync-bridge' ),
				array(
					'entry'      => $entry_name,
					'target'     => $target,
					'target_dir' => $root,
				)
			);
		}

		return $target;
	}

	private function zip_extract_error( $code, $package_path, $target_dir, $entry_name, $target_path ) {
		return new WP_Error(
			$code,
			sprintf(
				/* translators: %s: archive entry path. */
				__( 'Unable to extract snapshot archive entry: %s', 'ag-sync-bridge' ),
				$entry_name
			),
			array(
				'package'    => normalize_path( $package_path ),
				'target_dir' => normalize_path( $target_dir ),
				'entry'      => $entry_name,
				'target'     => normalize_path( $target_path ),
				'php_error'  => error_get_last(),
			)
		);
	}

	private function add_directory( ZipArchive $zip, $source_dir, $archive_dir, $component, callable $exclude_callback, $progress_callback = null, $cancellation_check = null, $reject_links = false ) {
		$summary = array(
			'archive_root' => $archive_dir,
			'file_count'   => 0,
			'size_bytes'   => 0,
		);
		$last_report = microtime( true );
		$source_dir  = rtrim( normalize_path( $source_dir ), '/' );
		$source_real = realpath( $source_dir );

		if ( $reject_links && ( is_link( $source_dir ) || false === $source_real ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_source_link_forbidden', __( 'Partial snapshots cannot archive a linked or unresolved source directory.', 'ag-sync-bridge' ) );
		}
		$source_real = false === $source_real ? '' : rtrim( normalize_path( $source_real ), '/' );

		if ( $reject_links && ! $zip->addEmptyDir( $archive_dir ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_add_directory', __( 'Unable to add the selected partial directory root to the archive.', 'ag-sync-bridge' ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/** @var SplFileInfo $item */
		foreach ( $iterator as $item ) {
			if ( $this->is_cancel_requested( $cancellation_check, 'archive-files' ) ) {
				return $this->cancellation_error( 'archive-files' );
			}
			$source_path = normalize_path( $item->getPathname() );
			$relative = ltrim( substr( $source_path, strlen( $source_dir ) ), '/' );

			if ( $reject_links ) {
				$resolved = realpath( $source_path );
				$expected = normalize_path( $source_real . '/' . $relative );
				if ( $item->isLink() || false === $resolved || ! $this->archive_paths_are_equal( $resolved, $expected ) ) {
					return new WP_Error(
						'ag_sync_bridge_partial_source_link_forbidden',
						__( 'Partial snapshots cannot traverse a symlink, junction or filesystem alias.', 'ag-sync-bridge' ),
						array( 'path' => $relative )
					);
				}
			}

			if ( $exclude_callback( $source_path ) ) {
				continue;
			}

			$zip_path = $archive_dir . ( $relative ? '/' . str_replace( '\\', '/', $relative ) : '' );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $zip_path );
				continue;
			}

			if ( ! $zip->addFile( $source_path, $zip_path ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_add_file',
					sprintf(
						/* translators: %s: archive file path. */
						__( 'Unable to add file to archive: %s', 'ag-sync-bridge' ),
						$zip_path
					)
				);
			}

			$summary['file_count']++;
			$summary['size_bytes'] += filesize( $source_path );

			if ( 0 === $summary['file_count'] % 100 || ( microtime( true ) - $last_report ) >= 5 ) {
				$this->report_progress(
					$progress_callback,
					'archive-files',
					null,
					array( 'component' => $component, 'files_complete' => $summary['file_count'], 'bytes_complete' => $summary['size_bytes'] )
				);
				$last_report = microtime( true );
			}
		}

		$summary['component'] = $component;
		return $summary;
	}

	private function add_file( ZipArchive $zip, $source_path, $zip_path, $component, callable $exclude_callback, $reject_links = false ) {
		if ( $reject_links && ( is_link( $source_path ) || false === realpath( $source_path ) ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_source_link_forbidden', __( 'Partial snapshots cannot archive a linked or unresolved source file.', 'ag-sync-bridge' ) );
		}

		if ( $exclude_callback( $source_path ) ) {
			return array(
				'component'    => $component,
				'archive_root' => $zip_path,
				'file_count'   => 0,
				'size_bytes'   => 0,
			);
		}

		if ( ! $zip->addFile( $source_path, $zip_path ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_add_file',
				sprintf(
					/* translators: %s: archive file path. */
					__( 'Unable to add file to archive: %s', 'ag-sync-bridge' ),
					$zip_path
				)
			);
		}

		return array(
			'component'    => $component,
			'archive_root' => $zip_path,
			'file_count'   => 1,
			'size_bytes'   => filesize( $source_path ),
		);
	}

	private function archive_paths_are_equal( $left, $right ) {
		$left  = rtrim( normalize_path( $left ), '/' );
		$right = rtrim( normalize_path( $right ), '/' );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$left  = strtolower( $left );
			$right = strtolower( $right );
		}
		return $left === $right;
	}

	private function report_progress( $callback, $stage, $progress, array $context = array() ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $stage, $progress, $context );
		}
	}

	private function is_cancel_requested( $callback, $stage ) {
		return is_callable( $callback ) && (bool) call_user_func( $callback, $stage, false );
	}

	private function cancellation_error( $stage ) {
		return new WP_Error(
			'ag_sync_bridge_operation_cancelled',
			__( 'Snapshot creation was cancelled before the target changed.', 'ag-sync-bridge' ),
			array(
				'cancelled'         => true,
				'rollback_required' => false,
				'stage'             => sanitize_key( $stage ),
			)
		);
	}
}
