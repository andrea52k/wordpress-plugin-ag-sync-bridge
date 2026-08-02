<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class CLI extends \WP_CLI_Command {
		/**
		 * @var Config
		 */
		private static $config;

		/**
		 * @var Logger
		 */
		private static $logger;

		/**
		 * @var Lock_Manager
		 */
		private static $lock_manager;

		/**
		 * @var File_System_Service
		 */
		private static $file_system;

		/**
		 * @var Sync_Service
		 */
		private static $sync;

		public static function register( Config $config, Logger $logger, Lock_Manager $lock_manager, File_System_Service $file_system, Sync_Service $sync ) {
			self::$config      = $config;
			self::$logger      = $logger;
			self::$lock_manager = $lock_manager;
			self::$file_system = $file_system;
			self::$sync        = $sync;

			\WP_CLI::add_command( 'agsync', __CLASS__ );
		}

		/**
		 * Shows the current plugin status.
		 */
		public function status() {
			$latest_snapshot = self::$file_system->list_packages( 'snapshots', 1, true );
			$latest_snapshot = empty( $latest_snapshot ) ? array() : $latest_snapshot[0];

			\WP_CLI::log( 'Role: ' . self::$config->get_role() );
			\WP_CLI::log( 'Remote URL: ' . self::$config->get_remote_url() );
			\WP_CLI::log( 'Storage: ' . self::$config->get_storage_dir() );
			\WP_CLI::log( 'Latest snapshot: ' . ( empty( $latest_snapshot ) ? 'none' : array_get( $latest_snapshot, 'basename', '' ) ) );
			if ( ! empty( $latest_snapshot ) ) {
				\WP_CLI::log( 'Latest snapshot scope: ' . array_get( $latest_snapshot, 'snapshot_scope', 'unknown' ) );
			}
		}

		/**
		 * Checks local and remote AG Sync storage prerequisites.
		 *
		 * ## OPTIONS
		 *
		 * [--required-bytes=<bytes>]
		 * : Minimum free bytes required on each runtime filesystem.
		 *
		 * [--skip-remote]
		 * : Only check the current site.
		 *
		 * [--deep]
		 * : Include latest snapshot validation and root sitemap coherence checks.
		 */
		public function doctor( $args, $assoc_args ) {
			unset( $args );

			$required_bytes = isset( $assoc_args['required-bytes'] ) ? max( 0, (int) $assoc_args['required-bytes'] ) : self::estimate_required_bytes_for_doctor();
			$deep           = ! empty( $assoc_args['deep'] );
			$local          = self::$file_system->diagnose_runtime_storage( $required_bytes, $deep );
			$ok             = self::log_doctor_result( 'Local', $local );

			if ( empty( $assoc_args['skip-remote'] ) && self::$config->get_remote_url() && self::$config->get_secret() ) {
				$client = new Http_Client( self::$config, self::$logger );
				$remote = $client->remote_doctor( $required_bytes, $deep );

				if ( is_wp_error( $remote ) ) {
					$ok = false;
					\WP_CLI::warning( 'Remote doctor failed: ' . $remote->get_error_message() );
					$data = $remote->get_error_data();
					if ( is_array( $data ) && ! empty( $data['body'] ) ) {
						\WP_CLI::log( 'Remote body: ' . (string) $data['body'] );
					}
				} else {
					$ok = self::log_doctor_result( 'Remote', $remote ) && $ok;
				}
			}

			if ( ! $ok ) {
				\WP_CLI::error( 'AG Sync doctor found blocking issues.' );
			}

			\WP_CLI::success( 'AG Sync doctor passed.' );
		}

		/**
		 * Shows the current AG Sync lock.
		 */
		public function lock() {
			$lock = self::$lock_manager->get_lock();
			if ( empty( $lock ) ) {
				\WP_CLI::success( 'No AG Sync lock present.' );
				return;
			}

			\WP_CLI::log( wp_json_encode( $lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}

		/**
		 * Releases an AG Sync lock deliberately.
		 *
		 * ## OPTIONS
		 *
		 * [--stale-only]
		 * : Release only if the lock is older than --max-age seconds.
		 *
		 * [--max-age=<seconds>]
		 * : Stale threshold in seconds. Default: 900.
		 *
		 * [--reason=<text>]
		 * : Reason stored in the plugin log.
		 */
		public function unlock( $args, $assoc_args ) {
			unset( $args );

			$lock = self::$lock_manager->get_lock();
			if ( empty( $lock ) ) {
				\WP_CLI::success( 'No AG Sync lock present.' );
				return;
			}

			$max_age = isset( $assoc_args['max-age'] ) ? absint( $assoc_args['max-age'] ) : 900;
			$age     = time() - absint( array_get( $lock, 'timestamp', 0 ) );
			if ( ! empty( $assoc_args['stale-only'] ) && $age < $max_age ) {
				\WP_CLI::error( sprintf( 'Lock is not stale yet: %d seconds old, threshold %d seconds.', $age, $max_age ) );
			}

			$reason = isset( $assoc_args['reason'] ) ? sanitize_text_field( (string) $assoc_args['reason'] ) : 'wp-cli';
			self::$lock_manager->force_release( $reason );
			\WP_CLI::success( sprintf( 'AG Sync lock released. Age: %d seconds.', $age ) );
		}

		/**
		 * Creates a snapshot.
		 *
		 * ## OPTIONS
		 *
		 * [--type=<type>]
		 * : Snapshot type label.
		 *
		 * [--paths=<paths>]
		 * : Optional comma/newline separated relative paths for a file-only partial snapshot.
		 */
		public function snapshot( $args, $assoc_args ) {
			$type   = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : 'cli-snapshot';
			$paths  = self::parse_path_list_arg( array_get( $assoc_args, 'paths', '' ) );
			$result = empty( $paths )
				? self::$sync->create_snapshot( $type, array( 'trigger' => 'wp-cli' ) )
				: self::$sync->create_partial_snapshot(
					$paths,
					$type ?: 'cli-partial-snapshot',
					array(
						'trigger'       => 'wp-cli',
						'partial_paths' => $paths,
					)
				);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Snapshot created: ' . array_get( $result, 'basename', '' ) );
		}

		/**
		 * Pulls the live site into the local site.
		 *
		 * ## OPTIONS
		 *
		 * [--use-existing-snapshot]
		 * : Reuse the latest live snapshot instead of creating a fresh one.
		 *
		 * [--paths=<paths>]
		 * : Comma/newline separated relative paths for a fresh file-only partial pull. Cannot be combined with --use-existing-snapshot.
		 */
		public function pull( $args, $assoc_args ) {
			$paths = self::parse_path_list_arg( array_get( $assoc_args, 'paths', '' ) );
			if ( ! empty( $paths ) ) {
				\WP_CLI::log( 'Partial pull paths: ' . implode( ', ', $paths ) );
			}
			$result = self::$sync->pull_from_remote(
				array(
					'use_existing_snapshot' => ! empty( $assoc_args['use-existing-snapshot'] ),
					'partial_paths'         => $paths,
				)
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Pull completed.' );
		}

		/**
		 * Shows the non-mutating plan for a full or explicit partial pull.
		 *
		 * ## OPTIONS
		 *
		 * [--paths=<paths>]
		 * : Comma/newline separated paths. Without it the plan remains full.
		 *
		 * [--format=<format>]
		 * : Use json for a machine-readable, non-mutating plan.
		 */
		public function pull_plan( $args, $assoc_args ) {
			unset( $args );
			$paths = self::parse_path_list_arg( array_get( $assoc_args, 'paths', '' ) );
			$plan  = self::$sync->plan_pull( $paths );
			if ( is_wp_error( $plan ) ) {
				\WP_CLI::error( $plan->get_error_message() );
			}

			if ( 'json' === strtolower( (string) array_get( $assoc_args, 'format', '' ) ) ) {
				\WP_CLI::line( wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				return;
			}

			\WP_CLI::log( wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			\WP_CLI::success( 'Pull plan generated without changes.' );
		}

		/**
		 * Pushes the local site into the live site.
		 *
		 * ## OPTIONS
		 *
		 * [--use-existing-snapshot]
		 * : Reuse the latest local snapshot instead of creating a fresh one.
		 *
		 * [--skip-remote-backup]
		 * : Skip live backup creation when live backups are explicitly enabled. Live backups are disabled by default.
		 *
		 * [--allow-partial-snapshot]
		 * : Explicitly allow pushing a snapshot that is not marked as full. Use only for deliberate recovery operations.
		 *
		 * [--recover-stale-remote-import]
		 * : Replace only a stale quarantined live import with a full snapshot that was created by that same live site.
		 *
		 * [--paths=<paths>]
		 * : Comma/newline separated relative paths to push as a file-only partial package. Cannot be combined with --use-existing-snapshot.
		 */
		public function push( $args, $assoc_args ) {
			if ( ! empty( $assoc_args['allow-partial-snapshot'] ) ) {
				\WP_CLI::warning( 'Partial snapshot override enabled. This can omit files from live if the selected snapshot is incomplete.' );
			}

			$paths = self::parse_path_list_arg( array_get( $assoc_args, 'paths', '' ) );
			if ( ! empty( $paths ) ) {
				\WP_CLI::log( 'Partial push paths: ' . implode( ', ', $paths ) );
			}

			$result = self::$sync->push_to_remote(
				array(
					'use_existing_snapshot'  => ! empty( $assoc_args['use-existing-snapshot'] ),
					'skip_remote_backup'     => ! empty( $assoc_args['skip-remote-backup'] ),
					'allow_partial_snapshot' => ! empty( $assoc_args['allow-partial-snapshot'] ),
					'recover_stale_remote_import' => ! empty( $assoc_args['recover-stale-remote-import'] ),
					'partial_paths'          => $paths,
				)
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Push completed.' );
		}

		/**
		 * Shows the non-mutating plan for a full or explicit partial push.
		 *
		 * ## OPTIONS
		 *
		 * [--paths=<paths>]
		 * : Comma/newline separated paths. Without it the plan remains full.
		 *
		 * [--format=<format>]
		 * : Use json for a machine-readable, non-mutating plan.
		 */
		public function push_plan( $args, $assoc_args ) {
			unset( $args );
			$paths = self::parse_path_list_arg( array_get( $assoc_args, 'paths', '' ) );
			$plan  = self::$sync->plan_push( $paths );
			if ( is_wp_error( $plan ) ) {
				\WP_CLI::error( $plan->get_error_message() );
			}

			if ( 'json' === strtolower( (string) array_get( $assoc_args, 'format', '' ) ) ) {
				\WP_CLI::line( wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				return;
			}

			\WP_CLI::log( wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			\WP_CLI::success( 'Push plan generated without changes.' );
		}

		/**
		 * Cleans local AG Sync runtime storage.
		 *
		 * ## OPTIONS
		 *
		 * [--snapshots=<count>]
		 * : Number of local snapshots to keep.
		 *
		 * [--backups=<count>]
		 * : Number of local backups to keep.
		 *
		 * [--temp-hours=<hours>]
		 * : Only delete temp/incoming runtime files older than this many hours. Use 0 after a failed sync when no operation is running.
		 */
		public function cleanup( $args, $assoc_args ) {
			$options = self::parse_cleanup_options( $assoc_args );
			$result  = self::$file_system->cleanup_runtime_storage( $options['snapshots'], $options['backups'], $options['temp_hours'] );

			self::log_cleanup_result( $result );
			\WP_CLI::success( 'Cleanup completed.' );
		}

		/**
		 * Cleans AG Sync runtime storage on the configured remote site.
		 *
		 * ## OPTIONS
		 *
		 * [--snapshots=<count>]
		 * : Number of remote snapshots to keep.
		 *
		 * [--backups=<count>]
		 * : Number of remote backups to keep.
		 *
		 * [--temp-hours=<hours>]
		 * : Only delete remote temp/incoming runtime files older than this many hours. Use 0 after a failed sync when no operation is running.
		 */
		public function remote_cleanup( $args, $assoc_args ) {
			$options = self::parse_cleanup_options( $assoc_args );
			$client  = new Http_Client( self::$config, self::$logger );
			$result  = $client->cleanup_remote_storage(
				array(
					'snapshots'  => $options['snapshots'],
					'backups'    => $options['backups'],
					'temp_hours' => $options['temp_hours'],
				)
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			self::log_cleanup_result( $result );
			\WP_CLI::success( 'Remote cleanup completed.' );
		}

		/** Audits remote WordPress storage without changing files. */
		public function remote_storage_audit() {
			$client = new Http_Client( self::$config, self::$logger );
			$result = $client->audit_remote_storage();
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			\WP_CLI::success( 'Remote storage audit completed without changes.' );
		}

		/**
		 * Requests cancellation of exactly one remote async snapshot or import.
		 *
		 * ## OPTIONS
		 *
		 * --operation-id=<id>
		 * : Remote operation ID returned by AG Sync status.
		 *
		 * --kind=<snapshot|import>
		 * : Type of remote operation to cancel.
		 */
		public function remote_cancel( $args, $assoc_args ) {
			unset( $args );

			$operation_id = sanitize_text_field( (string) array_get( $assoc_args, 'operation-id', '' ) );
			$kind         = sanitize_key( (string) array_get( $assoc_args, 'kind', '' ) );
			if ( ! $operation_id || ! in_array( $kind, array( 'snapshot', 'import' ), true ) ) {
				\WP_CLI::error( 'Specify --operation-id=<id> and --kind=snapshot|import.' );
			}

			$client = new Http_Client( self::$config, self::$logger );
			$result = $client->cancel_remote_operation( $operation_id, $kind );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			$operation = array_get( $result, 'operation', array() );
			\WP_CLI::success( 'Remote operation state: ' . array_get( $operation, 'status', 'unknown' ) . '.' );
		}

		/**
		 * Requests cooperative cancellation of the active local operation.
		 *
		 * ## OPTIONS
		 *
		 * [--token=<token>]
		 * : Optional lock token used to reject cancellation of a replaced operation.
		 */
		public function cancel( $args, $assoc_args ) {
			unset( $args );

			$token  = sanitize_text_field( (string) array_get( $assoc_args, 'token', '' ) );
			$result = self::$lock_manager->request_cancel( $token );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Local cancellation requested for operation: ' . array_get( $result, 'operation', 'unknown' ) . '.' );
		}

		/**
		 * Reconciles a stale remote operation without declaring it successful.
		 *
		 * ## OPTIONS
		 *
		 * --operation-id=<id>
		 * --kind=<snapshot|import>
		 * --action=<quarantine|close|recover>
		 * --expected-updated-at=<timestamp>
		 * --note=<text>
		 * --worker-absent-verified
		 * [--target-integrity-verified]
		 * [--rollback-verified]
		 */
		public function remote_reconcile( $args, $assoc_args ) {
			unset( $args );
			self::assert_local_remote_command();

			$payload = array(
				'operation_id'             => sanitize_text_field( (string) array_get( $assoc_args, 'operation-id', '' ) ),
				'kind'                     => sanitize_key( (string) array_get( $assoc_args, 'kind', '' ) ),
				'action'                   => sanitize_key( (string) array_get( $assoc_args, 'action', '' ) ),
				'expected_updated_at'      => sanitize_text_field( (string) array_get( $assoc_args, 'expected-updated-at', '' ) ),
				'note'                     => sanitize_text_field( (string) array_get( $assoc_args, 'note', '' ) ),
				'worker_absent_verified'   => ! empty( $assoc_args['worker-absent-verified'] ),
				'target_integrity_verified'=> ! empty( $assoc_args['target-integrity-verified'] ),
				'rollback_verified'        => ! empty( $assoc_args['rollback-verified'] ),
			);
			$worker_check_required = in_array( $payload['action'], array( 'quarantine', 'close' ), true );
			$recovery_proof        = ! empty( $payload['target_integrity_verified'] ) || ! empty( $payload['rollback_verified'] );
			if ( ! $payload['operation_id'] || ! in_array( $payload['kind'], array( 'snapshot', 'import' ), true ) || ! in_array( $payload['action'], array( 'quarantine', 'close', 'recover' ), true ) || ! $payload['expected_updated_at'] || ! $payload['note'] || ( $worker_check_required && ! $payload['worker_absent_verified'] ) || ( 'recover' === $payload['action'] && ! $recovery_proof ) ) {
				\WP_CLI::error( 'Specify operation ID, kind, action, expected updated_at and note. Quarantine/close require --worker-absent-verified; recover requires --target-integrity-verified or --rollback-verified.' );
			}

			$result = ( new Http_Client( self::$config, self::$logger ) )->reconcile_remote_operation( $payload );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$operation = array_get( $result, 'operation', array() );
			\WP_CLI::warning( 'Remote operation state: ' . array_get( $operation, 'status', 'unknown' ) . '. This is not a successful sync result.' );
		}

		/**
		 * Updates AG Sync Bridge on the configured live peer from one verified
		 * official GitHub release.
		 *
		 * ## OPTIONS
		 *
		 * --version=<version>
		 * : Exact release version; must match this local plugin version.
		 *
		 * --sha256=<checksum>
		 * : SHA-256 of the official ag-sync-bridge.zip release asset.
		 *
		 * --confirm=<text>
		 * : Exact confirmation: UPDATE AG SYNC
		 *
		 * [--recovery-hotfix]
		 * : Explicitly allow a signed bridge hotfix while a remote import is in rollback_required.
		 */
		public function remote_update_bridge( $args, $assoc_args ) {
			unset( $args );
			self::assert_local_remote_command();

			$version = trim( (string) array_get( $assoc_args, 'version', '' ) );
			$sha256  = strtolower( trim( (string) array_get( $assoc_args, 'sha256', '' ) ) );
			$confirm = (string) array_get( $assoc_args, 'confirm', '' );
			$recovery_hotfix = ! empty( $assoc_args['recovery-hotfix'] );
			if ( AG_SYNC_BRIDGE_VERSION !== $version ) {
				\WP_CLI::error( 'Target version must exactly match the AG Sync version installed on this local site.' );
			}
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || Remote_Update_Service::CONFIRMATION !== $confirm ) {
				\WP_CLI::error( 'Specify a valid --sha256 and exact --confirm="UPDATE AG SYNC".' );
			}

			$client = new Http_Client( self::$config, self::$logger );
			$status = $client->test_connection();
			if ( is_wp_error( $status ) ) {
				\WP_CLI::error( 'Remote preflight failed: ' . $status->get_error_message() );
			}
			$current = trim( (string) array_get( $status, 'plugin', '' ) );
			if ( ! $current || ! version_compare( $version, $current, '>' ) ) {
				\WP_CLI::error( 'Remote version is missing, equal or newer; reinstall and downgrade are blocked.' );
			}

			\WP_CLI::log( sprintf( 'Updating remote AG Sync Bridge %s -> %s from verified GitHub asset.', $current, $version ) );
			$result = $client->update_remote_bridge( $version, $sha256, $current, $confirm, $recovery_hotfix );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			$verified = null;
			for ( $attempt = 0; $attempt < 12; $attempt++ ) {
				if ( $attempt > 0 ) {
					sleep( 5 );
				}
				$verified = $client->test_connection();
				if ( ! is_wp_error( $verified ) && $version === (string) array_get( $verified, 'plugin', '' ) ) {
					break;
				}
			}
			if ( is_wp_error( $verified ) || $version !== (string) array_get( $verified, 'plugin', '' ) ) {
				\WP_CLI::error( 'Remote update returned but post-update version verification failed.' );
			}
			\WP_CLI::success( 'Remote AG Sync Bridge updated and version verified: ' . $version );
		}

		/**
		 * Enables verified pre-push backups on the configured live peer.
		 *
		 * ## OPTIONS
		 *
		 * --confirm=<text>
		 * : Exact confirmation: ENABLE REMOTE BACKUPS
		 */
		public function remote_enable_backups( $args, $assoc_args ) {
			unset( $args );
			self::assert_local_remote_command();

			$confirm = (string) array_get( $assoc_args, 'confirm', '' );
			if ( Rest_Controller::ENABLE_REMOTE_BACKUPS_CONFIRMATION !== $confirm ) {
				\WP_CLI::error( 'Specify exact --confirm="ENABLE REMOTE BACKUPS".' );
			}

			$result = ( new Http_Client( self::$config, self::$logger ) )->enable_remote_backups( $confirm );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			if ( empty( $result['enabled'] ) ) {
				\WP_CLI::error( 'Remote peer did not confirm that pre-push backups are enabled.' );
			}

			\WP_CLI::success(
				! empty( $result['previously_enabled'] )
					? 'Remote pre-push backups were already enabled.'
					: 'Remote pre-push backups enabled and verified.'
			);
		}

		private static function assert_local_remote_command() {
			if ( 'local' !== self::$config->get_role() || ! self::$config->get_remote_url() || ! self::$config->get_secret() ) {
				\WP_CLI::error( 'This command requires a configured local peer with remote URL and shared secret.' );
			}
		}

		private static function parse_cleanup_options( array $assoc_args ) {
			return array(
				'snapshots'  => isset( $assoc_args['snapshots'] ) ? absint( $assoc_args['snapshots'] ) : null,
				'backups'    => isset( $assoc_args['backups'] ) ? absint( $assoc_args['backups'] ) : null,
				'temp_hours' => isset( $assoc_args['temp-hours'] ) ? absint( $assoc_args['temp-hours'] ) : 6,
			);
		}

		private static function parse_path_list_arg( $value ) {
			if ( is_array( $value ) ) {
				$raw = $value;
			} else {
				$raw = preg_split( '/[\r\n,]+/', (string) $value );
			}

			$raw = is_array( $raw ) ? $raw : array();
			return array_values(
				array_filter(
					array_map(
						static function ( $path ) {
							return trim( (string) $path );
						},
						$raw
					)
				)
			);
		}

		private static function log_cleanup_result( array $result ) {
			$total = array_get( $result, 'total', array() );
			\WP_CLI::log( 'Deleted files: ' . (int) array_get( $total, 'deleted_files', 0 ) );
			\WP_CLI::log( 'Deleted directories: ' . (int) array_get( $total, 'deleted_dirs', 0 ) );
			\WP_CLI::log( 'Freed bytes: ' . (int) array_get( $total, 'deleted_bytes', 0 ) );
		}

		private static function estimate_required_bytes_for_doctor() {
			$list   = self::$file_system->list_packages( 'snapshots', 1, false );
			$latest = empty( $list ) ? array() : $list[0];
			$size   = (int) array_get( $latest, 'size_bytes', 0 );
			$path   = (string) array_get( $latest, 'path', '' );

			if ( $size <= 0 && $path && file_exists( $path ) ) {
				$size = (int) filesize( $path );
			}

			return $size > 0 ? $size * 2 : 268435456;
		}

		private static function log_doctor_result( $label, array $result ) {
			$ok = ! empty( $result['ok'] );
			\WP_CLI::log( $label . ' doctor: ' . ( $ok ? 'ok' : 'failed' ) );
			\WP_CLI::log( 'Required free space: ' . array_get( $result, 'required_free_human', format_bytes( (int) array_get( $result, 'required_free_bytes', 0 ) ) ) );

			$directories = array_get( $result, 'directories', array() );
			if ( ! is_array( $directories ) ) {
				return $ok;
			}

			foreach ( $directories as $scope => $directory ) {
				$dir_ok = ! empty( $directory['ok'] );
				\WP_CLI::log(
					sprintf(
						'  - %s: %s, writable=%s, write_test=%s, free=%s, path=%s',
						$scope,
						$dir_ok ? 'ok' : 'failed',
						! empty( $directory['writable'] ) ? 'yes' : 'no',
						! empty( $directory['write_test'] ) ? 'yes' : 'no',
						(string) array_get( $directory, 'free_human', '' ),
						(string) array_get( $directory, 'path', '' )
					)
				);

				$errors = array_get( $directory, 'errors', array() );
				if ( is_array( $errors ) && ! empty( $errors ) ) {
					\WP_CLI::log( '    errors: ' . implode( ', ', $errors ) );
				}
			}

			$latest_snapshot = array_get( $result, 'latest_snapshot', array() );
			if ( is_array( $latest_snapshot ) && ! empty( $latest_snapshot ) ) {
				$scope = array_get( $latest_snapshot, 'snapshot_scope', 'unknown' );
				\WP_CLI::log( 'Latest snapshot scope: ' . $scope );
				if ( 'partial' === $scope ) {
					$validation = array_get( $latest_snapshot, 'partial_snapshot_validation', array() );
					\WP_CLI::log( 'Latest snapshot full validation: not applicable' );
					if ( is_array( $validation ) && ! empty( $validation ) ) {
						\WP_CLI::log( 'Latest snapshot partial validation: ' . ( ! empty( $validation['ok'] ) ? 'ok' : 'failed' ) );
						\WP_CLI::log( '    partial paths: ' . implode( ', ', array_get( $validation, 'partial_paths', array() ) ) );
					}
				} else {
					$validation = array_get( $latest_snapshot, 'full_snapshot_validation', array() );
					if ( is_array( $validation ) && ! empty( $validation ) ) {
						\WP_CLI::log( 'Latest snapshot full validation: ' . ( ! empty( $validation['ok'] ) ? 'ok' : 'failed' ) );
					}
				}
				if ( isset( $validation ) && is_array( $validation ) && ! empty( $validation ) ) {
					$errors = array_get( $validation, 'errors', array() );
					if ( is_array( $errors ) && ! empty( $errors ) ) {
						\WP_CLI::log( '    validation errors: ' . implode( ', ', $errors ) );
					}
				}
			}

			$sitemap_integrity = array_get( $result, 'sitemap_integrity', array() );
			if ( is_array( $sitemap_integrity ) && ! empty( $sitemap_integrity ) ) {
				\WP_CLI::log( 'Root sitemap integrity: ' . ( ! empty( $sitemap_integrity['ok'] ) ? 'ok' : 'failed' ) );
				$missing = array_get( $sitemap_integrity, 'missing_root_sitemap_files', array() );
				if ( is_array( $missing ) && ! empty( $missing ) ) {
					\WP_CLI::log( '    missing root XML files: ' . implode( ', ', $missing ) );
				}
			}

			return $ok;
		}
	}
} else {
	class CLI {
		public static function register( Config $config, Logger $logger, Lock_Manager $lock_manager, File_System_Service $file_system, Sync_Service $sync ) {
			// WP-CLI is not available in the current runtime.
		}
	}
}
