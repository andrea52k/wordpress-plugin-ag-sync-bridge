<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function __( $message ) { return $message; }
	class WP_Error {
		private $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}

namespace AGSyncBridge {
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function ensure_directory( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
}

namespace {

require_once dirname( __DIR__ ) . '/includes/class-config.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-remote-operation-runtime.php';

class Runtime_Test_Config extends \AGSyncBridge\Config {
	private $dir;
	public function __construct( $dir ) { $this->dir = $dir; }
	public function get_data_dir( $subdir = '' ) { return rtrim( $this->dir, '/' ) . ( $subdir ? '/' . $subdir : '' ); }
}
$root = sys_get_temp_dir() . '/agsync-runtime-' . bin2hex( random_bytes( 4 ) );
$config = new Runtime_Test_Config( $root );
$runtime = new \AGSyncBridge\Remote_Operation_Runtime( $config, new \AGSyncBridge\Logger( $config ) );
function expect_true( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$first = $runtime->reserve( 'import', array( 'id' => 'one' ) );
expect_true( ! is_wp_error( $first ) && 'queued' === $first['status'], 'reserve queued operation' );
expect_true( is_wp_error( $runtime->reserve( 'snapshot', array( 'id' => 'two' ) ) ), 'reject concurrent operation' );
$cancelled = $runtime->request_cancel( 'one', 'import' );
expect_true( 'cancelled' === $cancelled['status'], 'cancel queued operation' );
expect_true( is_wp_error( $runtime->claim( 'one' ) ), 'cancelled operation cannot claim' );

$second = $runtime->reserve( 'import', array( 'id' => 'two' ) );
expect_true( 'queued' === $second['status'], 'reserve after terminal operation' );
$running = $runtime->claim( 'two' );
expect_true( 'running' === $running['status'], 'claim queued operation' );
$requested = $runtime->request_cancel( 'two', 'import' );
expect_true( 'cancel_requested' === $requested['status'], 'request cancel running operation' );
$final = $runtime->finalize( 'two', 'complete', array( 'rollback_required' => true ) );
expect_true( 'rollback_required' === $final['status'], 'cancelled mutation requires rollback' );

$third = $runtime->reserve( 'import', array( 'id' => 'three' ) );
expect_true( 'queued' === $third['status'], 'reserve after rollback-required operation' );
expect_true( 'running' === $runtime->claim( 'three' )['status'], 'claim third operation' );
$complete = $runtime->finalize( 'three', 'complete', array( 'rollback_required' => true ) );
expect_true( 'complete' === $complete['status'], 'complete operation remains complete without cancellation' );
expect_true( ! isset( $complete['rollback_required'] ), 'completed operation does not falsely require rollback' );
echo "remote operation runtime: ok\n";
}
