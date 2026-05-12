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

	public function __construct( Config $config, Logger $logger, Lock_Manager $lock_manager, File_System_Service $file_system, Export_Service $exporter, Import_Service $importer, Http_Client $http_client ) {
		$this->config       = $config;
		$this->logger       = $logger;
		$this->lock_manager = $lock_manager;
		$this->file_system  = $file_system;
		$this->exporter     = $exporter;
		$this->importer     = $importer;
		$this->http_client  = $http_client;
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
		return $this->exporter->create_snapshot( $type, $context );
	}

	public function create_backup( $type = 'manual-backup', array $context = array() ) {
		return $this->exporter->create_snapshot( $type, $context );
	}

	public function pull_from_remote( array $args = array() ) {
		$lock = $this->lock_manager->acquire( 'pull' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$trigger              = sanitize_key( (string) array_get( $args, 'trigger', 'manual' ) );
			$use_existing_snapshot = ! empty( $args['use_existing_snapshot'] );

			$this->logger->info( 'Pull started.', array( 'remote_url' => $this->config->get_remote_url(), 'trigger' => $trigger, 'use_existing_snapshot' => $use_existing_snapshot ) );
			$this->update_operation( 'pull', 5, 'local-backup', __( 'Creazione backup locale di sicurezza...', 'ag-sync-bridge' ) );
			$local_backup = $this->create_backup(
				'pre-pull-backup',
				array(
					'trigger' => $trigger ? $trigger . '-pull' : 'pull',
				)
			);
			if ( is_wp_error( $local_backup ) ) {
				$this->fail_operation( 'pull', $local_backup );
				return $local_backup;
			}

			$this->logger->info( 'Local pre-pull backup completed.', array( 'backup' => array_get( $local_backup, 'basename', '' ) ) );
			$this->update_operation( 'pull', 20, 'remote-snapshot', $use_existing_snapshot ? __( 'Recupero snapshot disponibile dal live...', 'ag-sync-bridge' ) : __( 'Richiesta snapshot dal live...', 'ag-sync-bridge' ) );
			$remote_snapshot = $use_existing_snapshot ? $this->http_client->get_latest_snapshot() : $this->http_client->create_remote_snapshot( 'manual-pull-snapshot' );

			if ( $use_existing_snapshot && ( is_wp_error( $remote_snapshot ) || empty( $remote_snapshot ) ) ) {
				$this->logger->warning( 'No reusable remote snapshot was available. Falling back to fresh remote snapshot creation.', array( 'trigger' => $trigger ) );
				$remote_snapshot = $this->http_client->create_remote_snapshot( 'auto-pull-snapshot' );
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
			$downloaded    = $this->http_client->download_snapshot( array_get( $remote_snapshot, 'basename', '' ), $download_path );
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
			$use_existing_snapshot = ! empty( $args['use_existing_snapshot'] );
			$skip_remote_backup    = ! empty( $args['skip_remote_backup'] );

			$this->logger->info( 'Push started.', array( 'remote_url' => $this->config->get_remote_url(), 'use_existing_snapshot' => $use_existing_snapshot, 'skip_remote_backup' => $skip_remote_backup ) );
			$remote_backup = array( 'skipped' => true );

			if ( ! $skip_remote_backup ) {
				$this->update_operation( 'push', 5, 'remote-backup', __( 'Creazione backup di sicurezza sul live...', 'ag-sync-bridge' ) );
				$remote_backup = $this->http_client->create_remote_backup();
				if ( is_wp_error( $remote_backup ) ) {
					$this->fail_operation( 'push', $remote_backup );
					return $remote_backup;
				}

				$this->logger->info( 'Remote pre-push backup completed.', array( 'backup' => array_get( $remote_backup, 'basename', '' ) ) );
			}

			$this->update_operation( 'push', 25, 'local-snapshot', $use_existing_snapshot ? __( 'Riutilizzo ultimo snapshot locale...', 'ag-sync-bridge' ) : __( 'Creazione snapshot completo del locale...', 'ag-sync-bridge' ) );
			$local_snapshot = $use_existing_snapshot ? $this->get_latest_local_snapshot() : array();

			if ( empty( $local_snapshot ) ) {
				$local_snapshot = $this->create_snapshot(
					'manual-push-snapshot',
					array(
						'trigger' => 'push',
					)
				);
			}

			if ( is_wp_error( $local_snapshot ) ) {
				$this->fail_operation( 'push', $local_snapshot );
				return $local_snapshot;
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
				}
			);
			if ( is_wp_error( $upload ) ) {
				$this->fail_operation( 'push', $upload );
				return $upload;
			}

			$this->logger->info( 'Snapshot uploaded to live.', array( 'snapshot' => array_get( $upload, 'snapshot', '' ) ) );
			$this->update_operation( 'push', 85, 'remote-import', __( 'Import snapshot sul live e replace URL...', 'ag-sync-bridge' ) );
			$remote_import = $this->http_client->trigger_remote_import( array_get( $upload, 'snapshot', '' ), array_get( $upload, 'sha256', '' ) );
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
					'upload'        => $upload,
					'remote_import' => $remote_import,
				)
			);

			return array(
				'remote_backup' => $remote_backup,
				'local_snapshot'=> $local_snapshot,
				'upload'        => $upload,
				'remote_import' => $remote_import,
			);
		} finally {
			$this->lock_manager->release();
		}
	}

	private function get_latest_local_snapshot() {
		$list = $this->file_system->list_packages( 'snapshots', 1, true );
		return empty( $list ) ? array() : $list[0];
	}

	public function restore_local_backup( $reference, $custom_path = '' ) {
		$lock = $this->lock_manager->acquire( 'restore' );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
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
					'trigger' => 'restore',
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

		$this->config->set_state_value( 'current_operation', $state );
		$this->lock_manager->touch( $state );
	}

	private function fail_operation( $operation, WP_Error $error ) {
		$this->logger->error(
			ucfirst( sanitize_key( $operation ) ) . ' failed.',
			array(
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			)
		);
		$this->update_operation( $operation, (int) array_get( array_get( $this->config->get_state(), 'current_operation', array() ), 'progress', 0 ), 'error', $error->get_error_message(), 'failed' );
	}
}
