<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {
	const HOOK_WEEKLY_SNAPSHOT = 'ag_sync_bridge_weekly_snapshot';
	const HOOK_WEEKLY_PULL     = 'ag_sync_bridge_weekly_pull';
	const HOOK_ASYNC_IMPORT    = 'ag_sync_bridge_async_import_snapshot';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var Sync_Service
	 */
	private $sync;

	public function __construct( Config $config, Logger $logger, Sync_Service $sync ) {
		$this->config = $config;
		$this->logger = $logger;
		$this->sync   = $sync;
	}

	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( self::HOOK_WEEKLY_SNAPSHOT, array( $this, 'run_weekly_snapshot' ) );
		add_action( self::HOOK_WEEKLY_PULL, array( $this, 'run_weekly_pull' ) );
		$this->schedule_if_needed();
	}

	public function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['ag_sync_bridge_weekly'] ) ) {
			$schedules['ag_sync_bridge_weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly (AG Sync Bridge)', 'ag-sync-bridge' ),
			);
		}

		return $schedules;
	}

	public function schedule_if_needed( $force = false ) {
		$is_remote           = 'remote' === $this->config->get_role();
		$is_local_auto_pull  = 'local' === $this->config->get_role() && ! empty( $this->config->get( 'auto_pull_enabled', false ) );
		$scheduled_snapshot  = wp_next_scheduled( self::HOOK_WEEKLY_SNAPSHOT );
		$scheduled_pull      = wp_next_scheduled( self::HOOK_WEEKLY_PULL );

		if ( $is_remote ) {
			if ( $force || ! $scheduled_snapshot ) {
				if ( $scheduled_snapshot ) {
					wp_unschedule_event( $scheduled_snapshot, self::HOOK_WEEKLY_SNAPSHOT );
				}

				wp_schedule_event( time() + HOUR_IN_SECONDS, 'ag_sync_bridge_weekly', self::HOOK_WEEKLY_SNAPSHOT );
			}
		} else {
			$this->clear_hook( self::HOOK_WEEKLY_SNAPSHOT );
		}

		if ( $is_local_auto_pull ) {
			if ( $force || ! $scheduled_pull ) {
				if ( $scheduled_pull ) {
					wp_unschedule_event( $scheduled_pull, self::HOOK_WEEKLY_PULL );
				}

				wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'ag_sync_bridge_weekly', self::HOOK_WEEKLY_PULL );
			}
		} else {
			$this->clear_hook( self::HOOK_WEEKLY_PULL );
		}
	}

	public function clear() {
		$this->clear_hook( self::HOOK_WEEKLY_SNAPSHOT );
		$this->clear_hook( self::HOOK_WEEKLY_PULL );
	}

	public function run_weekly_snapshot() {
		$result = $this->sync->create_snapshot(
			'weekly-snapshot',
			array(
				'trigger' => 'wp-cron',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Weekly snapshot failed.', array( 'error' => $result->get_error_message() ) );
			return;
		}

		$this->logger->info( 'Weekly snapshot completed.', array( 'basename' => array_get( $result, 'basename', '' ) ) );
	}

	public function run_weekly_pull() {
		$result = $this->sync->pull_from_remote(
			array(
				'trigger'               => 'wp-cron',
				'use_existing_snapshot' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Weekly local pull failed.', array( 'error' => $result->get_error_message() ) );
			return;
		}

		$this->logger->info( 'Weekly local pull completed.', array( 'snapshot' => array_get( array_get( $result, 'remote', array() ), 'basename', '' ) ) );
	}

	private function clear_hook( $hook ) {
		$scheduled = wp_next_scheduled( $hook );

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, $hook );
			$scheduled = wp_next_scheduled( $hook );
		}
	}
}
