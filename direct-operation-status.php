<?php
/**
 * Direct maintenance-safe status endpoint. This file intentionally does not
 * load wp-load.php: WordPress exits early while .maintenance is active.
 */

require_once __DIR__ . '/includes/class-direct-operation-monitor.php';

use AGSyncBridge\Direct_Operation_Monitor;

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, private, max-age=0' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );

if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
	header( 'Allow: GET' );
	http_response_code( 405 );
	echo '{"error":"method_not_allowed"}';
	exit;
}

$operation_id = isset( $_SERVER['HTTP_X_AGSB_OPERATION_ID'] ) ? (string) $_SERVER['HTTP_X_AGSB_OPERATION_ID'] : '';
$token        = isset( $_SERVER['HTTP_X_AGSB_OPERATION_TOKEN'] ) ? (string) $_SERVER['HTTP_X_AGSB_OPERATION_TOKEN'] : '';
$monitor      = new Direct_Operation_Monitor();
$operation    = $monitor->read_authenticated( $operation_id, $token );

if ( ! is_array( $operation ) ) {
	http_response_code( 401 );
	echo '{"error":"unauthorized"}';
	exit;
}

http_response_code( 200 );
echo json_encode(
	array(
		'remote_import_operation' => $operation,
		'source'                  => 'direct-maintenance-monitor',
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
