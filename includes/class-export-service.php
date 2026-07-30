<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Export_Service {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var File_System_Service
	 */
	private $file_system;

	/**
	 * @var Database_Service
	 */
	private $database;

	/**
	 * @var Archive_Service
	 */
	private $archive;

	public function __construct( Config $config, Logger $logger, File_System_Service $file_system, Database_Service $database, Archive_Service $archive ) {
		$this->config      = $config;
		$this->logger      = $logger;
		$this->file_system = $file_system;
		$this->database    = $database;
		$this->archive     = $archive;
	}

	public function create_snapshot( $type = 'snapshot', array $context = array() ) {
		$started_at = microtime( true );
		$progress_callback = array_get( $context, 'progress_callback', null );
		$cancellation_check = array_get( $context, 'cancellation_check', null );
		unset( $context['progress_callback'], $context['cancellation_check'] );
		$cancelled = $this->check_cancellation( $cancellation_check, 'snapshot-prepare' );
		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}
		$this->report_progress( $progress_callback, 'snapshot-prepare', 5 );
		$temp_dir   = $this->file_system->create_temp_dir( $type );

		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$package_data = $this->file_system->get_new_package_path( $type );
		$database_sql = normalize_path( $temp_dir . '/database.sql' );
		$this->report_progress( $progress_callback, 'database-export', 10 );
		$result       = $this->database->export_to_file(
			$database_sql,
			array(
				'progress_callback' => function ( $stage, $progress, array $details = array() ) use ( $progress_callback ) {
					$this->report_progress( $progress_callback, $stage, null === $progress ? 15 : $progress, $details );
				},
				'cancellation_check' => $cancellation_check,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return $result;
		}
		$this->report_progress( $progress_callback, 'database-exported', 30 );
		$cancelled = $this->check_cancellation( $cancellation_check, 'database-exported' );
		if ( is_wp_error( $cancelled ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return $cancelled;
		}

		$entries            = $this->file_system->get_export_entries();
		$snapshot_integrity = $this->file_system->get_snapshot_integrity_for_export( $entries );
		$manifest           = array(
			'id'               => wp_generate_uuid4(),
			'type'             => sanitize_key( $type ),
			'snapshot_scope'   => 'full',
			'is_full_snapshot' => true,
			'created_at'       => gmdate( 'c' ),
			'source_site_url'  => site_url(),
			'source_home_url'  => home_url(),
			'source_host'      => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_role'      => $this->config->get_role(),
			'source_table_prefix' => $this->database->get_table_prefix(),
			'entries_included' => wp_list_pluck( $entries, 'component' ),
			'root_sync_files'  => array_get( $snapshot_integrity, 'root_sync_files', array() ),
			'sitemap_integrity'=> array_get( $snapshot_integrity, 'sitemap_integrity', array() ),
			'full_snapshot_requirements' => array(
				'required_components' => wp_list_pluck( $entries, 'component' ),
			),
			'exclude_patterns' => $this->config->get_exclude_patterns(),
			'context'          => $context,
		);

		$archive_result = $this->archive->create_package(
			$package_data['path'],
			$database_sql,
			$manifest,
			$entries,
			array( $this->file_system, 'should_exclude' ),
			function ( $stage, $progress, array $details = array() ) use ( $progress_callback ) {
				$mapped = null === $progress ? 60 : 35 + (int) round( max( 0, min( 100, (int) $progress ) ) * 0.55 );
				$this->report_progress( $progress_callback, $stage, $mapped, $details );
			},
			$cancellation_check
		);

		$this->file_system->cleanup_path( $temp_dir );

		if ( is_wp_error( $archive_result ) ) {
			return $archive_result;
		}
		$this->report_progress( $progress_callback, 'snapshot-finalize', 95 );

		$meta = array(
			'basename'        => $package_data['basename'],
			'path'            => $package_data['path'],
			'type'            => sanitize_key( $type ),
			'created_at'      => gmdate( 'c' ),
			'size_bytes'      => array_get( $archive_result, 'size_bytes', 0 ),
			'sha256'          => array_get( $archive_result, 'sha256', '' ),
			'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'manifest'        => array_get( $archive_result, 'manifest', array() ),
			'database_method' => array_get( $result, 'method', 'php' ),
		);
		$this->file_system->cleanup_path( $this->file_system->get_meta_path_for_package( $package_data['path'] ) );

		if ( false !== strpos( $type, 'backup' ) ) {
			$this->file_system->cleanup_old_packages( 'backups' );
		} else {
			$this->file_system->cleanup_old_packages( 'snapshots' );
		}

		$this->config->set_state_value( 'last_snapshot', $meta );
		$this->logger->info(
			'Snapshot created.',
			array(
				'type'        => $type,
				'basename'    => $meta['basename'],
				'size_bytes'  => $meta['size_bytes'],
				'sha256'      => $meta['sha256'],
				'duration_ms' => $meta['duration_ms'],
			)
		);

		return $meta;
	}

	public function create_partial_snapshot( array $paths, $type = 'partial-push-snapshot', array $context = array() ) {
		$started_at = microtime( true );
		$progress_callback = array_get( $context, 'progress_callback', null );
		$cancellation_check = array_get( $context, 'cancellation_check', null );
		unset( $context['progress_callback'], $context['cancellation_check'] );
		$cancelled = $this->check_cancellation( $cancellation_check, 'snapshot-prepare' );
		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}
		$entries    = $this->file_system->get_partial_export_entries( $paths );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$package_data   = $this->file_system->get_new_package_path( $type );
		$partial_paths  = array_values( array_map( static function ( $entry ) {
			return (string) array_get( $entry, 'partial_path', '' );
		}, $entries ) );
		$partial_entries = array_values( array_map( static function ( $entry ) {
			return array(
				'path'    => (string) array_get( $entry, 'partial_path', '' ),
				'type'    => (string) array_get( $entry, 'partial_type', array_get( $entry, 'type', '' ) ),
				'archive' => (string) array_get( $entry, 'archive', '' ),
			);
		}, $entries ) );

		$manifest = array(
			'id'                  => wp_generate_uuid4(),
			'type'                => sanitize_key( $type ),
			'snapshot_scope'      => 'partial',
			'is_full_snapshot'    => false,
			'created_at'          => gmdate( 'c' ),
			'source_site_url'     => site_url(),
			'source_home_url'     => home_url(),
			'source_host'         => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_role'         => $this->config->get_role(),
			'source_table_prefix' => $this->database->get_table_prefix(),
			'entries_included'    => wp_list_pluck( $entries, 'component' ),
			'partial_paths'       => $partial_paths,
			'partial_entries'     => $partial_entries,
			'exclude_patterns'    => $this->config->get_exclude_patterns(),
			'context'             => $context,
		);

		$archive_result = $this->archive->create_package(
			$package_data['path'],
			'',
			$manifest,
			$entries,
			array( $this->file_system, 'should_exclude' ),
			$progress_callback,
			$cancellation_check
		);

		if ( is_wp_error( $archive_result ) ) {
			return $archive_result;
		}

		$meta = array(
			'basename'       => $package_data['basename'],
			'path'           => $package_data['path'],
			'type'           => sanitize_key( $type ),
			'created_at'     => gmdate( 'c' ),
			'size_bytes'     => array_get( $archive_result, 'size_bytes', 0 ),
			'sha256'         => array_get( $archive_result, 'sha256', '' ),
			'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'manifest'       => array_get( $archive_result, 'manifest', array() ),
			'snapshot_scope' => 'partial',
			'partial_paths'  => $partial_paths,
		);

		$this->file_system->cleanup_path( $this->file_system->get_meta_path_for_package( $package_data['path'] ) );
		$this->file_system->cleanup_old_packages( 'snapshots' );
		$this->config->set_state_value( 'last_snapshot', $meta );
		$this->logger->info(
			'Partial snapshot created.',
			array(
				'type'        => $type,
				'basename'    => $meta['basename'],
				'size_bytes'  => $meta['size_bytes'],
				'sha256'      => $meta['sha256'],
				'duration_ms' => $meta['duration_ms'],
				'paths'       => $partial_paths,
			)
		);

		return $meta;
	}

	public function create_partial_backup( array $paths, $type = 'partial-pre-push-backup', array $context = array() ) {
		$started_at         = microtime( true );
		$progress_callback  = array_get( $context, 'progress_callback', null );
		$cancellation_check = array_get( $context, 'cancellation_check', null );
		unset( $context['progress_callback'], $context['cancellation_check'] );

		$cancelled = $this->check_cancellation( $cancellation_check, 'backup-prepare' );
		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}

		$export_data = $this->file_system->get_partial_backup_export_data( $paths );
		if ( is_wp_error( $export_data ) ) {
			return $export_data;
		}

		$partial_paths   = array_get( $export_data, 'paths', array() );
		$entries         = array_get( $export_data, 'entries', array() );
		$partial_entries = array_get( $export_data, 'partial_entries', array() );
		$package_data    = $this->file_system->get_new_package_path( $type );
		$manifest        = array(
			'id'                  => wp_generate_uuid4(),
			'type'                => sanitize_key( $type ),
			'snapshot_scope'      => 'partial',
			'is_full_snapshot'    => false,
			'created_at'          => gmdate( 'c' ),
			'source_site_url'     => site_url(),
			'source_home_url'     => home_url(),
			'source_host'         => wp_parse_url( home_url(), PHP_URL_HOST ),
			'source_role'         => $this->config->get_role(),
			'source_table_prefix' => $this->database->get_table_prefix(),
			'entries_included'    => wp_list_pluck( $entries, 'component' ),
			'partial_paths'       => $partial_paths,
			'partial_entries'     => $partial_entries,
			'exclude_patterns'    => $this->config->get_exclude_patterns(),
			'context'             => $context,
		);

		$archive_result = $this->archive->create_package(
			$package_data['path'],
			'',
			$manifest,
			$entries,
			array( $this->file_system, 'should_exclude' ),
			$progress_callback,
			$cancellation_check
		);

		if ( is_wp_error( $archive_result ) ) {
			return $archive_result;
		}

		$meta = array(
			'basename'       => $package_data['basename'],
			'path'           => $package_data['path'],
			'type'           => sanitize_key( $type ),
			'created_at'     => gmdate( 'c' ),
			'size_bytes'     => array_get( $archive_result, 'size_bytes', 0 ),
			'sha256'         => array_get( $archive_result, 'sha256', '' ),
			'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'manifest'       => array_get( $archive_result, 'manifest', array() ),
			'snapshot_scope' => 'partial',
			'partial_paths'  => $partial_paths,
		);

		$this->file_system->cleanup_path( $this->file_system->get_meta_path_for_package( $package_data['path'] ) );
		$this->file_system->cleanup_old_packages( 'backups' );
		$this->config->set_state_value( 'last_snapshot', $meta );
		$this->logger->info(
			'Partial backup created.',
			array(
				'type'        => $type,
				'basename'    => $meta['basename'],
				'size_bytes'  => $meta['size_bytes'],
				'sha256'      => $meta['sha256'],
				'duration_ms' => $meta['duration_ms'],
				'paths'       => $partial_paths,
			)
		);

		return $meta;
	}

	private function report_progress( $callback, $stage, $progress, array $details = array() ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $stage, $progress, $details );
		}
	}

	private function check_cancellation( $callback, $stage ) {
		if ( ! is_callable( $callback ) || ! call_user_func( $callback, $stage, false ) ) {
			return true;
		}

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

	public function get_latest_snapshot() {
		$list = $this->file_system->list_packages( 'snapshots', 1, true );
		return empty( $list ) ? array() : $list[0];
	}
}
