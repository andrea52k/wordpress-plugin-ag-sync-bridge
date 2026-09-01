<?php
$root = dirname( __DIR__ );
require_once $root . '/includes/class-direct-operation-monitor.php';

function expect_direct_monitor( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$sandbox = sys_get_temp_dir() . '/agsb-direct-monitor-' . bin2hex( random_bytes( 6 ) );
mkdir( $sandbox, 0700, true );
$monitor = new \AGSyncBridge\Direct_Operation_Monitor( $sandbox );
$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
$id      = '12345678-1234-4234-8234-123456789abc';
$operation = array(
	'id' => $id,
	'kind' => 'import',
	'status' => 'queued',
	'stage' => 'remote-import',
	'progress' => 0,
	'snapshot' => 'must-not-be-exposed.zip',
);

expect_direct_monitor( $monitor->arm( $id, hash( 'sha256', $token ), time() + 300, $operation ), 'Monitor could not be armed.' );
expect_direct_monitor( false === strpos( file_get_contents( $monitor->get_state_path() ), $token ), 'Raw operation token was persisted.' );
expect_direct_monitor( false === $monitor->read_authenticated( $id, str_repeat( 'x', 43 ) ), 'Wrong token was accepted.' );
expect_direct_monitor( false === $monitor->read_authenticated( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $token ), 'Wrong operation ID was accepted.' );
$read = $monitor->read_authenticated( $id, $token );
expect_direct_monitor( is_array( $read ) && 'queued' === $read['status'], 'Valid operation status was not returned.' );
expect_direct_monitor( ! isset( $read['snapshot'] ), 'Non-allowlisted operation data leaked.' );

$operation['status'] = 'running';
$operation['stage'] = 'import-database';
$operation['progress'] = 42;
expect_direct_monitor( $monitor->publish( $operation ), 'Monitor update failed.' );
$read = $monitor->read_authenticated( $id, $token );
expect_direct_monitor( 'running' === $read['status'] && 42 === $read['progress'], 'Monitor did not publish progress.' );

$expired = new \AGSyncBridge\Direct_Operation_Monitor( $sandbox . '-expired' );
expect_direct_monitor( $expired->arm( $id, hash( 'sha256', $token ), time() - 120, $operation ), 'Expired monitor fixture could not be written.' );
// arm() enforces a minimum lifetime; modify only this isolated fixture to test expiry.
$expired_path = $expired->get_state_path();
$expired_data = json_decode( file_get_contents( $expired_path ), true );
$expired_data['expires_at'] = time() - 1;
file_put_contents( $expired_path, json_encode( $expired_data ) );
expect_direct_monitor( false === $expired->read_authenticated( $id, $token ), 'Expired token was accepted.' );

$endpoint = file_get_contents( $root . '/direct-operation-status.php' );
$client   = file_get_contents( $root . '/includes/class-http-client.php' );
expect_direct_monitor( ! preg_match( '/(?:require|include)(?:_once)?[^;]*wp-load\.php/i', $endpoint ), 'Direct endpoint must not bootstrap WordPress.' );
expect_direct_monitor( false !== strpos( $endpoint, 'HTTP_X_AGSB_OPERATION_TOKEN' ), 'Direct endpoint must require the operation token header.' );
expect_direct_monitor( false !== strpos( $client, "503 === \$this->get_remote_http_error_status( \$status )" ), 'Client fallback must be limited to REST HTTP 503.' );
expect_direct_monitor( substr_count( $client, '$this->request_direct_import_status( $operation_id, $monitor_token, $monitor_path )' ) >= 2, 'Direct monitor must support both import polling and durable admin refresh.' );
expect_direct_monitor( false !== strpos( $client, 'Config::OPTION_REMOTE_MONITOR' ), 'Client must persist durable local monitor metadata.' );
expect_direct_monitor( false !== strpos( $client, 'call_user_func( $status_callback, $operation )' ), 'Authenticated direct status must reach the local progress callback.' );

echo "direct-operation-monitor-test: ok\n";
