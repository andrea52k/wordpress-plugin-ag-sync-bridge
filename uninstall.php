<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hooks = array(
	'ag_sync_bridge_weekly_snapshot',
	'ag_sync_bridge_weekly_pull',
	'ag_sync_bridge_async_import_snapshot',
	'ag_sync_bridge_async_create_snapshot',
);

foreach ( $hooks as $hook ) {
	$next = wp_next_scheduled( $hook );

	while ( $next ) {
		wp_unschedule_event( $next, $hook );
		$next = wp_next_scheduled( $hook );
	}
}

delete_option( 'ag_sync_bridge_settings' );
delete_option( 'ag_sync_bridge_state' );
delete_option( 'ag_sync_bridge_recent_logs' );
