<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-backed control plane for the single remote async operation.
 * It remains outside snapshots, so a cancellation survives a database import.
 */
class Remote_Operation_Runtime {
	private $config;
	private $logger;

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function reserve( $kind, array $operation ) {
		return $this->locked( function ( array $current ) use ( $kind, $operation ) {
			if ( ! empty( $current ) && ! $this->is_terminal( $current ) ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_busy', __( 'A remote AG Sync operation is already active.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}
			$operation['kind'] = $kind;
			$operation['status'] = 'queued';
			$operation['updated_at'] = gmdate( 'c' );
			return $operation;
		} );
	}

	public function claim( $operation_id ) {
		return $this->locked( function ( array $current ) use ( $operation_id ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id || 'queued' !== (string) array_get( $current, 'status', '' ) ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_not_claimable', __( 'Remote operation is no longer claimable.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}
			$current['status'] = 'running';
			$current['updated_at'] = gmdate( 'c' );
			return $current;
		} );
	}

	public function request_cancel( $operation_id, $kind ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $kind ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id ) {
				return new WP_Error( 'ag_sync_bridge_cancel_operation_mismatch', __( 'The requested operation does not match the active remote operation.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			if ( (string) array_get( $current, 'kind', '' ) !== (string) $kind ) {
				return new WP_Error( 'ag_sync_bridge_cancel_kind_mismatch', __( 'The requested operation type does not match.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			if ( $this->is_terminal( $current ) ) {
				return $current;
			}
			$current['status'] = 'queued' === (string) array_get( $current, 'status', '' ) ? 'cancelled' : 'cancel_requested';
			$current['updated_at'] = gmdate( 'c' );
			$current['cancel_requested_at'] = gmdate( 'c' );
			return $current;
		} );
	}

	public function finalize( $operation_id, $status, array $changes = array() ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $status, $changes ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_mismatch', __( 'Remote operation changed before finalization.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			$cancel_requested = 'cancel_requested' === (string) array_get( $current, 'status', '' );
			if ( $cancel_requested ) {
				$status = ! empty( $changes['rollback_required'] ) ? 'rollback_required' : 'cancelled';
			} elseif ( 'complete' === $status ) {
				unset( $changes['rollback_required'] );
			}
			$current = array_merge( $current, $changes, array( 'status' => $status, 'updated_at' => gmdate( 'c' ), 'finished_at' => gmdate( 'c' ) ) );
			return $current;
		} );
	}

	public function get() {
		return $this->locked( function ( array $current ) { return $current; }, false );
	}

	public function is_cancel_requested( $operation_id ) {
		$current = $this->get();
		return is_array( $current ) && (string) array_get( $current, 'id', '' ) === (string) $operation_id && 'cancel_requested' === (string) array_get( $current, 'status', '' );
	}

	private function is_terminal( array $operation ) {
		return in_array( (string) array_get( $operation, 'status', '' ), array( 'complete', 'error', 'cancelled', 'rollback_required' ), true );
	}

	private function locked( callable $callback, $write = true ) {
		$dir = $this->config->get_data_dir( 'operations' );
		ensure_directory( $dir );
		$path = normalize_path( $dir . '/remote-operation.json' );
		$handle = fopen( $path, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_operation_lock_failed', __( 'Unable to lock remote operation state.', 'ag-sync-bridge' ) );
		}
		try {
			rewind( $handle );
			$current = json_decode( stream_get_contents( $handle ), true );
			$current = is_array( $current ) ? $current : array();
			$result = call_user_func( $callback, $current );
			if ( $write && ! is_wp_error( $result ) ) {
				ftruncate( $handle, 0 );
				rewind( $handle );
				fwrite( $handle, wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				fflush( $handle );
			}
			return $result;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}
}
