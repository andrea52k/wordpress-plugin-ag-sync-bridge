<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sync_Service {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var Lock_Manager
	 */
	private $lock_manager;

	/**
	 * @var File_System_Service
	 */
	private $file_system;

	/**
	 * @var Export_Service
	 */
	private $exporter;

	/**
	 * @var Import_Service
	 */
	private $importer;

	/**
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * @var Local_Maintenance_Service
	 */
	private $maintenance;

	public function __construct( Config $config, Logger $logger, Lock_Manager $lock_manager, File_System_Service $file_system, Export_Service $exporter, Import_Service $importer, Http_Client $http_client, Local_Maintenance_Service $maintenance ) {
		$this->config       = $config;
		$this->logger       = $logger;
		$this->lock_manager = $lock_manager;
		$this->file_system  = $file_system;
		$this->exporter     = $exporter;
		$this->importer     = $importer;
		$this->http_client  = $http_client;
		$this->maintenance  = $maintenance;
	}

	public function test_connection() {
		if ( ! $this->config->get_remote_url() || ! $this->config->get_secret() ) {
			$error = new WP_Error( 'ag_sync_bridge_missing_remote', __( 'Remote URL or shared secret is missing.', 'ag-sync-bridge' ) );
			$this->config->set_state_value(
				'last_connection',
				array(
					'checked_at' => gmdate( 'c' ),
					'status'     => 'error',
					'message'    => $error->get_error_message(),
				)
			);
			return $error;
		}

		$result = $this->http_client->test_connection();

		if ( ! is_wp_error( $result ) ) {
			$this->config->set_state_value(
				'last_connection',
				array(
					'checked_at' => gmdate( 'c' ),
					'status'     => 'ok',
					'remote'     => $result,
				)
			);
			$this->logger->info( 'Remote connection test passed.', array( 'remote_url' => $this->config->get_remote_url() ) );
		} else {
			$this->config->set_state_value(
				'last_connection',
				array(
					'checked_at' => gmdate( 'c' ),
					'status'     => 'error',
					'message'    => $result->get_error_message(),
				)
			);
			$this->logger->warning( 'Remote connection test failed.', array( 'remote_url' => $this->config->get_remote_url(), 'error' => $result->get_error_message() ) );
		}

		return $result;
	}

	public function create_snapshot( $type = 'manual-snapshot', array $context = array() ) {
		$result = $this->exporter->create_snapshot( $type, $context );

		if ( ! is_wp_error( $result ) ) {
			$this->cleanup_local_runtime_storage( 'snapshot' );
		}

		return $result;
	}

	public function create_partial_snapshot( array $paths, $type = 'manual-partial-snapshot', array $context = array() ) {
		$result = $this->exporter->create_partial_snapshot( $paths, $type, $context );

		if ( ! is_wp_error( $result ) ) {
			$this->cleanup_local_runtime_storage( 'snapshot' );
		}

		return $result;
	}

	public function create_backup( $type = 'manual-backup', array $context = array() ) {
		$result = $this->exporter->create_snapshot( $type, $context );

		if ( ! is_wp_error( $result ) ) {
			$this->cleanup_local_runtime_storage( 'backup' );
		}

		return $result;
	}

	public function create_partial_backup( array $paths, $type = 'partial-pre-push-backup', array $context = array() ) {
		$result = $this->exporter->create_partial_backup( $paths, $type, $context );

		if ( ! is_wp_error( $result ) ) {
			$this->cleanup_local_runtime_storage( 'backup' );
		}

		return $result;
	}

	/**
	 * Build a read-only deployment plan. A partial plan is possible only when
	 * callers explicitly declare safe file paths; database and unknown changes
	 * remain full snapshots by design.
	 *
	 * @param array $paths Requested partial paths.
	 * @return array|WP_Error
	 */
	public function plan_push( array $paths = array() ) {
		$paths = $this->file_system->normalize_partial_export_paths( $paths );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}
		$full_metrics = $this->estimate_entries( $this->file_system->get_export_entries() );
		$full_metrics['includes_database']       = true;
		$full_metrics['database_estimated_bytes'] = null;

		if ( empty( $paths ) ) {
			return array(
				'preflight_version' => 1,
				'decision'       => 'full',
				'snapshot_scope' => 'full',
				'reason'         => 'database_or_unknown_scope',
				'paths'          => array(),
				'file_count'     => $full_metrics['file_count'],
				'estimated_bytes'=> $full_metrics['estimated_bytes'],
				'change_classification' => array(
					'files'         => 'not_scoped',
					'database'      => 'unknown',
					'configuration' => 'unknown',
				),
				'transfers' => array(
					'database' => true,
					'files'    => true,
				),
				'metrics' => array(
					'full'    => $full_metrics,
					'partial' => array( 'available' => false, 'file_count' => 0, 'estimated_bytes' => 0 ),
				),
				'rollback' => array(
					'strategy' => 'full_snapshot_and_preflight',
					'required' => false,
					'available' => ! empty( $this->config->get( 'remote_backups_enabled', false ) ),
				),
			);
		}

		$entries = $this->file_system->get_partial_export_entries( $paths );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$partial_metrics = $this->estimate_entries( $entries );
		$file_count = $partial_metrics['file_count'];
		$total_bytes = $partial_metrics['estimated_bytes'];
		$directory_paths = array();
		foreach ( $entries as $entry ) {
			if ( 'directory' === (string) array_get( $entry, 'partial_type', '' ) ) {
				$directory_paths[] = (string) array_get( $entry, 'partial_path', '' );
			}
		}
		$rollback_available = ! empty( $this->config->get( 'remote_backups_enabled', false ) );

		return array(
			'preflight_version' => 1,
			'decision'        => 'partial',
			'snapshot_scope'  => 'partial',
			'reason'          => 'explicit_safe_paths',
			'paths'           => $paths,
			'file_count'      => $file_count,
			'estimated_bytes' => $total_bytes,
			'change_classification' => array(
				'files'         => 'explicit_safe_paths',
				'database'      => 'excluded',
				'configuration' => 'excluded_unless_in_paths',
			),
			'transfers' => array(
				'database' => false,
				'files'    => true,
			),
			'metrics' => array(
				'full'    => $full_metrics,
				'partial' => array( 'available' => true, 'file_count' => $file_count, 'estimated_bytes' => $total_bytes ),
				'comparison' => array(
					'estimated_file_bytes_saved' => max( 0, $full_metrics['estimated_bytes'] - $total_bytes ),
					'estimated_files_avoided'    => max( 0, $full_metrics['file_count'] - $file_count ),
					'database_transfer_avoided'  => true,
				),
			),
			'rollback' => array(
				'strategy'  => 'remote_pre_push_backup',
				'required'  => true,
				'available' => $rollback_available,
			),
			'risk' => array(
				'directory_replacement_paths' => array_values( array_filter( $directory_paths ) ),
				'partial_execution_allowed'   => $rollback_available,
				'block_reason' => $rollback_available ? '' : 'remote_backup_required_for_partial_push',
			),
		);
	}

	public function pull_from_remote( array $args = array() ) {
		$lock = $this->lock_manager->acquire( 'pull' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$trigger              = sanitize_key( (string) array_get( $args, 'trigger', 'manual' ) );
			$use_existing_snapshot = ! empty( $args['use_existing_snapshot'] );
			$cancellation_check   = function ( $stage = '', $rollback_required = false ) {
				unset( $stage );
				return ! $rollback_required && $this->lock_manager->is_cancel_requested();
			};

			$this->logger->info( 'Pull started.', array( 'remote_url' => $this->config->get_remote_url(), 'trigger' => $trigger, 'use_existing_snapshot' => $use_existing_snapshot ) );
			$this->update_operation( 'pull', 5, 'local-backup', __( 'Creazione backup locale di sicurezza...', 'ag-sync-bridge' ) );
			$local_backup = $this->create_backup(
				'pre-pull-backup',
				array(
					'trigger'            => $trigger ? $trigger . '-pull' : 'pull',
					'cancellation_check' => $cancellation_check,
				)
			);
			if ( is_wp_error( $local_backup ) ) {
				$this->fail_operation( 'pull', $local_backup );
				return $local_backup;
			}

			$this->logger->info( 'Local pre-pull backup completed.', array( 'backup' => array_get( $local_backup, 'basename', '' ) ) );
			$this->update_operation( 'pull', 20, 'remote-snapshot', $use_existing_snapshot ? __( 'Recupero snapshot disponibile dal live...', 'ag-sync-bridge' ) : __( 'Richiesta snapshot dal live...', 'ag-sync-bridge' ) );
			$remote_snapshot = $use_existing_snapshot ? $this->http_client->get_latest_snapshot() : $this->http_client->create_remote_snapshot( 'manual-pull-snapshot', $cancellation_check );

			if ( $use_existing_snapshot && ( is_wp_error( $remote_snapshot ) || empty( $remote_snapshot ) ) ) {
				$this->logger->warning( 'No reusable remote snapshot was available. Falling back to fresh remote snapshot creation.', array( 'trigger' => $trigger ) );
				$remote_snapshot = $this->http_client->create_remote_snapshot( 'auto-pull-snapshot', $cancellation_check );
			}

			if ( is_wp_error( $remote_snapshot ) ) {
				$this->fail_operation( 'pull', $remote_snapshot );
				return $remote_snapshot;
			}

			$this->logger->info( 'Remote snapshot prepared for pull.', array( 'snapshot' => array_get( $remote_snapshot, 'basename', '' ) ) );
			$this->update_operation( 'pull', 35, 'download', __( 'Download snapshot dal live...', 'ag-sync-bridge' ) );
			$temp_dir = $this->file_system->create_temp_dir( 'download' );
			if ( is_wp_error( $temp_dir ) ) {
				$this->fail_operation( 'pull', $temp_dir );
				return $temp_dir;
			}

			$download_path = normalize_path( $temp_dir . '/' . basename( array_get( $remote_snapshot, 'basename', 'snapshot.zip' ) ) );
			$downloaded    = $this->http_client->download_snapshot( array_get( $remote_snapshot, 'basename', '' ), $download_path, $cancellation_check );
			if ( is_wp_error( $downloaded ) ) {
				$this->file_system->cleanup_path( $temp_dir );
				$this->fail_operation( 'pull', $downloaded );
				return $downloaded;
			}

			$this->logger->info( 'Remote snapshot downloaded locally.', array( 'path' => $download_path ) );
			$this->update_operation( 'pull', 70, 'import', __( 'Import snapshot sul locale e replace URL...', 'ag-sync-bridge' ) );
			$result = $this->importer->import_snapshot(
				$download_path,
				array(
					'expected_sha256' => array_get( $remote_snapshot, 'sha256', '' ),
					'target_site_url' => site_url(),
					'target_home_url' => home_url(),
					'cancellation_check' => $cancellation_check,
				)
			);

			$this->file_system->cleanup_path( $temp_dir );

			if ( is_wp_error( $result ) ) {
				$this->fail_operation( 'pull', $result );
				return $result;
			}

			$this->update_operation( 'pull', 95, 'finalize', __( 'Pulizia finale e salvataggio stato...', 'ag-sync-bridge' ) );
			$this->config->set_state_value(
				'last_pull',
				array(
					'completed_at' => gmdate( 'c' ),
					'trigger'      => $trigger,
					'backup'       => $local_backup,
					'remote'       => $remote_snapshot,
					'result'       => $result,
				)
			);

			if ( 'wp-cron' === $trigger || 'auto' === $trigger ) {
				$this->config->set_state_value(
					'last_auto_pull',
					array(
						'completed_at' => gmdate( 'c' ),
						'backup'       => $local_backup,
						'remote'       => $remote_snapshot,
						'result'       => $result,
					)
				);
			}

			$this->complete_operation( 'pull', __( 'Pull completed.', 'ag-sync-bridge' ) );
			$this->cleanup_local_runtime_storage( 'pull' );
			$this->cleanup_remote_runtime_storage( 'pull' );

			return array(
				'backup' => $local_backup,
				'remote' => $remote_snapshot,
				'result' => $result,
			);
		} finally {
			$this->lock_manager->release();
		}
	}

	public function push_to_remote( array $args = array() ) {
		$lock = $this->lock_manager->acquire( 'push' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$cancellation_check = function ( $stage = '', $rollback_required = false ) {
				unset( $stage );
				return ! $rollback_required && $this->lock_manager->is_cancel_requested();
			};
			$use_existing_snapshot = ! empty( $args['use_existing_snapshot'] );
			$remote_backups_enabled = ! empty( $this->config->get( 'remote_backups_enabled', false ) );
			$skip_remote_backup_arg = ! empty( $args['skip_remote_backup'] );
			$skip_remote_backup    = $skip_remote_backup_arg || ! $remote_backups_enabled;
			$allow_partial_snapshot = ! empty( $args['allow_partial_snapshot'] );
			$partial_paths          = array_get( $args, 'partial_paths', array() );
			$partial_paths          = is_array( $partial_paths ) ? $partial_paths : array();
			$partial_paths          = $this->file_system->normalize_partial_export_paths( $partial_paths );
			if ( is_wp_error( $partial_paths ) ) {
				return $partial_paths;
			}
			$is_partial_push = ! empty( $partial_paths );
			$deployment_plan = $this->plan_push( $partial_paths );
			if ( is_wp_error( $deployment_plan ) ) {
				return $deployment_plan;
			}
			if ( $is_partial_push && ( ! $remote_backups_enabled || $skip_remote_backup_arg ) ) {
				return new WP_Error(
					'ag_sync_bridge_partial_push_backup_required',
					__( 'Partial push blocked: enable and run a remote pre-push backup before replacing selected files or directories.', 'ag-sync-bridge' ),
					array( 'deployment_plan' => $deployment_plan )
				);
			}

			if ( $is_partial_push && $use_existing_snapshot ) {
				$error = new WP_Error( 'ag_sync_bridge_partial_push_existing_snapshot', __( 'Partial push cannot reuse an existing snapshot. Remove --use-existing-snapshot and create a fresh partial package.', 'ag-sync-bridge' ) );
				return $error;
			}

			$this->update_operation( 'push', 1, 'local-maintenance', __( 'Controllo e aggiornamento locale di plugin, temi e traduzioni...', 'ag-sync-bridge' ) );
			$maintenance = $this->maintenance->prepare_for_push();
			if ( is_wp_error( $maintenance ) ) {
				$this->fail_operation( 'push', $maintenance );
				return $maintenance;
			}

			$this->logger->info( 'Push started.', array( 'remote_url' => $this->config->get_remote_url(), 'use_existing_snapshot' => $use_existing_snapshot, 'remote_backups_enabled' => $remote_backups_enabled, 'skip_remote_backup' => $skip_remote_backup, 'allow_partial_snapshot' => $allow_partial_snapshot, 'partial_paths' => $partial_paths, 'deployment_plan' => $deployment_plan, 'local_maintenance' => $maintenance ) );
			$remote_backup = $remote_backups_enabled
				? Remote_Backup_Result::skipped( 'pre-push-backup' )
				: Remote_Backup_Result::disabled( 'pre-push-backup' );

			$this->update_operation( 'push', 3, 'remote-preflight', __( 'Controllo prerequisiti storage sul live...', 'ag-sync-bridge' ) );
			$remote_preflight = $this->run_remote_preflight( $use_existing_snapshot, $is_partial_push, ! $skip_remote_backup );
			if ( is_wp_error( $remote_preflight ) ) {
				$this->fail_operation( 'push', $remote_preflight );
				return $remote_preflight;
			}

			$this->logger->info(
				'Remote preflight completed.',
				array(
					'ok'                  => ! empty( $remote_preflight['ok'] ),
					'required_free_bytes' => array_get( $remote_preflight, 'required_free_bytes', 0 ),
				)
			);

			if ( ! $skip_remote_backup ) {
				$this->update_operation( 'push', 5, 'remote-backup', __( 'Creazione backup di sicurezza sul live...', 'ag-sync-bridge' ) );
				$backup_scope  = $is_partial_push ? 'partial' : 'full';
				$backup_paths  = $is_partial_push ? array_get( $deployment_plan, 'paths', array() ) : array();
				$remote_backup = $this->http_client->create_remote_backup( $backup_scope, $backup_paths );
				if ( is_wp_error( $remote_backup ) ) {
					$this->fail_operation( 'push', $remote_backup );
					return $remote_backup;
				}

				$remote_backup = Remote_Backup_Result::require_completed( $remote_backup, $backup_scope, $backup_paths );
				if ( is_wp_error( $remote_backup ) ) {
					$this->fail_operation( 'push', $remote_backup );
					return $remote_backup;
				}

				$this->logger->info(
					'Remote pre-push backup completed and verified.',
					array(
						'status'     => array_get( $remote_backup, 'status', '' ),
						'backup'     => array_get( $remote_backup, 'basename', '' ),
						'size_bytes' => array_get( $remote_backup, 'size_bytes', 0 ),
						'sha256'     => array_get( $remote_backup, 'sha256', '' ),
					)
				);
			} else {
				$this->logger->info( 'Remote pre-push backup skipped.', $remote_backup );
			}

			$snapshot_message = $is_partial_push ? __( 'Creazione snapshot parziale del locale...', 'ag-sync-bridge' ) : ( $use_existing_snapshot ? __( 'Riutilizzo ultimo snapshot locale...', 'ag-sync-bridge' ) : __( 'Creazione snapshot completo del locale...', 'ag-sync-bridge' ) );
			$this->update_operation( 'push', 25, 'local-snapshot', $snapshot_message );
			$local_snapshot = $use_existing_snapshot ? $this->get_latest_local_snapshot() : array();

			if ( empty( $local_snapshot ) ) {
				$local_snapshot = $is_partial_push
					? $this->create_partial_snapshot(
						$partial_paths,
						'manual-partial-push-snapshot',
						array(
							'trigger'       => 'push',
							'partial_paths' => $partial_paths,
							'cancellation_check' => $cancellation_check,
						)
					)
					: $this->create_snapshot(
						'manual-push-snapshot',
						array(
							'trigger' => 'push',
							'cancellation_check' => $cancellation_check,
						)
					);
			}

			if ( is_wp_error( $local_snapshot ) ) {
				$this->fail_operation( 'push', $local_snapshot );
				return $local_snapshot;
			}

			$this->update_operation( 'push', 35, 'local-validation', __( 'Validazione snapshot prima dell upload...', 'ag-sync-bridge' ) );
			$validation = $this->validate_local_snapshot_for_push( $local_snapshot, $allow_partial_snapshot, $is_partial_push );
			if ( is_wp_error( $validation ) ) {
				$this->fail_operation( 'push', $validation );
				return $validation;
			}

			$this->logger->info( 'Local push snapshot completed.', array( 'snapshot' => array_get( $local_snapshot, 'basename', '' ) ) );
			$this->update_operation( 'push', 40, 'upload', __( 'Upload snapshot verso il live...', 'ag-sync-bridge' ) );
			$upload = $this->http_client->upload_snapshot(
				array_get( $local_snapshot, 'path', '' ),
				$local_snapshot,
				function ( $current, $total, $message ) {
					$total    = max( 1, (int) $total );
					$current  = max( 0, min( (int) $current, $total ) );
					$progress = 40 + (int) round( ( $current / $total ) * 40 );
					$this->update_operation( 'push', $progress, 'upload', $message );
				},
				$cancellation_check
			);
			if ( is_wp_error( $upload ) ) {
				$this->fail_operation( 'push', $upload );
				return $upload;
			}

			$this->logger->info( 'Snapshot uploaded to live.', array( 'snapshot' => array_get( $upload, 'snapshot', '' ) ) );
			$this->update_operation( 'push', 85, 'remote-import', __( 'Import snapshot sul live e replace URL...', 'ag-sync-bridge' ) );
			$remote_import = $this->http_client->trigger_remote_import( array_get( $upload, 'snapshot', '' ), array_get( $upload, 'sha256', '' ), $allow_partial_snapshot || $is_partial_push, $cancellation_check );
			if ( is_wp_error( $remote_import ) ) {
				$this->fail_operation( 'push', $remote_import );
				return $remote_import;
			}

			$this->logger->info( 'Remote import completed.', array( 'snapshot' => array_get( $upload, 'snapshot', '' ) ) );
			$this->update_operation( 'push', 95, 'finalize', __( 'Pulizia finale e salvataggio stato...', 'ag-sync-bridge' ) );
			$this->config->set_state_value(
				'last_push',
				array(
					'completed_at'  => gmdate( 'c' ),
					'remote_backup' => $remote_backup,
					'local_snapshot'=> $local_snapshot,
					'snapshot_validation' => $validation,
					'deployment_plan' => $deployment_plan,
					'local_maintenance' => $maintenance,
					'upload'        => $upload,
					'remote_import' => $remote_import,
				)
			);

			$this->complete_operation( 'push', __( 'Push completed.', 'ag-sync-bridge' ) );
			$this->cleanup_local_runtime_storage( 'push' );
			$this->cleanup_remote_runtime_storage( 'push' );

			return array(
				'remote_backup' => $remote_backup,
				'local_snapshot'=> $local_snapshot,
				'snapshot_validation' => $validation,
				'deployment_plan' => $deployment_plan,
				'local_maintenance' => $maintenance,
				'upload'        => $upload,
				'remote_import' => $remote_import,
			);
		} finally {
			$this->lock_manager->release();
		}
	}

	private function estimate_entry_size( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return array( 'file_count' => 0, 'bytes' => 0 );
		}

		if ( is_file( $path ) ) {
			return array( 'file_count' => 1, 'bytes' => max( 0, (int) filesize( $path ) ) );
		}

		$files = 0;
		$bytes = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					$files++;
					$bytes += max( 0, (int) $item->getSize() );
				}
			}
		} catch ( \UnexpectedValueException $exception ) {
			$this->logger->warning( 'Unable to fully estimate partial push entry.', array( 'path' => $path, 'error' => $exception->getMessage() ) );
		}

		return array( 'file_count' => $files, 'bytes' => $bytes );
	}

	private function estimate_entries( array $entries ) {
		$file_count = 0;
		$total_bytes = 0;
		foreach ( $entries as $entry ) {
			$estimate = $this->estimate_entry_size( (string) array_get( $entry, 'source', '' ) );
			$file_count += $estimate['file_count'];
			$total_bytes += $estimate['bytes'];
		}

		return array(
			'file_count'      => $file_count,
			'estimated_bytes' => $total_bytes,
		);
	}

	private function get_latest_local_snapshot() {
		foreach ( $this->file_system->list_packages( 'snapshots', 50, true ) as $snapshot ) {
			if ( 'full' === array_get( $snapshot, 'snapshot_scope', '' ) ) {
				return $snapshot;
			}
		}

		return array();
	}

	private function validate_local_snapshot_for_push( array $snapshot, $allow_partial_snapshot = false, $expect_partial_snapshot = false ) {
		$path = (string) array_get( $snapshot, 'path', '' );

		if ( '' === $path || ! file_exists( $path ) ) {
			return new WP_Error(
				'ag_sync_bridge_push_snapshot_missing',
				__( 'Local push snapshot file is missing.', 'ag-sync-bridge' ),
				array(
					'snapshot' => $snapshot,
				)
			);
		}

		$package = $this->importer->validate_package( $path, array_get( $snapshot, 'sha256', '' ) );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$manifest = array_get( $package, 'manifest', array() );
		$scope    = $this->file_system->get_snapshot_scope_from_manifest( $manifest );

		if ( $expect_partial_snapshot ) {
			$partial_entries = array_get( $manifest, 'partial_entries', array() );
			$partial_entries = is_array( $partial_entries ) ? array_values( array_filter( $partial_entries ) ) : array();
			$validation      = array(
				'ok'              => 'partial' === $scope && ! empty( $partial_entries ),
				'snapshot_scope'  => $scope ?: 'unknown',
				'partial_entries' => $partial_entries,
				'errors'          => array(),
			);

			if ( 'partial' !== $scope ) {
				$validation['errors'][] = 'snapshot_scope_not_partial';
			}

			if ( empty( $partial_entries ) ) {
				$validation['errors'][] = 'partial_entries_missing';
			}

			if ( empty( $validation['ok'] ) ) {
				return new WP_Error(
					'ag_sync_bridge_push_snapshot_not_partial',
					__( 'Partial push blocked: the package is not marked as a valid partial AG Sync snapshot.', 'ag-sync-bridge' ),
					$validation
				);
			}

			return $validation;
		}

		$validation = $this->file_system->validate_full_snapshot_manifest( $manifest );

		if ( empty( $validation['ok'] ) && ! $allow_partial_snapshot ) {
			return new WP_Error(
				'ag_sync_bridge_push_snapshot_not_full',
				__( 'Push blocked: the selected snapshot is not marked as a complete AG Sync snapshot. Create a fresh snapshot or use the explicit partial-snapshot override only for a deliberate recovery operation.', 'ag-sync-bridge' ),
				$validation
			);
		}

		if ( empty( $validation['ok'] ) ) {
			$this->logger->warning(
				'Partial snapshot push override enabled.',
				array(
					'snapshot'   => array_get( $snapshot, 'basename', basename( $path ) ),
					'validation' => $validation,
				)
			);
		}

		return $validation;
	}

	private function run_remote_preflight( $use_existing_snapshot = false, $requires_partial_push = false, $requires_signed_backup = false ) {
		$required_bytes = $this->estimate_remote_required_bytes( $use_existing_snapshot );
		$result         = $this->http_client->remote_doctor( $required_bytes );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$body = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';

			if ( false !== stripos( $result->get_error_message(), 'status 404' ) || false !== stripos( $body, 'rest_no_route' ) ) {
				return new WP_Error(
					'ag_sync_bridge_remote_preflight_missing',
					__( 'Il live non espone il preflight storage di AG Sync Bridge. Aggiorna AG Sync Bridge anche sul sito live prima di fare push.', 'ag-sync-bridge' ),
					$data
				);
			}

			return new WP_Error(
				'ag_sync_bridge_remote_preflight_failed',
				sprintf(
					/* translators: %s: remote error message */
					__( 'Preflight remoto non riuscito: %s', 'ag-sync-bridge' ),
					$result->get_error_message()
				),
				$data
			);
		}

		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_storage_not_ready',
				__( 'Il live non ha superato il preflight storage. Controlla spazio, quota e permessi nelle directory AG Sync Bridge prima di fare push.', 'ag-sync-bridge' ),
				$result
			);
		}

		$remote_plugin = (string) array_get( $result, 'plugin', array_get( $result, 'plugin_version', '' ) );
		if ( $remote_plugin && version_compare( $remote_plugin, '0.1.25', '<' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_version_too_old',
				sprintf(
					/* translators: 1: remote version, 2: required version. */
					__( 'Il live usa AG Sync Bridge %1$s. Aggiorna il plugin live almeno alla versione %2$s prima di fare push.', 'ag-sync-bridge' ),
					$remote_plugin,
					'0.1.25'
				),
				$result
			);
		}

		if ( $requires_partial_push && $remote_plugin && version_compare( $remote_plugin, '0.1.42', '<' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_version_too_old_for_partial',
				sprintf(
					/* translators: 1: remote version, 2: required version. */
					__( 'Il live usa AG Sync Bridge %1$s. Aggiorna il plugin live almeno alla versione %2$s prima di fare push selettivi.', 'ag-sync-bridge' ),
					$remote_plugin,
					'0.1.42'
				),
				$result
			);
		}

		if ( $requires_signed_backup && $remote_plugin && version_compare( $remote_plugin, '0.1.42', '<' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_version_too_old_for_signed_backup',
				sprintf(
					/* translators: 1: remote version, 2: required version. */
					__( 'Il live usa AG Sync Bridge %1$s. Aggiorna il plugin live almeno alla versione %2$s prima di usare backup pre-push firmati.', 'ag-sync-bridge' ),
					$remote_plugin,
					'0.1.42'
				),
				$result
			);
		}

		return $result;
	}

	private function estimate_remote_required_bytes( $use_existing_snapshot = false ) {
		unset( $use_existing_snapshot );

		$list   = $this->file_system->list_packages( 'snapshots', 50, false );
		$latest = array();
		foreach ( $list as $snapshot ) {
			if ( 'full' === array_get( $snapshot, 'snapshot_scope', '' ) ) {
				$latest = $snapshot;
				break;
			}
		}
		$size   = (int) array_get( $latest, 'size_bytes', 0 );
		$path   = (string) array_get( $latest, 'path', '' );

		if ( $size <= 0 && $path && file_exists( $path ) ) {
			$size = (int) filesize( $path );
		}

		if ( $size > 0 ) {
			return $size * 2;
		}

		return 268435456;
	}

	public function restore_local_backup( $reference, $custom_path = '' ) {
		$lock = $this->lock_manager->acquire( 'restore' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$cancellation_check = function ( $stage = '', $rollback_required = false ) {
				unset( $stage );
				return ! $rollback_required && $this->lock_manager->is_cancel_requested();
			};
			$this->update_operation( 'restore', 10, 'resolve-backup', __( 'Ricerca backup da ripristinare...', 'ag-sync-bridge' ) );
			$backup_path = $this->file_system->resolve_restore_package( $reference, $custom_path );
			if ( ! $backup_path ) {
				$error = new WP_Error( 'ag_sync_bridge_backup_missing', __( 'Selected backup was not found.', 'ag-sync-bridge' ) );
				$this->fail_operation( 'restore', $error );
				return $error;
			}

			$this->update_operation( 'restore', 25, 'pre-restore-backup', __( 'Creazione backup locale prima del ripristino...', 'ag-sync-bridge' ) );
			$pre_restore = $this->create_backup(
				'pre-restore-backup',
				array(
					'trigger'            => 'restore',
					'cancellation_check' => $cancellation_check,
				)
			);
			if ( is_wp_error( $pre_restore ) ) {
				$this->fail_operation( 'restore', $pre_restore );
				return $pre_restore;
			}

			$this->update_operation( 'restore', 70, 'import', __( 'Ripristino backup selezionato...', 'ag-sync-bridge' ) );
			$result = $this->importer->import_snapshot(
				$backup_path,
				array(
					'target_site_url' => site_url(),
					'target_home_url' => home_url(),
					'cancellation_check' => $cancellation_check,
				)
			);

			if ( is_wp_error( $result ) ) {
				$this->fail_operation( 'restore', $result );
				return $result;
			}

			$response = array(
				'pre_restore_backup' => $pre_restore,
				'restored_backup'    => basename( $backup_path ),
				'restored_path'      => $backup_path,
				'result'             => $result,
			);

			$this->config->set_state_value(
				'last_restore',
				array(
					'completed_at' => gmdate( 'c' ),
					'result'       => $response,
				)
			);

			$this->complete_operation( 'restore', __( 'Restore completed.', 'ag-sync-bridge' ) );
			$this->cleanup_local_runtime_storage( 'restore' );

			return $response;
		} finally {
			$this->lock_manager->release();
		}
	}

	private function update_operation( $operation, $progress, $stage, $message, $status = 'running' ) {
		$current = array_get( $this->config->get_state(), 'current_operation', array() );
		$state   = array(
			'operation'  => sanitize_key( $operation ),
			'status'     => sanitize_key( $status ),
			'stage'      => sanitize_key( $stage ),
			'progress'   => max( 0, min( 100, (int) $progress ) ),
			'message'    => (string) $message,
			'updated_at' => gmdate( 'c' ),
			'started_at' => array_get( $current, 'started_at', gmdate( 'c' ) ),
			'user_id'    => get_current_user_id(),
		);

		if ( in_array( $state['status'], array( 'complete', 'failed' ), true ) ) {
			$state['finished_at'] = gmdate( 'c' );
		}

		$this->config->set_state_value( 'current_operation', $state );
		$this->lock_manager->touch( $state );
	}

	private function complete_operation( $operation, $message ) {
		$this->update_operation( $operation, 100, 'complete', $message, 'complete' );
	}

	private function fail_operation( $operation, WP_Error $error ) {
		$error_data        = $error->get_error_data();
		$rollback_required = is_array( $error_data )
			&& ( ! empty( $error_data['rollback_required'] )
				|| 'rollback_required' === (string) array_get( $error_data, 'status', '' ) );
		$this->logger->error(
			ucfirst( sanitize_key( $operation ) ) . ' failed.',
			array(
				'message' => $error->get_error_message(),
				'data'    => $error_data,
				'rollback_required' => $rollback_required,
			)
		);
		$this->update_operation( $operation, (int) array_get( array_get( $this->config->get_state(), 'current_operation', array() ), 'progress', 0 ), 'error', $error->get_error_message(), 'failed' );
		if ( $rollback_required ) {
			$this->logger->warning(
				'Runtime cleanup skipped because target recovery is required.',
				array( 'operation' => sanitize_key( $operation ) )
			);
			return;
		}
		$this->cleanup_local_runtime_storage( 'failed-' . sanitize_key( $operation ) );

		if ( 'push' === sanitize_key( $operation ) ) {
			$this->cleanup_remote_runtime_storage( 'failed-push' );
		}
	}

	private function cleanup_local_runtime_storage( $context ) {
		$result = $this->file_system->cleanup_runtime_storage( null, null, 0 );
		$total  = array_get( $result, 'total', array() );

		$this->logger->info(
			'Local runtime cleanup completed.',
			array(
				'context'          => $context,
				'deleted_files'    => (int) array_get( $total, 'deleted_files', 0 ),
				'deleted_dirs'     => (int) array_get( $total, 'deleted_dirs', 0 ),
				'deleted_bytes'    => (int) array_get( $total, 'deleted_bytes', 0 ),
				'temp_min_hours'   => 0,
				'retention_count'  => (int) $this->config->get( 'retention_count', 1 ),
			)
		);
	}

	private function cleanup_remote_runtime_storage( $context ) {
		if ( ! $this->config->get_remote_url() || ! $this->config->get_secret() ) {
			return;
		}

		$result = $this->http_client->cleanup_remote_storage(
			array(
				'temp_hours' => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->warning(
				'Remote runtime cleanup failed.',
				array(
					'context' => $context,
					'error'   => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
			return;
		}

		$total = array_get( $result, 'total', array() );
		$this->logger->info(
			'Remote runtime cleanup completed.',
			array(
				'context'       => $context,
				'deleted_files' => (int) array_get( $total, 'deleted_files', 0 ),
				'deleted_dirs'  => (int) array_get( $total, 'deleted_dirs', 0 ),
				'deleted_bytes' => (int) array_get( $total, 'deleted_bytes', 0 ),
			)
		);
	}
}
