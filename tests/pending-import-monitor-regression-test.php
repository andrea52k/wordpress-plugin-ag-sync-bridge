<?php
$root = dirname( __DIR__ );

function expect_pending_monitor( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$rest = file_get_contents( $root . '/includes/class-rest-controller.php' );
$start = strpos( $rest, 'public function run_pending_import' );
$end = strpos( $rest, 'public function cleanup_storage', $start );
$handler = substr( $rest, $start, $end - $start );
$database = file_get_contents( $root . '/includes/class-database-service.php' );
$admin = file_get_contents( $root . '/assets/admin.js' );
$page = file_get_contents( $root . '/includes/class-admin-page.php' );

expect_pending_monitor( false !== strpos( $handler, 'spawn_cron( time() )' ), 'Recovery must dispatch WP-Cron.' );
expect_pending_monitor( false === strpos( $handler, '$this->run_async_import_snapshot(' ), 'Recovery REST request must never run a multi-gigabyte import inline.' );
expect_pending_monitor( false !== strpos( $handler, "'recovery_dispatched'     => true" ) && false !== strpos( $handler, "202\n" ), 'Recovery must return an accepted 202 response.' );
expect_pending_monitor( false !== strpos( $handler, "array_get( \$operation, 'schedule_args', array() )" ), 'Recovery must preserve the exact stored import contract.' );
expect_pending_monitor( false !== strpos( $database, "'expected_size_bytes' => \$expected_size_bytes" ), 'Database heartbeat must expose its verified total byte count.' );
expect_pending_monitor( false !== strpos( $rest, "20 + (int) floor( min( 1, \$processed / \$expected ) * 35 )" ), 'Database byte progress must map into the remote 20-55 percent interval.' );
expect_pending_monitor( false !== strpos( $admin, 'if (panel && !panel.hidden)' ), 'Admin monitor must resume polling after page reload.' );
expect_pending_monitor( false !== strpos( $admin, 'operationResolved = false;') && false !== strpos( $admin, 'pollStatus();' ), 'Transport failure must hand off to independent status polling.' );
expect_pending_monitor( false !== strpos( $page, '$this->sync->refresh_remote_import_monitor();' ), 'Admin status endpoint must refresh the durable remote monitor.' );

echo "pending-import-monitor-regression-test: ok\n";
