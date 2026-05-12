<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	/**
	 * @var Config
	 */
	private $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	public function log( $level, $message, array $context = array() ) {
		$timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$line      = sprintf(
			'[%1$s] [%2$s] %3$s%4$s',
			$timestamp,
			strtoupper( $level ),
			trim( (string) $message ),
			$context ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : ''
		);

		$log_file = $this->get_log_file();
		if ( ensure_directory( dirname( $log_file ) ) ) {
			file_put_contents( $log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
		}

		$recent_logs   = get_option( Config::OPTION_RECENT_LOGS, array() );
		$recent_logs   = is_array( $recent_logs ) ? $recent_logs : array();
		$recent_logs[] = $line;
		$recent_logs   = array_slice( $recent_logs, -200 );

		update_option( Config::OPTION_RECENT_LOGS, $recent_logs, false );
		$this->config->set_state_value( 'last_operation_log', $line );
	}

	public function get_recent_entries( $limit = 30 ) {
		$recent = get_option( Config::OPTION_RECENT_LOGS, array() );
		$recent = is_array( $recent ) ? $recent : array();
		return array_reverse( array_slice( $recent, -absint( $limit ) ) );
	}

	public function get_log_file() {
		return $this->config->get_data_dir( 'logs/ag-sync-bridge-' . gmdate( 'Y-m-d' ) . '.log' );
	}
}
