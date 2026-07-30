<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and validates the pre-push backup contract shared by both peers.
 */
class Remote_Backup_Result {
	const STATUS_COMPLETED = 'completed';
	const STATUS_SKIPPED   = 'skipped';
	const STATUS_DISABLED  = 'disabled';
	const STATUS_FAILED    = 'failed';

	/**
	 * Verify the archive on the remote filesystem before reporting success.
	 *
	 * @param array $backup Raw backup metadata returned by the exporter.
	 * @return array|WP_Error
	 */
	public static function completed_from_archive( array $backup ) {
		$path              = (string) array_get( $backup, 'path', '' );
		$basename          = trim( (string) array_get( $backup, 'basename', '' ) );
		$reported_bytes    = (int) array_get( $backup, 'size_bytes', 0 );
		$reported_checksum = strtolower( trim( (string) array_get( $backup, 'sha256', '' ) ) );

		if ( '' === $path || '' === $basename || basename( $path ) !== $basename ) {
			return self::verification_error( 'archive_identity_missing', __( 'Remote backup did not return a valid archive basename and path.', 'ag-sync-bridge' ) );
		}

		clearstatcache( true, $path );
		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			return self::verification_error( 'archive_missing', __( 'Remote backup archive was not found after creation.', 'ag-sync-bridge' ) );
		}

		$actual_bytes = (int) filesize( $path );
		if ( $reported_bytes <= 0 || $actual_bytes <= 0 || $reported_bytes !== $actual_bytes ) {
			return self::verification_error(
				'archive_size_invalid',
				__( 'Remote backup archive size could not be verified.', 'ag-sync-bridge' ),
				array(
					'reported_bytes' => $reported_bytes,
					'actual_bytes'   => $actual_bytes,
				)
			);
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $reported_checksum ) ) {
			return self::verification_error( 'archive_checksum_missing', __( 'Remote backup did not return a valid SHA-256 checksum.', 'ag-sync-bridge' ) );
		}

		$actual_checksum = strtolower( (string) hash_file( 'sha256', $path ) );
		if ( '' === $actual_checksum || ! hash_equals( $reported_checksum, $actual_checksum ) ) {
			return self::verification_error( 'archive_checksum_mismatch', __( 'Remote backup archive checksum verification failed.', 'ag-sync-bridge' ) );
		}

		unset( $backup['path'] );
		$backup['status']       = self::STATUS_COMPLETED;
		$backup['skipped']      = false;
		$backup['basename']     = $basename;
		$backup['size_bytes']   = $actual_bytes;
		$backup['sha256']       = $actual_checksum;
		$backup['completed_at'] = gmdate( 'c' );
		$backup['proof']        = array(
			'archive_exists' => true,
			'basename'       => $basename,
			'size_bytes'     => $actual_bytes,
			'sha256'         => $actual_checksum,
			'verified_at'    => gmdate( 'c' ),
		);

		return $backup;
	}

	public static function disabled( $type, $reason = 'remote_backups_disabled' ) {
		return array(
			'status'      => self::STATUS_DISABLED,
			'skipped'     => true,
			'reason'      => (string) $reason,
			'type'        => (string) $type,
			'disabled_at' => gmdate( 'c' ),
			'proof'       => self::empty_proof(),
		);
	}

	public static function skipped( $type, $reason = 'skip_remote_backup' ) {
		return array(
			'status'     => self::STATUS_SKIPPED,
			'skipped'    => true,
			'reason'     => (string) $reason,
			'type'       => (string) $type,
			'skipped_at' => gmdate( 'c' ),
			'proof'      => self::empty_proof(),
		);
	}

	public static function failed( $type, $code, $message ) {
		return array(
			'status'    => self::STATUS_FAILED,
			'skipped'   => false,
			'reason'    => (string) $code,
			'type'      => (string) $type,
			'message'   => (string) $message,
			'failed_at' => gmdate( 'c' ),
			'proof'     => self::empty_proof(),
		);
	}

	/**
	 * Require an attested, concrete remote archive before a required push.
	 *
	 * @param mixed $response Decoded remote response.
	 * @return array|WP_Error
	 */
	public static function require_completed( $response ) {
		if ( ! is_array( $response ) || empty( $response ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_backup_empty',
				__( 'Required remote pre-push backup returned an empty or invalid response.', 'ag-sync-bridge' )
			);
		}

		$status = strtolower( trim( (string) array_get( $response, 'status', '' ) ) );
		if ( self::STATUS_COMPLETED !== $status ) {
			$code = in_array( $status, array( self::STATUS_DISABLED, self::STATUS_SKIPPED, self::STATUS_FAILED ), true )
				? $status
				: 'invalid';

			return new WP_Error(
				'ag_sync_bridge_remote_backup_' . $code,
				sprintf(
					/* translators: %s: remote backup status */
					__( 'Required remote pre-push backup was not completed (status: %s).', 'ag-sync-bridge' ),
					$status ?: 'missing'
				),
				array( 'remote_backup' => $response )
			);
		}

		$proof      = array_get( $response, 'proof', array() );
		$proof      = is_array( $proof ) ? $proof : array();
		$basename   = trim( (string) array_get( $proof, 'basename', '' ) );
		$bytes      = (int) array_get( $proof, 'size_bytes', 0 );
		$checksum   = strtolower( trim( (string) array_get( $proof, 'sha256', '' ) ) );
		$top_name   = trim( (string) array_get( $response, 'basename', '' ) );
		$top_bytes  = (int) array_get( $response, 'size_bytes', 0 );
		$top_sha256 = strtolower( trim( (string) array_get( $response, 'sha256', '' ) ) );

		if (
			true !== array_get( $proof, 'archive_exists', false )
			|| '' === $basename
			|| $bytes <= 0
			|| ! preg_match( '/^[a-f0-9]{64}$/', $checksum )
			|| $basename !== $top_name
			|| $bytes !== $top_bytes
			|| ! hash_equals( $checksum, $top_sha256 )
		) {
			return new WP_Error(
				'ag_sync_bridge_remote_backup_unverified',
				__( 'Required remote pre-push backup did not include valid archive proof.', 'ag-sync-bridge' ),
				array( 'remote_backup' => $response )
			);
		}

		return $response;
	}

	private static function empty_proof() {
		return array(
			'archive_exists' => false,
			'basename'       => '',
			'size_bytes'     => 0,
			'sha256'         => '',
			'verified_at'    => '',
		);
	}

	private static function verification_error( $reason, $message, array $details = array() ) {
		return new WP_Error(
			'ag_sync_bridge_remote_backup_unverified',
			$message,
			array_merge( array( 'reason' => (string) $reason ), $details )
		);
	}
}
