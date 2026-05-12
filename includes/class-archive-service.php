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

		$manifest['database'] = array(
			'filename'    => 'database.sql',
			'size_bytes'  => filesize( $database_path ),
			'sha256'      => hash_file( 'sha256', $database_path ),
			'exported_at' => gmdate( 'c' ),
		);
		$manifest['components'] = array();

		$zip->addFile( $database_path, 'database.sql' );

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

		$zip = new ZipArchive();

		if ( true !== $zip->open( $package_path ) ) {
			return new WP_Error( 'ag_sync_bridge_zip_open', __( 'Unable to open snapshot archive.', 'ag-sync-bridge' ) );
		}

		if ( ! $zip->extractTo( $target_dir ) ) {
			$zip->close();
			return new WP_Error( 'ag_sync_bridge_zip_extract', __( 'Unable to extract snapshot archive.', 'ag-sync-bridge' ) );
		}

		$zip->close();
		return true;
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
