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
		 */
		public function snapshot( $args, $assoc_args ) {
			$type   = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : 'cli-snapshot';
			$result = self::$sync->create_snapshot( $type, array( 'trigger' => 'wp-cli' ) );

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
		 */
		public function pull( $args, $assoc_args ) {
			$result = self::$sync->pull_from_remote(
				array(
					'use_existing_snapshot' => ! empty( $assoc_args['use-existing-snapshot'] ),
				)
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Pull completed.' );
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
		 * : Skip live backup creation, useful only for retrying a failed upload after a backup already succeeded.
		 */
		public function push( $args, $assoc_args ) {
			$result = self::$sync->push_to_remote(
				array(
					'use_existing_snapshot' => ! empty( $assoc_args['use-existing-snapshot'] ),
					'skip_remote_backup'    => ! empty( $assoc_args['skip-remote-backup'] ),
				)
			);

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			\WP_CLI::success( 'Push completed.' );
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

		private static function parse_cleanup_options( array $assoc_args ) {
			return array(
				'snapshots'  => isset( $assoc_args['snapshots'] ) ? absint( $assoc_args['snapshots'] ) : null,
				'backups'    => isset( $assoc_args['backups'] ) ? absint( $assoc_args['backups'] ) : null,
				'temp_hours' => isset( $assoc_args['temp-hours'] ) ? absint( $assoc_args['temp-hours'] ) : 6,
			);
		}

		private static function log_cleanup_result( array $result ) {
			$total = array_get( $result, 'total', array() );
			\WP_CLI::log( 'Deleted files: ' . (int) array_get( $total, 'deleted_files', 0 ) );
			\WP_CLI::log( 'Deleted directories: ' . (int) array_get( $total, 'deleted_dirs', 0 ) );
			\WP_CLI::log( 'Freed bytes: ' . (int) array_get( $total, 'deleted_bytes', 0 ) );
		}
	}
} else {
	class CLI {
		public static function register( Config $config, Logger $logger, Lock_Manager $lock_manager, File_System_Service $file_system, Sync_Service $sync ) {
			// WP-CLI is not available in the current runtime.
		}
	}
}
