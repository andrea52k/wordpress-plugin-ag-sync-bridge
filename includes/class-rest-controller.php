<?php
namespace AGSyncBridge;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller {
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
	 * @var Export_Service
	 */
	private $exporter;

	/**
	 * @var Import_Service
	 */
	private $importer;

	/**
	 * @var Sync_Service
	 */
	private $sync;

	/**
	 * @var Auth
	 */
	private $auth;

	/** @var Remote_Operation_Runtime */
	private $runtime;

	public function __construct( Config $config, Logger $logger, File_System_Service $file_system, Export_Service $exporter, Import_Service $importer, Sync_Service $sync, Auth $auth, Remote_Operation_Runtime $runtime ) {
		$this->config      = $config;
		$this->logger      = $logger;
		$this->file_system = $file_system;
		$this->exporter    = $exporter;
		$this->importer    = $importer;
		$this->sync        = $sync;
		$this->auth        = $auth;
		$this->runtime     = $runtime;
	}

	public function register_routes() {
		register_rest_route(
			'ag-sync-bridge/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/doctor',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'doctor' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/latest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'latest_snapshot' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_snapshot' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/backup/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_backup' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_snapshot' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_snapshot_chunk' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/upload-finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'finish_chunked_upload' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/upload-abort',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'abort_chunked_upload' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_snapshot' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/operation/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'operation_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/operation/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_operation' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/operation/run-pending-import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_pending_import' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/maintenance/cleanup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanup_storage' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_snapshot' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/download-chunk',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_snapshot_chunk' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'ag-sync-bridge/v1',
			'/snapshot/download-raw-chunk',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_snapshot_raw_chunk' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function doctor( WP_REST_Request $request ) {
		$required_bytes = max( 0, (int) $request->get_param( 'required_bytes' ) );
		$deep           = (bool) $request->get_param( 'deep' );
		$diagnostics    = $this->file_system->diagnose_runtime_storage( $required_bytes, $deep );
		$diagnostics['site_url']  = site_url();
		$diagnostics['home_url']  = home_url();
		$diagnostics['role']      = $this->config->get_role();
		$diagnostics['plugin']    = AG_SYNC_BRIDGE_VERSION;
		$diagnostics['wordpress'] = get_bloginfo( 'version' );

		return new WP_REST_Response( $diagnostics );
	}

	public function check_permission( WP_REST_Request $request ) {
		return $this->auth->verify_rest_request( $request );
	}

	public function status() {
		$next_schedule = wp_next_scheduled( Scheduler::HOOK_WEEKLY_SNAPSHOT );

		return new WP_REST_Response(
			array(
				'site_url'         => site_url(),
				'home_url'         => home_url(),
				'role'             => $this->config->get_role(),
				'role_source'      => $this->config->get_role_source(),
				'plugin'           => AG_SYNC_BRIDGE_VERSION,
				'wordpress'        => get_bloginfo( 'version' ),
				'php'              => PHP_VERSION,
				'latest_snapshot' => $this->exporter->get_latest_snapshot(),
				'schedule'         => array(
					'active'    => (bool) $next_schedule,
					'hook'      => Scheduler::HOOK_WEEKLY_SNAPSHOT,
					'next_run'  => $next_schedule ? gmdate( 'c', (int) $next_schedule ) : '',
				),
				'state'            => $this->config->get_state(),
			)
		);
	}

	public function latest_snapshot() {
		$latest = $this->exporter->get_latest_snapshot();

		if ( empty( $latest ) ) {
			return new WP_Error( 'ag_sync_bridge_snapshot_missing', __( 'No snapshot is currently available.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $latest );
	}

	public function create_snapshot( WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$type   = $type ?: 'manual-remote-snapshot';
		$async  = (bool) $request->get_param( 'async' );

		if ( $async ) {
			$operation_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( (string) wp_rand(), true ) );
			$operation    = array(
				'id'         => $operation_id,
				'type'       => $type,
				'status'     => 'queued',
				'stage'      => 'remote-snapshot',
				'started_at' => gmdate( 'c' ),
				'updated_at' => gmdate( 'c' ),
				'schedule_args' => array( $operation_id, $type ),
			);

			$operation = $this->runtime->reserve( 'snapshot', $operation );
			if ( is_wp_error( $operation ) ) {
				return $operation;
			}
			$this->config->set_state_value( 'remote_snapshot_operation', $operation );
			$this->logger->info( 'Remote async snapshot queued.', $operation );

			$scheduled = wp_schedule_single_event( time() + 1, Scheduler::HOOK_ASYNC_SNAPSHOT, array( $operation_id, $type ) );
			if ( false === $scheduled ) {
				$operation['status']      = 'error';
				$operation['message']     = __( 'Unable to schedule remote async snapshot.', 'ag-sync-bridge' );
				$operation['updated_at']  = gmdate( 'c' );
				$operation['finished_at'] = gmdate( 'c' );
				$operation = $this->runtime->finalize( $operation_id, 'error', $operation );
				$this->config->set_state_value( 'remote_snapshot_operation', $operation );
				return new WP_Error(
					'ag_sync_bridge_remote_snapshot_schedule_failed',
					__( 'Unable to schedule remote async snapshot.', 'ag-sync-bridge' ),
					array( 'status' => 500 )
				);
			}

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron( time() );
			}

			return new WP_REST_Response(
				array(
					'accepted'     => true,
					'operation_id' => $operation_id,
					'type'         => $type,
				),
				202
			);
		}

		$result = $this->sync->create_snapshot(
			$type,
			array(
				'trigger' => 'remote-api',
			)
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function create_backup( WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$type   = $type ?: 'remote-backup';

		if ( ! $this->config->get( 'remote_backups_enabled', false ) ) {
			$result = array(
				'skipped'    => true,
				'reason'     => 'remote_backups_disabled',
				'type'       => $type,
				'skipped_at' => gmdate( 'c' ),
			);
			$this->logger->info( 'Remote backup request skipped because remote backups are disabled.', $result );
			return new WP_REST_Response( $result );
		}

		$result = $this->sync->create_backup(
			$type,
			array(
				'trigger' => 'remote-api',
			)
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function run_async_create_snapshot( $operation_id, $type ) {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '-1' );

		$type      = sanitize_key( (string) $type );
		$type      = $type ?: 'manual-remote-snapshot';
		$operation = $this->runtime->claim( $operation_id );
		if ( is_wp_error( $operation ) ) {
			return;
		}

		$operation = array_merge( $operation, array(
			'id'         => $operation_id,
			'type'       => $type,
			'status'     => 'running',
			'stage'      => 'remote-snapshot',
			'started_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		) );
		$this->config->set_state_value( 'remote_snapshot_operation', $operation );

		$result = $this->sync->create_snapshot(
			$type,
			array(
				'trigger' => 'remote-api-async',
			)
		);

		if ( $this->runtime->is_cancel_requested( $operation_id ) ) {
			$operation = $this->runtime->finalize( $operation_id, 'cancelled' );
			$this->config->set_state_value( 'remote_snapshot_operation', $operation );
			$this->file_system->cleanup_runtime_storage( null, null, 0 );
			return;
		}

		if ( is_wp_error( $result ) ) {
			$operation['status']      = 'error';
			$operation['updated_at']  = gmdate( 'c' );
			$operation['finished_at'] = gmdate( 'c' );
			$operation['message']     = $result->get_error_message();
			$operation['data']        = $result->get_error_data();
			$operation = $this->runtime->finalize( $operation_id, 'error', $operation );
			$this->config->set_state_value( 'remote_snapshot_operation', $operation );
			$this->file_system->cleanup_runtime_storage( null, null, 0 );
			$this->logger->error( 'Remote async snapshot failed.', $operation );
			return;
		}

		$operation['status']      = 'complete';
		$operation['updated_at']  = gmdate( 'c' );
		$operation['finished_at'] = gmdate( 'c' );
		$operation['result']      = $result;
		$operation = $this->runtime->finalize( $operation_id, 'complete', $operation );
		$this->config->set_state_value( 'remote_snapshot_operation', $operation );
		$this->file_system->cleanup_runtime_storage( null, null, 0 );
		$this->logger->info( 'Remote async snapshot completed.', $operation );
	}

	public function upload_snapshot( WP_REST_Request $request ) {
		$this->file_system->prepare_runtime_dirs();

		$incoming_dir = $this->file_system->get_incoming_dir();
		$files        = $request->get_file_params();
		$file_path    = '';
		$filename     = '';

		if ( ! empty( $files['snapshot_file']['tmp_name'] ) ) {
			$filename = sanitize_file_name( $files['snapshot_file']['name'] );
			$file_path = normalize_path( $incoming_dir . '/' . gmdate( 'Ymd-His' ) . '-' . $filename );
			copy( $files['snapshot_file']['tmp_name'], $file_path );
		} else {
			$filename = sanitize_file_name( (string) $request->get_header( 'x-agsb-filename' ) );
			$filename = $filename ?: 'incoming-snapshot.zip';
			$file_path = normalize_path( $incoming_dir . '/' . gmdate( 'Ymd-His' ) . '-' . $filename );
			file_put_contents( $file_path, $request->get_body(), LOCK_EX );
		}

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'ag_sync_bridge_upload_missing', __( 'No snapshot payload was uploaded.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$sha256       = hash_file( 'sha256', $file_path );
		$expected     = (string) $request->get_header( 'x-agsb-sha256' );
		$multipart    = $request->get_param( 'snapshot_meta' );
		$meta_header  = rawurldecode( (string) $request->get_header( 'x-agsb-meta' ) );
		$meta_payload = $multipart ? $multipart : $meta_header;
		$meta         = json_decode( (string) $meta_payload, true );
		$meta         = is_array( $meta ) ? $meta : array();
		$expected     = $expected ?: array_get( $meta, 'sha256', '' );

		if ( $expected && ! hash_equals( strtolower( $expected ), strtolower( $sha256 ) ) ) {
			$this->file_system->cleanup_path( $file_path );
			return new WP_Error( 'ag_sync_bridge_upload_checksum', __( 'Uploaded snapshot checksum failed.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$response = array(
			'snapshot'   => basename( $file_path ),
			'size_bytes' => filesize( $file_path ),
			'sha256'     => $sha256,
			'uploaded_at'=> gmdate( 'c' ),
			'meta'       => $meta,
		);

		$this->logger->info( 'Remote snapshot uploaded.', $response );
		return new WP_REST_Response( $response );
	}

	public function upload_snapshot_chunk( WP_REST_Request $request ) {
		$this->file_system->prepare_runtime_dirs();

		$upload_id    = sanitize_key( (string) $request->get_header( 'x-agsb-upload-id' ) );
		$filename     = sanitize_file_name( (string) $request->get_header( 'x-agsb-filename' ) );
		$chunk_index  = absint( $request->get_header( 'x-agsb-chunk-index' ) );
		$total_chunks = max( 1, absint( $request->get_header( 'x-agsb-total-chunks' ) ) );
		$chunk_sha256 = strtolower( sanitize_text_field( (string) $request->get_header( 'x-agsb-chunk-sha256' ) ) );

		if ( '' === $upload_id || '' === $filename ) {
			return new WP_Error( 'ag_sync_bridge_chunk_headers_missing', __( 'Chunk upload headers are incomplete.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$body      = '';
		$chunk_b64 = (string) $request->get_param( 'chunk_b64' );
		if ( '' !== $chunk_b64 ) {
			$decoded = base64_decode( $chunk_b64, true );
			if ( false === $decoded ) {
				return new WP_Error( 'ag_sync_bridge_chunk_decode_failed', __( 'Chunk body is not valid base64.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
			}
			$body = $decoded;
		} else {
			$body = $request->get_body();
		}

		if ( '' === $body ) {
			return new WP_Error( 'ag_sync_bridge_chunk_body_missing', __( 'Chunk body is empty.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$calculated_sha = hash( 'sha256', $body );
		if ( $chunk_sha256 && ! hash_equals( $chunk_sha256, strtolower( $calculated_sha ) ) ) {
			return new WP_Error( 'ag_sync_bridge_chunk_checksum_failed', __( 'Chunk checksum verification failed.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$chunk_dir = $this->get_chunk_upload_dir( $upload_id );
		if ( ! ensure_directory( $chunk_dir ) ) {
			return new WP_Error(
				'ag_sync_bridge_chunk_dir_failed',
				__( 'Unable to prepare chunk upload directory.', 'ag-sync-bridge' ),
				array(
					'status'      => 500,
					'chunk_dir'   => $chunk_dir,
					'diagnostics' => $this->file_system->diagnose_runtime_storage( strlen( $body ) ),
				)
			);
		}

		$chunk_path = normalize_path( $chunk_dir . '/' . sprintf( 'chunk-%05d.part', $chunk_index ) );
		$meta_path  = normalize_path( $chunk_dir . '/upload.json' );
		$written    = file_put_contents( $chunk_path, $body, LOCK_EX );

		if ( false === $written ) {
			return new WP_Error(
				'ag_sync_bridge_chunk_write_failed',
				__( 'Unable to store uploaded chunk.', 'ag-sync-bridge' ),
				array(
					'status'       => 500,
					'chunk_path'   => $chunk_path,
					'chunk_dir'    => $chunk_dir,
					'chunk_index'  => $chunk_index,
					'total_chunks' => $total_chunks,
					'chunk_bytes'  => strlen( $body ),
					'php_error'    => error_get_last(),
					'diagnostics'  => $this->file_system->diagnose_runtime_storage( strlen( $body ) ),
				)
			);
		}

		if ( strlen( $body ) !== (int) $written || ! file_exists( $chunk_path ) ) {
			return new WP_Error(
				'ag_sync_bridge_chunk_write_incomplete',
				__( 'Uploaded chunk was not fully written.', 'ag-sync-bridge' ),
				array(
					'status'        => 500,
					'chunk_path'    => $chunk_path,
					'chunk_dir'     => $chunk_dir,
					'chunk_index'   => $chunk_index,
					'total_chunks'  => $total_chunks,
					'chunk_bytes'   => strlen( $body ),
					'written_bytes' => (int) $written,
					'diagnostics'   => $this->file_system->diagnose_runtime_storage( strlen( $body ) ),
				)
			);
		}

		$this->file_system->write_json_file(
			$meta_path,
			array(
				'upload_id'     => $upload_id,
				'filename'      => $filename,
				'total_chunks'  => $total_chunks,
				'updated_at'    => gmdate( 'c' ),
			)
		);

		return new WP_REST_Response(
			array(
				'upload_id'     => $upload_id,
				'chunk_index'   => $chunk_index,
				'total_chunks'  => $total_chunks,
				'size_bytes'    => $written,
				'stored_at'     => gmdate( 'c' ),
			)
		);
	}

	public function abort_chunked_upload( WP_REST_Request $request ) {
		$upload_id = sanitize_key( (string) $request->get_param( 'upload_id' ) );

		if ( '' === $upload_id ) {
			return new WP_Error( 'ag_sync_bridge_chunk_abort_missing', __( 'Chunked upload cannot be aborted without an upload id.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$chunk_dir = $this->get_chunk_upload_dir( $upload_id );
		$existed   = file_exists( $chunk_dir );
		$deleted   = $this->file_system->cleanup_path( $chunk_dir );

		return new WP_REST_Response(
			array(
				'upload_id' => $upload_id,
				'path'      => $chunk_dir,
				'existed'   => $existed,
				'deleted'   => (bool) $deleted,
			)
		);
	}

	public function finish_chunked_upload( WP_REST_Request $request ) {
		$this->file_system->prepare_runtime_dirs();

		$upload_id       = sanitize_key( (string) $request->get_param( 'upload_id' ) );
		$filename        = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$expected_sha256 = strtolower( sanitize_text_field( (string) $request->get_param( 'expected_sha256' ) ) );
		$total_chunks    = max( 1, absint( $request->get_param( 'total_chunks' ) ) );
		$meta            = $request->get_param( 'meta' );
		$meta            = is_array( $meta ) ? $meta : array();

		if ( '' === $upload_id || '' === $filename ) {
			return new WP_Error( 'ag_sync_bridge_chunk_finish_missing', __( 'Chunked upload cannot be finalized without upload metadata.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$chunk_dir = $this->get_chunk_upload_dir( $upload_id );
		if ( ! is_dir( $chunk_dir ) ) {
			return new WP_Error( 'ag_sync_bridge_chunk_upload_missing', __( 'Temporary chunk upload directory was not found.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		$incoming_dir = $this->file_system->get_incoming_dir();
		$file_path    = normalize_path( $incoming_dir . '/' . gmdate( 'Ymd-His' ) . '-' . $filename );
		$handle       = fopen( $file_path, 'wb' );

		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_chunk_target_failed', __( 'Unable to prepare final uploaded snapshot file.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
		}

		try {
			for ( $index = 0; $index < $total_chunks; $index++ ) {
				$chunk_path = normalize_path( $chunk_dir . '/' . sprintf( 'chunk-%05d.part', $index ) );

				if ( ! file_exists( $chunk_path ) ) {
					fclose( $handle );
					$this->file_system->cleanup_path( $file_path );
					return new WP_Error(
						'ag_sync_bridge_chunk_missing',
						sprintf(
							/* translators: %d: chunk index */
							__( 'Missing uploaded chunk %d.', 'ag-sync-bridge' ),
							$index + 1
						),
						array( 'status' => 400 )
					);
				}

				$chunk_handle = fopen( $chunk_path, 'rb' );
				if ( false === $chunk_handle ) {
					fclose( $handle );
					$this->file_system->cleanup_path( $file_path );
					return new WP_Error( 'ag_sync_bridge_chunk_open_failed', __( 'Unable to read stored uploaded chunk.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
				}

				while ( ! feof( $chunk_handle ) ) {
					$buffer = fread( $chunk_handle, 1048576 );

					if ( false === $buffer ) {
						fclose( $chunk_handle );
						fclose( $handle );
						$this->file_system->cleanup_path( $file_path );
						return new WP_Error( 'ag_sync_bridge_chunk_read_failed', __( 'Unable to read stored uploaded chunk.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
					}

					if ( '' !== $buffer && false === fwrite( $handle, $buffer ) ) {
						fclose( $chunk_handle );
						fclose( $handle );
						$this->file_system->cleanup_path( $file_path );
						return new WP_Error( 'ag_sync_bridge_chunk_merge_failed', __( 'Unable to assemble uploaded snapshot file.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
					}
				}

				fclose( $chunk_handle );
			}
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}

		$sha256 = hash_file( 'sha256', $file_path );
		if ( $expected_sha256 && ! hash_equals( $expected_sha256, strtolower( $sha256 ) ) ) {
			$this->file_system->cleanup_path( $file_path );
			$this->file_system->cleanup_path( $chunk_dir );
			return new WP_Error( 'ag_sync_bridge_upload_checksum', __( 'Uploaded snapshot checksum failed.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$this->file_system->cleanup_path( $chunk_dir );

		$response = array(
			'snapshot'    => basename( $file_path ),
			'size_bytes'  => filesize( $file_path ),
			'sha256'      => $sha256,
			'uploaded_at' => gmdate( 'c' ),
			'meta'        => $meta,
		);

		$this->logger->info( 'Remote snapshot uploaded via chunks.', $response );
		return new WP_REST_Response( $response );
	}

	public function import_snapshot( WP_REST_Request $request ) {
		$snapshot = sanitize_file_name( (string) $request->get_param( 'snapshot' ) );
		$sha256   = sanitize_text_field( (string) $request->get_param( 'expected_sha256' ) );
		$path     = normalize_path( $this->file_system->get_incoming_dir() . '/' . $snapshot );
		$async    = (bool) $request->get_param( 'async' );
		$allow_partial_snapshot = (bool) $request->get_param( 'allow_partial_snapshot' );

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_import_missing', __( 'Uploaded snapshot file is missing.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! $allow_partial_snapshot ) {
			$validation = $this->file_system->validate_full_snapshot_package( $path );
			if ( is_wp_error( $validation ) ) {
				return new WP_Error(
					$validation->get_error_code(),
					$validation->get_error_message(),
					array(
						'status'     => 400,
						'validation' => $validation->get_error_data(),
					)
				);
			}
		} else {
			$this->logger->warning(
				'Remote import accepted with partial snapshot override.',
				array(
					'snapshot' => $snapshot,
				)
			);
		}

		if ( $async ) {
			$operation_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( (string) wp_rand(), true ) );
			$operation    = array(
				'id'         => $operation_id,
				'snapshot'   => $snapshot,
				'status'     => 'queued',
				'stage'      => 'remote-import',
				'started_at' => gmdate( 'c' ),
				'updated_at' => gmdate( 'c' ),
				'schedule_args' => array( $operation_id, $path, $sha256 ),
			);

			$operation = $this->runtime->reserve( 'import', $operation );
			if ( is_wp_error( $operation ) ) {
				return $operation;
			}
			$this->config->set_state_value( 'remote_import_operation', $operation );
			$this->logger->info( 'Remote async import queued.', $operation );

			$scheduled = wp_schedule_single_event( time() + 1, Scheduler::HOOK_ASYNC_IMPORT, array( $operation_id, $path, $sha256 ) );
			if ( false === $scheduled ) {
				$operation['status']      = 'error';
				$operation['message']     = __( 'Unable to schedule remote async import.', 'ag-sync-bridge' );
				$operation['updated_at']  = gmdate( 'c' );
				$operation['finished_at'] = gmdate( 'c' );
				$this->config->set_state_value( 'remote_import_operation', $operation );
				return new WP_Error(
					'ag_sync_bridge_remote_import_schedule_failed',
					__( 'Unable to schedule remote async import.', 'ag-sync-bridge' ),
					array( 'status' => 500 )
				);
			}

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron( time() );
			}

			return new WP_REST_Response(
				array(
					'accepted'     => true,
					'operation_id' => $operation_id,
					'snapshot'     => $snapshot,
				),
				202
			);
		}

		$result = $this->importer->import_snapshot(
			$path,
			array(
				'expected_sha256' => $sha256,
				'target_site_url' => site_url(),
				'target_home_url' => home_url(),
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$this->file_system->cleanup_path( $path );
		}

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	public function run_async_import_snapshot( $operation_id, $path, $sha256 ) {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '-1' );

		$operation = $this->runtime->claim( $operation_id );
		if ( is_wp_error( $operation ) ) {
			return;
		}

		$operation = array_merge( $operation, array(
			'id'         => $operation_id,
			'snapshot'   => basename( $path ),
			'status'     => 'running',
			'stage'      => 'remote-import',
			'started_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		) );
		$this->config->set_state_value( 'remote_import_operation', $operation );

		$result = $this->importer->import_snapshot(
			$path,
			array(
				'expected_sha256' => $sha256,
				'target_site_url' => site_url(),
				'target_home_url' => home_url(),
				'cancellation_check' => function ( $stage, $rollback_required ) use ( $operation_id ) {
					return $this->runtime->is_cancel_requested( $operation_id );
				},
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'ag_sync_bridge_operation_cancelled' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				$operation = $this->runtime->finalize( $operation_id, 'cancelled', array( 'rollback_required' => is_array( $data ) && ! empty( $data['rollback_required'] ), 'message' => $result->get_error_message() ) );
				$this->config->set_state_value( 'remote_import_operation', $operation );
				$this->file_system->cleanup_path( $path );
				$this->file_system->cleanup_runtime_storage( null, null, 0 );
				return;
			}
			$operation['status']     = 'error';
			$operation['updated_at'] = gmdate( 'c' );
			$operation['finished_at'] = gmdate( 'c' );
			$operation['message']    = $result->get_error_message();
			$operation['data']       = $result->get_error_data();
			$operation = $this->runtime->finalize( $operation_id, 'error', $operation );
			$this->config->set_state_value( 'remote_import_operation', $operation );
			$this->file_system->cleanup_path( $path );
			$this->file_system->cleanup_runtime_storage( null, null, 0 );
			$this->logger->error( 'Remote async import failed.', $operation );
			return;
		}

		$this->file_system->cleanup_path( $path );

		$operation['status']     = 'complete';
		$operation['updated_at'] = gmdate( 'c' );
		$operation['finished_at'] = gmdate( 'c' );
		$operation['result']     = $result;
		$operation = $this->runtime->finalize( $operation_id, 'complete', array_merge( $operation, array( 'rollback_required' => true ) ) );
		$this->config->set_state_value( 'remote_import_operation', $operation );
		$this->file_system->cleanup_runtime_storage( null, null, 0 );
		$this->logger->info( 'Remote async import completed.', $operation );
	}

	public function operation_status() {
		$state = $this->config->get_state();
		$current_operation = $this->runtime->get();
		if ( is_array( $current_operation ) && ! empty( $current_operation['id'] ) ) {
			$state_key = 'snapshot' === array_get( $current_operation, 'kind', '' ) ? 'remote_snapshot_operation' : 'remote_import_operation';
			$state[ $state_key ] = $current_operation;
		}
		foreach ( array( 'remote_snapshot_operation', 'remote_import_operation' ) as $key ) {
			if ( ! empty( $state[ $key ] ) && is_array( $state[ $key ] ) ) {
				$state[ $key ]['cancellable'] = ! $this->is_terminal_operation( $state[ $key ] );
			}
		}
		return new WP_REST_Response( $state );
	}

	/**
	 * Cancel exactly one remote async operation. Queued jobs are unscheduled;
	 * running jobs receive a cooperative request and report recovery honestly.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_operation( WP_REST_Request $request ) {
		$operation_id = sanitize_text_field( (string) $request->get_param( 'operation_id' ) );
		$kind         = sanitize_key( (string) $request->get_param( 'kind' ) );
		$state_key    = 'snapshot' === $kind ? 'remote_snapshot_operation' : ( 'import' === $kind ? 'remote_import_operation' : '' );

		if ( ! $operation_id || ! $state_key ) {
			return new WP_Error( 'ag_sync_bridge_cancel_invalid_request', __( 'Operation ID and kind (snapshot or import) are required.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$operation = $this->runtime->request_cancel( $operation_id, $kind );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		if ( 'cancelled' === (string) array_get( $operation, 'status', '' ) ) {
			$this->unschedule_operation( $kind, $operation );
			if ( 'import' === $kind ) {
				$this->file_system->cleanup_path( normalize_path( $this->file_system->get_incoming_dir() . '/' . sanitize_file_name( (string) array_get( $operation, 'snapshot', '' ) ) ) );
			}
		}

		$operation['stage'] = 'cancelled' === (string) array_get( $operation, 'status', '' ) ? 'cancelled' : 'cancel_requested';
		$operation['message'] = 'cancelled' === (string) array_get( $operation, 'status', '' ) ? __( 'Queued operation cancelled.', 'ag-sync-bridge' ) : __( 'Cancellation requested. The worker will stop at the next safe checkpoint.', 'ag-sync-bridge' );
		$this->config->set_state_value( $state_key, $operation );
		$this->logger->warning( 'Remote operation cancellation updated.', $operation );

		return new WP_REST_Response( array( 'operation' => array_get( $this->config->get_state(), $state_key, array() ) ) );
	}

	private function is_terminal_operation( array $operation ) {
		return in_array( (string) array_get( $operation, 'status', '' ), array( 'complete', 'error', 'cancelled', 'rollback_required' ), true );
	}

	private function is_cancel_requested( array $operation ) {
		return 'cancel_requested' === (string) array_get( $operation, 'status', '' );
	}

	private function mark_operation_cancelled( $state_key, array $operation, $rollback_required = false, $message = '' ) {
		$current = array_get( $this->config->get_state(), $state_key, array() );
		if ( ! empty( $current ) && (string) array_get( $current, 'id', '' ) === (string) array_get( $operation, 'id', '' ) ) {
			$operation = array_merge( $operation, $current );
		}

		$operation['status']      = $rollback_required ? 'rollback_required' : 'cancelled';
		$operation['stage']       = $rollback_required ? 'recovery-required' : 'cancelled';
		$operation['updated_at']  = gmdate( 'c' );
		$operation['finished_at'] = gmdate( 'c' );
		$operation['rollback_required'] = (bool) $rollback_required;
		$operation['message']     = $message ?: ( $rollback_required
			? __( 'Cancellation reached a changed target. Restore the pre-import backup before treating the site as healthy.', 'ag-sync-bridge' )
			: __( 'Operation cancelled before target data changed.', 'ag-sync-bridge' ) );
		$this->config->set_state_value( $state_key, $operation );
		$this->logger->warning( 'Remote operation cancelled.', $operation );
	}

	private function unschedule_operation( $kind, array $operation ) {
		$hook = 'snapshot' === $kind ? Scheduler::HOOK_ASYNC_SNAPSHOT : Scheduler::HOOK_ASYNC_IMPORT;
		$args = array_get( $operation, 'schedule_args', array() );
		$args = is_array( $args ) ? $args : array();
		if ( empty( $args ) ) {
			return;
		}

		while ( $scheduled = wp_next_scheduled( $hook, $args ) ) {
			wp_unschedule_event( $scheduled, $hook, $args );
		}
	}

	/**
	 * Run an authenticated import that stayed queued because WP-Cron did not start.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_pending_import( WP_REST_Request $request ) {
		$operation_id = sanitize_text_field( (string) $request->get_param( 'operation_id' ) );
		$operation    = $this->runtime->get();

		if ( is_wp_error( $operation ) || empty( $operation ) || 'import' !== (string) array_get( $operation, 'kind', '' ) || $operation_id !== (string) array_get( $operation, 'id', '' ) ) {
			return new WP_Error( 'ag_sync_bridge_pending_import_mismatch', __( 'Pending import operation does not match.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
		}

		if ( 'queued' !== (string) array_get( $operation, 'status', '' ) ) {
			return new WP_REST_Response( array( 'remote_import_operation' => $operation ) );
		}

		$snapshot = sanitize_file_name( (string) array_get( $operation, 'snapshot', '' ) );
		$path     = normalize_path( $this->file_system->get_incoming_dir() . '/' . $snapshot );
		if ( ! $snapshot || ! is_file( $path ) ) {
			return new WP_Error( 'ag_sync_bridge_pending_import_missing', __( 'Pending import package is missing.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		$sha256 = hash_file( 'sha256', $path );
		$args   = array( $operation_id, $path, $sha256 );
		$next   = wp_next_scheduled( Scheduler::HOOK_ASYNC_IMPORT, $args );
		if ( $next ) {
			wp_unschedule_event( $next, Scheduler::HOOK_ASYNC_IMPORT, $args );
		}

		$this->logger->warning( 'Running queued remote import through authenticated recovery.', array( 'operation_id' => $operation_id, 'snapshot' => $snapshot ) );
		$this->run_async_import_snapshot( $operation_id, $path, $sha256 );

		return new WP_REST_Response(
			array(
				'remote_import_operation' => array_get( $this->config->get_state(), 'remote_import_operation', array() ),
			)
		);
	}

	public function cleanup_storage( WP_REST_Request $request ) {
		$result = $this->file_system->cleanup_runtime_storage(
			$request->get_param( 'snapshots' ),
			$request->get_param( 'backups' ),
			$request->get_param( 'temp_hours' )
		);

		$this->logger->info( 'Runtime storage cleanup completed.', array_get( $result, 'total', array() ) );

		return new WP_REST_Response( $result );
	}

	public function download_snapshot( WP_REST_Request $request ) {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '-1' );

		$snapshot = sanitize_file_name( (string) $request->get_param( 'snapshot' ) );
		$path     = $this->file_system->find_package( $snapshot, 'snapshots' );

		if ( ! $path ) {
			return new WP_Error( 'ag_sync_bridge_download_missing', __( 'Requested snapshot was not found.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_download_open_failed', __( 'Unable to open requested snapshot.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
		}

		$this->clear_output_buffers();
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'X-AGSB-Download: streaming' );

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 );
			flush();
		}

		fclose( $handle );
		exit;
	}

	public function download_snapshot_chunk( WP_REST_Request $request ) {
		$snapshot = sanitize_file_name( (string) $request->get_param( 'snapshot' ) );
		$path     = $this->file_system->find_package( $snapshot, 'snapshots' );

		if ( ! $path ) {
			return new WP_Error( 'ag_sync_bridge_download_missing', __( 'Requested snapshot was not found.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		$size   = (int) filesize( $path );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$length = max( 1, min( 8388608, (int) $request->get_param( 'length' ) ) );

		if ( $offset >= $size ) {
			return new WP_REST_Response(
				array(
					'basename' => basename( $path ),
					'offset'   => $offset,
					'length'   => 0,
					'size'     => $size,
					'sha256'   => hash_file( 'sha256', $path ),
					'complete' => true,
					'data'     => '',
				)
			);
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_download_open_failed', __( 'Unable to open requested snapshot.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
		}

		try {
			fseek( $handle, $offset );
			$bytes = fread( $handle, min( $length, $size - $offset ) );
			if ( false === $bytes ) {
				return new WP_Error( 'ag_sync_bridge_download_read_failed', __( 'Unable to read requested snapshot chunk.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
			}
		} finally {
			fclose( $handle );
		}

		$response = array(
			'basename' => basename( $path ),
			'offset'   => $offset,
			'length'   => strlen( $bytes ),
			'size'     => $size,
			'sha256'   => '',
			'complete' => ( $offset + strlen( $bytes ) ) >= $size,
			'data'     => base64_encode( $bytes ),
		);

		if ( ! empty( $response['complete'] ) ) {
			$response['sha256'] = hash_file( 'sha256', $path );
		}

		return new WP_REST_Response( $response );
	}

	public function download_snapshot_raw_chunk( WP_REST_Request $request ) {
		@set_time_limit( 0 );

		$snapshot = sanitize_file_name( (string) $request->get_param( 'snapshot' ) );
		$path     = $this->file_system->find_package( $snapshot, 'snapshots' );

		if ( ! $path ) {
			return new WP_Error( 'ag_sync_bridge_download_missing', __( 'Requested snapshot was not found.', 'ag-sync-bridge' ), array( 'status' => 404 ) );
		}

		$size   = (int) filesize( $path );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$length = max( 1, min( 8388608, (int) $request->get_param( 'length' ) ) );

		if ( $offset >= $size ) {
			$this->clear_output_buffers();
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: 0' );
			header( 'X-AGSB-Basename: ' . basename( $path ) );
			header( 'X-AGSB-Offset: ' . $offset );
			header( 'X-AGSB-Length: 0' );
			header( 'X-AGSB-Size: ' . $size );
			header( 'X-AGSB-Complete: true' );
			header( 'X-AGSB-Sha256: ' . hash_file( 'sha256', $path ) );
			exit;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_download_open_failed', __( 'Unable to open requested snapshot.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
		}

		try {
			fseek( $handle, $offset );
			$bytes = fread( $handle, min( $length, $size - $offset ) );
			if ( false === $bytes ) {
				return new WP_Error( 'ag_sync_bridge_download_read_failed', __( 'Unable to read requested snapshot chunk.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
			}
		} finally {
			fclose( $handle );
		}

		$complete = ( $offset + strlen( $bytes ) ) >= $size;

		$this->clear_output_buffers();
		nocache_headers();
		status_header( 206 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'X-AGSB-Basename: ' . basename( $path ) );
		header( 'X-AGSB-Offset: ' . $offset );
		header( 'X-AGSB-Length: ' . strlen( $bytes ) );
		header( 'X-AGSB-Size: ' . $size );
		header( 'X-AGSB-Complete: ' . ( $complete ? 'true' : 'false' ) );

		if ( $complete ) {
			header( 'X-AGSB-Sha256: ' . hash_file( 'sha256', $path ) );
		}

		echo $bytes;
		exit;
	}

	private function clear_output_buffers() {
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
	}

	private function get_chunk_upload_dir( $upload_id ) {
		return normalize_path( $this->file_system->get_upload_chunks_dir() . '/' . sanitize_key( $upload_id ) );
	}
}
