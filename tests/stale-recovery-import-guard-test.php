<?php
declare( strict_types=1 );

$root = dirname( __DIR__ );
$cli = file_get_contents( $root . '/includes/class-cli.php' );
$sync = file_get_contents( $root . '/includes/class-sync-service.php' );
$http = file_get_contents( $root . '/includes/class-http-client.php' );
$rest = file_get_contents( $root . '/includes/class-rest-controller.php' );
$runtime = file_get_contents( $root . '/includes/class-remote-operation-runtime.php' );
$file_system = file_get_contents( $root . '/includes/class-file-system-service.php' );

function expect_recovery_guard( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_recovery_guard( false !== strpos( $cli, "'recover_stale_remote_import' => ! empty( \$assoc_args['recover-stale-remote-import'] )" ), 'CLI must pass the explicit recovery flag into the sync service.' );
expect_recovery_guard( false !== strpos( $sync, "\$recover_stale_remote_import = ! empty( \$args['recover_stale_remote_import'] );" ), 'Sync service must consume the CLI recovery flag.' );
expect_recovery_guard( false !== strpos( $sync, "! \$use_existing_snapshot || \$is_partial_push || \$allow_partial_snapshot" ), 'Recovery must require an existing complete non-partial snapshot.' );
expect_recovery_guard( false !== strpos( $sync, "'remote' !== (string) array_get( array_get( \$local_snapshot, 'manifest', array() ), 'source_role', '' )" ), 'Local recovery must reject snapshots that are not marked as live-origin.' );
expect_recovery_guard( false !== strpos( $http, "'recovery_import'        => \$recovery_import ? 1 : 0" ), 'The signed REST body must carry the recovery flag.' );
expect_recovery_guard( false !== strpos( $rest, "if ( \$recovery_import && ! \$async )" ), 'Synchronous recovery imports must be rejected.' );
expect_recovery_guard( false !== strpos( $rest, "if ( \$recovery_import ) {\n\t\t\t\treturn new WP_Error( 'ag_sync_bridge_recovery_import_partial_forbidden'" ), 'Partial recovery imports must be rejected.' );
expect_recovery_guard( false !== strpos( $rest, "'import' !== (string) array_get( \$current, 'kind', '' )" ), 'Recovery must not supersede a quarantined operation of another kind.' );
expect_recovery_guard( false !== strpos( $rest, "'remote' !== (string) array_get( \$manifest, 'source_role', '' )" ) && false !== strpos( $rest, "\$source_site === untrailingslashit( site_url() )" ), 'Recovery must bind manifest role and URLs to this live peer.' );
expect_recovery_guard( false !== strpos( $runtime, "'import' === \$kind && \$stale_quarantine" ), 'Runtime may supersede only stale quarantined imports.' );
expect_recovery_guard( false !== strpos( $runtime, "\$operation['recovery_override']" ), 'Runtime must record the exceptional recovery reservation.' );
expect_recovery_guard( false !== strpos( $file_system, 'public function read_package_manifest( $package_path )' ), 'REST recovery validation must be allowed to read the package manifest.' );

echo "stale recovery import guard: ok\n";
