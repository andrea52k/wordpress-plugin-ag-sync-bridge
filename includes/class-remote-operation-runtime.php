<?php
namespace AGSyncBridge;

use WP_Error;

require_once __DIR__ . '/class-direct-operation-monitor.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-backed control plane for the single remote async operation.
 * It remains outside snapshots, so a cancellation survives a database import.
 */
class Remote_Operation_Runtime {
	const DEFAULT_STALE_AFTER = 900;
	const RECONCILE_GRACE_SECONDS = 30;

	private $config;
	private $logger;
	private $direct_monitor;

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
		$this->direct_monitor = new Direct_Operation_Monitor();
	}

	public function arm_direct_import_monitor( $operation_id, $token_sha256, $expires_at ) {
		$current = $this->get();
		if ( ! is_array( $current ) || (string) array_get( $current, 'id', '' ) !== (string) $operation_id || 'import' !== (string) array_get( $current, 'kind', '' ) ) {
			return false;
		}
		return $this->direct_monitor->arm( $operation_id, $token_sha256, $expires_at, $current );
	}

	public function reserve( $kind, array $operation, $allow_recovery_override = false ) {
		return $this->locked( function ( array $current ) use ( $kind, $operation, $allow_recovery_override ) {
			$current_status = (string) array_get( $current, 'status', '' );
			$stale_quarantine = 'import' === (string) array_get( $current, 'kind', '' )
				&& 'reconcile_requested' === $current_status
				&& ( strtotime( (string) array_get( $current, 'heartbeat_at', '' ) ) < ( time() - self::DEFAULT_STALE_AFTER ) );
			$rollback_recovery = 'import' === (string) array_get( $current, 'kind', '' ) && 'rollback_required' === $current_status;
			if ( $allow_recovery_override && 'import' === $kind && ( $stale_quarantine || $rollback_recovery ) ) {
				$operation['recovery_of_operation_id'] = (string) array_get( $current, 'id', '' );
				$operation['recovery_of_snapshot']     = (string) array_get( $current, 'snapshot', '' );
				$operation['recovery_of_status']       = $current_status;
				$operation['recovery_of_updated_at']   = (string) array_get( $current, 'updated_at', '' );
				$operation['recovery_override']        = true;
				$operation['recovery_authorized_at']   = gmdate( 'c' );
				$this->logger->warning(
					'Allowing explicit recovery import to supersede a blocked remote import.',
					array(
						'recovery_operation_id' => (string) array_get( $operation, 'id', '' ),
						'recovery_of_operation_id' => $operation['recovery_of_operation_id'],
						'recovery_of_status' => $current_status,
					)
				);
			} else {
				if ( 'rollback_required' === $current_status ) {
					return new WP_Error(
						'ag_sync_bridge_remote_recovery_required',
						__( 'The previous remote operation requires verified recovery before another operation can start.', 'ag-sync-bridge' ),
						array( 'status' => 409, 'operation' => $current )
					);
				}
				if ( ! empty( $current ) && ! $this->is_terminal( $current ) ) {
					return new WP_Error( 'ag_sync_bridge_remote_operation_busy', __( 'A remote AG Sync operation is already active.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
				}
			}
			$operation['kind'] = $kind;
			$operation['status'] = 'queued';
			$operation['stage'] = array_get( $operation, 'stage', 'queued' );
			$operation['progress'] = max( 0, min( 100, (int) array_get( $operation, 'progress', 0 ) ) );
			$operation['updated_at'] = gmdate( 'c' );
			$operation['heartbeat_at'] = $operation['updated_at'];
			$operation['heartbeat_sequence'] = 0;
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
			$current['heartbeat_at'] = $current['updated_at'];
			$current['heartbeat_sequence'] = (int) array_get( $current, 'heartbeat_sequence', 0 ) + 1;
			return $current;
		} );
	}

	public function heartbeat( $operation_id, $stage, $progress, array $changes = array() ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $stage, $progress, $changes ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_mismatch', __( 'Remote operation changed before heartbeat.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}

			$status = (string) array_get( $current, 'status', '' );
			if ( ! in_array( $status, array( 'running', 'cancel_requested', 'reconcile_requested' ), true ) ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_not_running', __( 'Remote operation is not running.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}

			$now = gmdate( 'c' );
			unset( $changes['id'], $changes['kind'], $changes['status'], $changes['started_at'], $changes['finished_at'] );
			$current = array_merge( $current, $changes );
			$current['stage'] = sanitize_key( (string) $stage );
			$current['progress'] = max(
				(int) array_get( $current, 'progress', 0 ),
				max( 0, min( 100, (int) $progress ) )
			);
			$current['updated_at'] = $now;
			$current['heartbeat_at'] = $now;
			$current['heartbeat_sequence'] = (int) array_get( $current, 'heartbeat_sequence', 0 ) + 1;
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
			$now                     = gmdate( 'c' );
			$queued                  = 'queued' === (string) array_get( $current, 'status', '' );
			$current['status']       = $queued ? 'cancelled' : 'cancel_requested';
			$current['stage']        = $queued ? 'cancelled' : 'cancel_requested';
			$current['message']      = $queued
				? __( 'Queued operation cancelled before the worker started.', 'ag-sync-bridge' )
				: __( 'Cancellation requested. The worker will stop at the next safe checkpoint.', 'ag-sync-bridge' );
			$current['updated_at']   = $now;
			$current['cancel_requested_at'] = array_get( $current, 'cancel_requested_at', $now );
			if ( $queued ) {
				$current['finished_at'] = $now;
				$current['cleanup_verified'] = true;
			}
			return $current;
		} );
	}

	public function finalize( $operation_id, $status, array $changes = array() ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $status, $changes ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id ) {
				return new WP_Error( 'ag_sync_bridge_remote_operation_mismatch', __( 'Remote operation changed before finalization.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			$cancel_requested = in_array( (string) array_get( $current, 'status', '' ), array( 'cancel_requested', 'reconcile_requested' ), true );
			$rollback_required = ! empty( $changes['rollback_required'] ) || ! empty( $changes['target_mutated'] ) || ! empty( $current['target_mutated'] );
			if ( $cancel_requested ) {
				$status = $rollback_required ? 'rollback_required' : 'cancelled';
			} elseif ( in_array( $status, array( 'error', 'failed' ), true ) && $rollback_required ) {
				$status = 'rollback_required';
			} elseif ( 'complete' === $status ) {
				unset( $changes['rollback_required'] );
				unset( $changes['target_mutated'] );
				$changes['progress'] = 100;
				$changes['stage'] = 'complete';
			}
			$current = array_merge( $current, $changes, array( 'status' => $status, 'updated_at' => gmdate( 'c' ), 'finished_at' => gmdate( 'c' ) ) );
			return $current;
		} );
	}

	/**
	 * Clears a blocking rollback_required state only after explicit, audited
	 * verification of either the target integrity or a completed rollback.
	 */
	public function resolve_recovery( $operation_id, $kind, $expected_updated_at, array $verification ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $kind, $expected_updated_at, $verification ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id || (string) array_get( $current, 'kind', '' ) !== (string) $kind ) {
				return new WP_Error( 'ag_sync_bridge_recovery_operation_mismatch', __( 'The requested recovery does not match the blocked remote operation.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			if ( 'rollback_required' !== (string) array_get( $current, 'status', '' ) ) {
				return new WP_Error( 'ag_sync_bridge_recovery_not_required', __( 'The remote operation is not waiting for recovery verification.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}
			if ( (string) array_get( $current, 'updated_at', '' ) !== (string) $expected_updated_at ) {
				return new WP_Error( 'ag_sync_bridge_recovery_state_changed', __( 'Remote recovery state changed. Read status again before confirming recovery.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}

			$note             = trim( (string) array_get( $verification, 'note', '' ) );
			$target_verified  = ! empty( $verification['target_integrity_verified'] );
			$rollback_verified = ! empty( $verification['rollback_verified'] );
			if ( '' === $note || ( ! $target_verified && ! $rollback_verified ) ) {
				return new WP_Error( 'ag_sync_bridge_recovery_verification_incomplete', __( 'Recovery confirmation requires a note and verified target integrity or rollback.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
			}

			$now                       = gmdate( 'c' );
			$current['status']         = 'reconciled';
			$current['stage']          = 'recovery-verified';
			$current['updated_at']     = $now;
			$current['finished_at']    = $now;
			$current['rollback_required'] = false;
			$current['target_mutated'] = false;
			$current['recovery']       = array(
				'verified_at'               => $now,
				'target_integrity_verified' => $target_verified,
				'rollback_verified'         => $rollback_verified,
				'note'                      => sanitize_text_field( $note ),
				'declared_success'          => false,
			);
			$current['message'] = __( 'Recovery verified. The blocked operation was closed without declaring the sync successful.', 'ag-sync-bridge' );
			return $current;
		} );
	}

	public function get() {
		return $this->locked( function ( array $current ) { return $current; }, false );
	}

	public function inspect( $stale_after = self::DEFAULT_STALE_AFTER ) {
		$current = $this->get();
		if ( is_wp_error( $current ) || empty( $current ) ) {
			return $current;
		}

		$stale_after = max( 60, (int) $stale_after );
		$heartbeat   = strtotime( (string) array_get( $current, 'heartbeat_at', array_get( $current, 'updated_at', '' ) ) );
		$age         = false === $heartbeat ? null : max( 0, time() - $heartbeat );
		$status      = (string) array_get( $current, 'status', '' );
		$running     = in_array( $status, array( 'running', 'cancel_requested', 'reconcile_requested' ), true );
		$stale       = $running && ( null === $age || $age > $stale_after );

		$current['heartbeat'] = array(
			'age_seconds' => $age,
			'stale_after_seconds' => $stale_after,
			'is_stale'    => $stale,
			'liveness'    => $this->is_terminal( $current ) ? 'terminal' : ( $stale ? 'stale_or_orphaned' : ( $running ? 'active' : 'queued' ) ),
		);
		return $current;
	}

	public function is_cancel_requested( $operation_id ) {
		$current = $this->get();
		return is_array( $current )
			&& (string) array_get( $current, 'id', '' ) === (string) $operation_id
			&& in_array( (string) array_get( $current, 'status', '' ), array( 'cancel_requested', 'reconcile_requested' ), true );
	}

	public function request_reconciliation( $operation_id, $kind, $expected_updated_at, $note, $stale_after = self::DEFAULT_STALE_AFTER ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $kind, $expected_updated_at, $note, $stale_after ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id || (string) array_get( $current, 'kind', '' ) !== (string) $kind ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_operation_mismatch', __( 'The requested operation does not match the active remote operation.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			if ( (string) array_get( $current, 'updated_at', '' ) !== (string) $expected_updated_at ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_state_changed', __( 'Remote operation changed after it was inspected. Read status again before reconciling.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}
			if ( ! in_array( (string) array_get( $current, 'status', '' ), array( 'running', 'cancel_requested' ), true ) ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_not_running', __( 'Only a running remote operation can enter reconciliation.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}

			$stale_after = max( 60, (int) $stale_after );
			$heartbeat   = strtotime( (string) array_get( $current, 'heartbeat_at', array_get( $current, 'updated_at', '' ) ) );
			$age         = false === $heartbeat ? PHP_INT_MAX : max( 0, time() - $heartbeat );
			if ( $age <= $stale_after ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_operation_active', __( 'The operation heartbeat is still active; reconciliation is blocked.', 'ag-sync-bridge' ), array( 'status' => 409, 'heartbeat_age_seconds' => $age ) );
			}

			$now = gmdate( 'c' );
			$current['status'] = 'reconcile_requested';
			$current['stage'] = 'reconciliation-quarantine';
			$current['updated_at'] = $now;
			$current['reconcile_requested_at'] = $now;
			$current['reconciliation_note'] = sanitize_text_field( (string) $note );
			return $current;
		} );
	}

	public function close_reconciliation( $operation_id, $kind, $expected_updated_at, array $verification ) {
		return $this->locked( function ( array $current ) use ( $operation_id, $kind, $expected_updated_at, $verification ) {
			if ( (string) array_get( $current, 'id', '' ) !== (string) $operation_id || (string) array_get( $current, 'kind', '' ) !== (string) $kind ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_operation_mismatch', __( 'The requested operation does not match the quarantined remote operation.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
			}
			if ( 'reconcile_requested' !== (string) array_get( $current, 'status', '' ) ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_not_quarantined', __( 'The operation must be quarantined before controlled closure.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}
			if ( (string) array_get( $current, 'updated_at', '' ) !== (string) $expected_updated_at ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_state_changed', __( 'Remote operation changed after quarantine. Read status again before closing it.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $current ) );
			}

			$requested_at = strtotime( (string) array_get( $current, 'reconcile_requested_at', '' ) );
			if ( false === $requested_at || ( time() - $requested_at ) < self::RECONCILE_GRACE_SECONDS ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_grace_period', __( 'Wait for the reconciliation grace period before controlled closure.', 'ag-sync-bridge' ), array( 'status' => 409, 'retry_after' => self::RECONCILE_GRACE_SECONDS ) );
			}

			$note = trim( (string) array_get( $verification, 'note', '' ) );
			$worker_absent = ! empty( $verification['worker_absent_verified'] );
			$target_verified = ! empty( $verification['target_integrity_verified'] );
			$rollback_verified = ! empty( $verification['rollback_verified'] );
			if ( ! $worker_absent || '' === $note ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_verification_incomplete', __( 'Controlled closure requires worker absence verification and a verification note.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
			}
			if ( 'import' === $kind && ! $target_verified && ! $rollback_verified ) {
				return new WP_Error( 'ag_sync_bridge_reconcile_import_unverified', __( 'An orphaned import requires verified target integrity or verified rollback.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
			}

			$now = gmdate( 'c' );
			$current['status'] = ( 'import' === $kind && ! $target_verified && ! $rollback_verified ) ? 'rollback_required' : 'reconciled';
			$current['stage'] = 'reconciled';
			$current['progress'] = min( 99, (int) array_get( $current, 'progress', 0 ) );
			$current['updated_at'] = $now;
			$current['finished_at'] = $now;
			$current['reconciliation'] = array(
				'closed_at'                 => $now,
				'worker_absent_verified'    => $worker_absent,
				'target_integrity_verified' => $target_verified,
				'rollback_verified'         => $rollback_verified,
				'note'                      => sanitize_text_field( $note ),
				'declared_success'          => false,
			);
			$current['message'] = __( 'Stale operation closed after explicit reconciliation; this is not a successful sync result.', 'ag-sync-bridge' );
			return $current;
		} );
	}

	private function is_terminal( array $operation ) {
		return in_array( (string) array_get( $operation, 'status', '' ), array( 'complete', 'error', 'failed', 'cancelled', 'reconciled' ), true );
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
				$this->direct_monitor->publish( $result );
			}
			return $result;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}
}
