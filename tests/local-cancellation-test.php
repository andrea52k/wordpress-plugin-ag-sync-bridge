<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function __( $message ) { return $message; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
	function get_current_user_id() { return 0; }
	function wp_generate_uuid4() { return 'local-cancel-test-token'; }
	function wp_rand() { return 42; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

	class WP_Error {
		private $code;
		public function __construct( $code = '', $message = '', $data = null ) {
			unset( $message, $data );
			$this->code = $code;
		}
		public function get_error_code() { return $this->code; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

namespace AGSyncBridge {
	function array_get( $array, $key, $default = null ) {
		return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default;
	}
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function ensure_directory( $path ) {
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}
	}

	class Config {
		public $root;
		public $state = array();
		public function __construct( $root ) { $this->root = $root; }
		public function get_data_dir( $suffix = '' ) {
			return normalize_path( $this->root . ( $suffix ? '/' . ltrim( $suffix, '/\\' ) : '' ) );
		}
		public function set_state_value( $key, $value ) { $this->state[ $key ] = $value; }
	}
	class Logger {
		public function info( $message, array $context = array() ) { unset( $message, $context ); }
		public function warning( $message, array $context = array() ) { unset( $message, $context ); }
		public function error( $message, array $context = array() ) { unset( $message, $context ); }
	}

	require_once dirname( __DIR__ ) . '/includes/class-lock-manager.php';
}

namespace {
	function expect_local_cancel( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	$root    = sys_get_temp_dir() . '/agsync-local-cancel-' . bin2hex( random_bytes( 4 ) );
	$config  = new \AGSyncBridge\Config( $root );
	$manager = new \AGSyncBridge\Lock_Manager( $config, new \AGSyncBridge\Logger() );

	expect_local_cancel( true === $manager->acquire( 'push' ), 'Local lock must be acquired.' );
	$mismatch = $manager->request_cancel( 'different-token' );
	expect_local_cancel( is_wp_error( $mismatch ), 'A replaced operation token must be rejected.' );

	$cancelled = $manager->request_cancel( 'local-cancel-test-token' );
	expect_local_cancel( ! is_wp_error( $cancelled ), 'Active local operation must accept cancellation.' );
	expect_local_cancel( ! empty( $cancelled['cancel_requested'] ), 'Cancellation must be durable in the lock.' );
	expect_local_cancel( true === $manager->is_cancel_requested(), 'Worker must observe the cancellation marker.' );

	$manager->touch( array( 'stage' => 'upload', 'progress' => 50 ) );
	expect_local_cancel( true === $manager->is_cancel_requested(), 'Heartbeat updates must preserve cancellation.' );
	expect_local_cancel( true === $manager->release(), 'Local lock must be released.' );

	@rmdir( $root . '/temp' );
	@rmdir( $root );

	echo "local cooperative cancellation: ok\n";
}
