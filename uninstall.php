<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$hook = 'ag_sync_bridge_weekly_snapshot';
$next = wp_next_scheduled( $hook );

while ( $next ) {
	wp_unschedule_event( $next, $hook );
	$next = wp_next_scheduled( $hook );
}

delete_option( 'ag_sync_bridge_settings' );
delete_option( 'ag_sync_bridge_state' );
delete_option( 'ag_sync_bridge_recent_logs' );
