<?php
declare( strict_types=1 );

$config_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-config.php' );
$rest_source   = file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-controller.php' );
$client_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-http-client.php' );
$cli_source    = file_get_contents( dirname( __DIR__ ) . '/includes/class-cli.php' );

function expect_remote_backup_policy( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_remote_backup_policy( false !== strpos( $config_source, 'function enable_remote_backups()' ), 'Config must own the persisted policy change.' );
expect_remote_backup_policy( false !== strpos( $config_source, 'AG_SYNC_BRIDGE_REMOTE_BACKUPS_ENABLED' ), 'A server constant must remain authoritative.' );
expect_remote_backup_policy( false !== strpos( $rest_source, "ENABLE_REMOTE_BACKUPS_CONFIRMATION = 'ENABLE REMOTE BACKUPS'" ), 'Remote route must require exact confirmation.' );
expect_remote_backup_policy( false !== strpos( $rest_source, "'remote' !== \$this->config->get_role()" ), 'Remote route must reject the wrong role.' );
expect_remote_backup_policy( false !== strpos( $rest_source, '$this->runtime->inspect()' ), 'Remote route must use the authoritative file-backed runtime.' );
expect_remote_backup_policy( false !== strpos( $rest_source, 'ag_sync_bridge_backup_policy_operation_running' ), 'Remote route must reject unresolved operations.' );
expect_remote_backup_policy( false !== strpos( $client_source, '/maintenance/enable-remote-backups' ), 'HTTP client must use the signed maintenance route.' );
expect_remote_backup_policy( false !== strpos( $cli_source, 'function remote_enable_backups' ), 'WP-CLI must expose the guarded peer command.' );
expect_remote_backup_policy( false !== strpos( $cli_source, "empty( \$result['enabled'] )" ), 'WP-CLI must verify the persisted remote result.' );

echo "remote backup policy: ok\n";
