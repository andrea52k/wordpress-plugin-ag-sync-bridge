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

	public function create_package( $package_path, $database_path, array $manifest, array $entries, callable $exclude_callback ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
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

		foreach ( $entries as $entry ) {
			if ( 'directory' === $entry['type'] ) {
				$summary = $this->add_directory( $zip, $entry['source'], $entry['archive'], $entry['component'], $exclude_callback );
			} else {
				$summary = $this->add_file( $zip, $entry['source'], $entry['archive'], $entry['component'], $exclude_callback );
			}

			if ( is_wp_error( $summary ) ) {
				$zip->close();
				return $summary;
			}

			$manifest['components'][ $entry['component'] ] = $summary;
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

	public function extract_package( $package_path, $target_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_missing', __( 'ZipArchive is not available on this server.', 'ag-sync-bridge' ) );
		}

		if ( ! ensure_directory( $target_dir ) ) {
			return new WP_Error(
				'ag_sync_bridge_zip_extract_target',
				__( 'Unable to prepare snapshot extraction directory.', 'ag-sync-bridge' ),
				array(
					'package'    => normalize_path( $package_path ),
					'target_dir' => normalize_path( $target_dir ),
				)
			);
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

		$result = $this->extract_entries( $zip, $package_path, $target_dir );
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

	private function extract_entries( ZipArchive $zip, $package_path, $target_dir ) {
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );

			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
				return new WP_Error(
					'ag_sync_bridge_zip_entry_stat',
					__( 'Unable to read snapshot archive entry metadata.', 'ag-sync-bridge' ),
					array(
						'package' => normalize_path( $package_path ),
						'index'   => $index,
					)
				);
			}

			$name   = str_replace( '\\', '/', (string) $stat['name'] );
			$target = $this->resolve_zip_entry_target( $target_dir, $name );

			if ( is_wp_error( $target ) ) {
				return $target;
			}

			if ( '/' === substr( $name, -1 ) ) {
				if ( ! ensure_directory( $target ) ) {
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_dir', $package_path, $target_dir, $name, $target );
				}
				continue;
			}

			if ( ! ensure_directory( dirname( $target ) ) ) {
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_parent', $package_path, $target_dir, $name, dirname( $target ) );
			}

			$source = $zip->getStream( $name );
			if ( false === $source ) {
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_open', $package_path, $target_dir, $name, $target );
			}

			$destination = fopen( $target, 'wb' );
			if ( false === $destination ) {
				fclose( $source );
				return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_write_open', $package_path, $target_dir, $name, $target );
			}

			while ( ! feof( $source ) ) {
				$buffer = fread( $source, 1048576 );
				if ( false === $buffer ) {
					fclose( $source );
					fclose( $destination );
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_read', $package_path, $target_dir, $name, $target );
				}

				if ( '' !== $buffer && false === fwrite( $destination, $buffer ) ) {
					fclose( $source );
					fclose( $destination );
					return $this->zip_extract_error( 'ag_sync_bridge_zip_entry_write', $package_path, $target_dir, $name, $target );
				}
			}

			fclose( $source );
			fclose( $destination );
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

	private function add_directory( ZipArchive $zip, $source_dir, $archive_dir, $component, callable $exclude_callback ) {
		$summary = array(
			'archive_root' => $archive_dir,
			'file_count'   => 0,
			'size_bytes'   => 0,
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/** @var SplFileInfo $item */
		foreach ( $iterator as $item ) {
			$source_path = normalize_path( $item->getPathname() );

			if ( $exclude_callback( $source_path ) ) {
				continue;
			}

			$relative = ltrim( str_replace( normalize_path( $source_dir ), '', $source_path ), '/' );
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
		}

		$summary['component'] = $component;
		return $summary;
	}

	private function add_file( ZipArchive $zip, $source_path, $zip_path, $component, callable $exclude_callback ) {
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
}
