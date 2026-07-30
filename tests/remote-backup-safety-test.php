<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function __( $message ) { return $message; }

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}

	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

namespace AGSyncBridge {
	function array_get( array $values, $key, $default = null ) {
		return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
	}

	require_once dirname( __DIR__ ) . '/includes/class-remote-backup-result.php';

	function expect_backup( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$disabled = Remote_Backup_Result::disabled( 'pre-push-backup' );
	expect_backup( 'disabled' === $disabled['status'], 'Disabled backup must be reported as disabled.' );
	$result = Remote_Backup_Result::require_completed( $disabled );
	expect_backup( \is_wp_error( $result ) && 'ag_sync_bridge_remote_backup_disabled' === $result->get_error_code(), 'Disabled backup must not satisfy a required backup.' );

	$skipped = Remote_Backup_Result::skipped( 'pre-push-backup' );
	expect_backup( 'skipped' === $skipped['status'], 'Skipped backup must be reported as skipped.' );
	$result = Remote_Backup_Result::require_completed( $skipped );
	expect_backup( \is_wp_error( $result ) && 'ag_sync_bridge_remote_backup_skipped' === $result->get_error_code(), 'Skipped backup must not satisfy a required backup.' );

	$result = Remote_Backup_Result::require_completed( array() );
	expect_backup( \is_wp_error( $result ) && 'ag_sync_bridge_remote_backup_empty' === $result->get_error_code(), 'Empty backup response must fail closed.' );

	$failed = Remote_Backup_Result::failed( 'pre-push-backup', 'archive_error', 'Archive creation failed.' );
	expect_backup( 'failed' === $failed['status'], 'Failed backup must be reported as failed.' );
	$result = Remote_Backup_Result::require_completed( $failed );
	expect_backup( \is_wp_error( $result ) && 'ag_sync_bridge_remote_backup_failed' === $result->get_error_code(), 'Failed backup must not satisfy a required backup.' );

	$archive = tempnam( sys_get_temp_dir(), 'agsync-backup-' );
	if ( false === $archive ) {
		throw new \RuntimeException( 'Unable to create temporary backup fixture.' );
	}
	$zip_path = $archive . '.zip';
	rename( $archive, $zip_path );
	file_put_contents( $zip_path, 'verified remote backup fixture' );

	$bytes    = filesize( $zip_path );
	$checksum = hash_file( 'sha256', $zip_path );
	$verified = Remote_Backup_Result::completed_from_archive(
		array(
			'path'       => $zip_path,
			'basename'   => basename( $zip_path ),
			'size_bytes' => $bytes,
			'sha256'     => $checksum,
			'type'       => 'pre-push-backup',
			'manifest'   => array(
				'snapshot_scope' => 'full',
				'partial_paths'  => array(),
			),
		)
	);

	expect_backup( ! \is_wp_error( $verified ), 'A real, non-empty archive with matching SHA-256 must be accepted.' );
	expect_backup( 'completed' === $verified['status'], 'Verified archive must be reported as completed.' );
	expect_backup( true === $verified['proof']['archive_exists'], 'Completed response must attest archive existence.' );
	expect_backup( $bytes === $verified['proof']['size_bytes'], 'Completed response must include verified bytes.' );
	expect_backup( $checksum === $verified['proof']['sha256'], 'Completed response must include verified SHA-256.' );
	expect_backup( 'full' === $verified['scope'], 'Verified full backup must attest its scope.' );
	expect_backup( array() === $verified['paths'], 'Verified full backup must not declare partial paths.' );
	expect_backup( ! isset( $verified['path'] ), 'Remote filesystem path must not be exposed in the response.' );
	expect_backup( $verified === Remote_Backup_Result::require_completed( $verified ), 'Concrete verified response must satisfy a required backup.' );

	$unverified = $verified;
	$unverified['proof']['archive_exists'] = false;
	$result = Remote_Backup_Result::require_completed( $unverified );
	expect_backup( \is_wp_error( $result ) && 'ag_sync_bridge_remote_backup_unverified' === $result->get_error_code(), 'A completed label without existence proof must fail closed.' );

	@unlink( $zip_path );

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-sync-service.php' );
	expect_backup( false !== strpos( $sync_source, 'Remote_Backup_Result::require_completed( $remote_backup,' ), 'Push flow must enforce the completed backup contract.' );

	echo "remote backup safety: ok\n";
}
