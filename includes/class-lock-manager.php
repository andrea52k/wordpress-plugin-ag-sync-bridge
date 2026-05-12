<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lock_Manager {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var string
	 */
	private $active_token = '';

	/**
	 * @var bool
	 */
	private $shutdown_registered = false;

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function acquire( $operation, $ttl = 7200 ) {
		$lock = $this->get_lock();

		if ( $lock && ( time() - absint( array_get( $lock, 'timestamp', 0 ) ) ) < absint( $ttl ) ) {
			return new \WP_Error(
				'ag_sync_bridge_locked',
				sprintf(
					/* translators: %s: operation name. */
					__( 'AG Sync Bridge is already running: %s', 'ag-sync-bridge' ),
					array_get( $lock, 'operation', 'unknown' )
				)
			);
		}

		$data = array(
			'operation' => sanitize_key( $operation ),
			'timestamp' => time(),
			'started_at'=> gmdate( 'c' ),
			'updated_at'=> gmdate( 'c' ),
			'token'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( (string) wp_rand(), true ) ),
			'pid'       => function_exists( 'getmypid' ) ? getmypid() : 0,
			'status'    => 'running',
			'stage'     => 'lock',
			'progress'  => 0,
			'user_id'   => get_current_user_id(),
		);

		$this->active_token = $data['token'];
		$this->write_lock( $data );
		$this->register_shutdown_handler();
		$this->config->set_state_value( 'current_operation', $data );
		$this->logger->info( 'Lock acquired.', $data );

		return true;
	}

	public function release( $token = '' ) {
		$file = $this->get_lock_file();
		$lock = $this->get_lock();
		$token = $token ? (string) $token : $this->active_token;

		if ( $token && ! empty( $lock['token'] ) && ! hash_equals( (string) $lock['token'], $token ) ) {
			$this->logger->warning(
				'Lock release skipped because token does not match.',
				array(
					'expected' => $token,
					'current'  => array_get( $lock, 'token', '' ),
				)
			);
			return false;
		}

		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		$this->config->set_state_value( 'current_operation', array() );
		$this->active_token = '';
		$this->logger->info( 'Lock released.' );
		return true;
	}

	public function force_release( $reason = 'manual' ) {
		$file = $this->get_lock_file();
		$lock = $this->get_lock();

		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		$this->config->set_state_value( 'current_operation', array() );
		$this->active_token = '';
		$this->logger->warning(
			'Lock force released.',
			array(
				'reason' => (string) $reason,
				'lock'   => $lock,
			)
		);
		return true;
	}

	public function touch( array $updates = array() ) {
		$lock = $this->get_lock();
		if ( empty( $lock ) || empty( $this->active_token ) ) {
			return false;
		}
		if ( ! empty( $lock['token'] ) && ! hash_equals( (string) $lock['token'], $this->active_token ) ) {
			return false;
		}

		$lock = array_merge(
			$lock,
			array_intersect_key(
				$updates,
				array_flip( array( 'operation', 'status', 'stage', 'progress', 'message', 'started_at' ) )
			)
		);
		$lock['updated_at'] = gmdate( 'c' );
		$this->write_lock( $lock );
		return true;
	}

	public function get_lock() {
		$file = $this->get_lock_file();

		if ( ! file_exists( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	public function get_lock_path() {
		return $this->get_lock_file();
	}

	private function get_lock_file() {
		return $this->config->get_data_dir( 'temp/operation.lock' );
	}

	private function write_lock( array $data ) {
		$file = $this->get_lock_file();
		ensure_directory( dirname( $file ) );
		file_put_contents( $file, wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), LOCK_EX );
	}

	private function register_shutdown_handler() {
		if ( $this->shutdown_registered ) {
			return;
		}

		$this->shutdown_registered = true;
		register_shutdown_function( array( $this, 'release_on_shutdown' ) );
	}

	public function release_on_shutdown() {
		if ( empty( $this->active_token ) ) {
			return;
		}

		$error = error_get_last();
		if ( $error && in_array( (int) array_get( $error, 'type', 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			$this->logger->error( 'Fatal shutdown while AG Sync lock was active.', $error );
		}

		$this->release( $this->active_token );
	}
}
