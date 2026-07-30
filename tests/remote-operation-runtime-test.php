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
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
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
expect_true( 'cancelled' === $cancelled['stage'] && ! empty( $cancelled['finished_at'] ), 'queued cancellation is durably terminal' );
expect_true( is_wp_error( $runtime->claim( 'one' ) ), 'cancelled operation cannot claim' );

$second = $runtime->reserve( 'import', array( 'id' => 'two' ) );
expect_true( 'queued' === $second['status'], 'reserve after terminal operation' );
$running = $runtime->claim( 'two' );
expect_true( 'running' === $running['status'], 'claim queued operation' );
$requested = $runtime->request_cancel( 'two', 'import' );
expect_true( 'cancel_requested' === $requested['status'], 'request cancel running operation' );
$final = $runtime->finalize( 'two', 'complete', array( 'rollback_required' => true ) );
expect_true( 'rollback_required' === $final['status'], 'cancelled mutation requires rollback' );
expect_true( is_wp_error( $runtime->reserve( 'snapshot', array( 'id' => 'blocked' ) ) ), 'rollback-required operation blocks new work' );
$recovery = $runtime->resolve_recovery(
	'two',
	'import',
	$final['updated_at'],
	array( 'rollback_verified' => true, 'note' => 'Pre-import backup restored and checked.' )
);
expect_true( 'reconciled' === $recovery['status'], 'verified recovery unblocks the runtime' );
expect_true( false === $recovery['recovery']['declared_success'], 'recovery does not declare sync success' );

$third = $runtime->reserve( 'import', array( 'id' => 'three' ) );
expect_true( 'queued' === $third['status'], 'reserve after rollback-required operation' );
expect_true( 'running' === $runtime->claim( 'three' )['status'], 'claim third operation' );
$complete = $runtime->finalize( 'three', 'complete', array( 'rollback_required' => true ) );
expect_true( 'complete' === $complete['status'], 'complete operation remains complete without cancellation' );
expect_true( ! isset( $complete['rollback_required'] ), 'completed operation does not falsely require rollback' );

$dirty_error = $runtime->reserve( 'import', array( 'id' => 'dirty-error' ) );
$runtime->claim( 'dirty-error' );
$runtime->heartbeat( 'dirty-error', 'database-import', 45, array( 'target_mutated' => true ) );
$dirty_error = $runtime->finalize( 'dirty-error', 'error', array( 'message' => 'Database import failed.' ) );
expect_true( 'rollback_required' === $dirty_error['status'], 'error after target mutation requires rollback' );
$runtime->resolve_recovery(
	'dirty-error',
	'import',
	$dirty_error['updated_at'],
	array( 'target_integrity_verified' => true, 'note' => 'Target checked against the pre-import manifest.' )
);

$fourth = $runtime->reserve( 'import', array( 'id' => 'four' ) );
$running = $runtime->claim( 'four' );
$heartbeat = $runtime->heartbeat( 'four', 'database-import', 55, array( 'checkpoint' => 'after_database_import' ) );
expect_true( 'database-import' === $heartbeat['stage'] && 55 === $heartbeat['progress'], 'heartbeat updates stage and progress' );
expect_true( $heartbeat['heartbeat_sequence'] > $running['heartbeat_sequence'], 'heartbeat sequence advances' );
$inspected = $runtime->inspect( 60 );
expect_true( 'active' === $inspected['heartbeat']['liveness'] && ! $inspected['heartbeat']['is_stale'], 'fresh heartbeat is active' );

$state_path = $root . '/operations/remote-operation.json';
$state = json_decode( file_get_contents( $state_path ), true );
$state['heartbeat_at'] = gmdate( 'c', time() - 120 );
$state['updated_at'] = $state['heartbeat_at'];
file_put_contents( $state_path, json_encode( $state ) );
$stale = $runtime->inspect( 60 );
expect_true( 'stale_or_orphaned' === $stale['heartbeat']['liveness'] && $stale['heartbeat']['is_stale'], 'expired heartbeat is stale, not successful' );

$quarantined = $runtime->request_reconciliation( 'four', 'import', $state['updated_at'], 'Worker verified absent.', 60 );
expect_true( 'reconcile_requested' === $quarantined['status'], 'stale operation enters quarantine' );
expect_true( $runtime->is_cancel_requested( 'four' ), 'quarantine requests cooperative worker stop' );
$premature = $runtime->close_reconciliation(
	'four',
	'import',
	$quarantined['updated_at'],
	array( 'worker_absent_verified' => true, 'target_integrity_verified' => true, 'note' => 'Checked.' )
);
expect_true( is_wp_error( $premature ), 'grace period blocks immediate closure' );

$state = json_decode( file_get_contents( $state_path ), true );
$state['reconcile_requested_at'] = gmdate( 'c', time() - 60 );
file_put_contents( $state_path, json_encode( $state ) );
$closed = $runtime->close_reconciliation(
	'four',
	'import',
	$state['updated_at'],
	array( 'worker_absent_verified' => true, 'target_integrity_verified' => true, 'note' => 'Front end, identity and data verified.' )
);
expect_true( 'reconciled' === $closed['status'], 'verified orphan closes as reconciled' );
expect_true( false === $closed['reconciliation']['declared_success'], 'reconciliation never declares sync success' );
expect_true( $closed['progress'] < 100, 'reconciled operation never reports 100 percent' );

$fifth = $runtime->reserve( 'import', array( 'id' => 'five' ) );
$runtime->claim( 'five' );
$state = json_decode( file_get_contents( $state_path ), true );
$state['heartbeat_at'] = gmdate( 'c', time() - 120 );
$state['updated_at'] = $state['heartbeat_at'];
file_put_contents( $state_path, json_encode( $state ) );
$quarantined = $runtime->request_reconciliation( 'five', 'import', $state['updated_at'], 'Worker verified absent.', 60 );
$resumed = $runtime->finalize( 'five', 'cancelled', array( 'rollback_required' => true ) );
expect_true( 'rollback_required' === $resumed['status'], 'quarantined worker finalization preserves rollback requirement' );
echo "remote operation runtime: ok\n";
}
