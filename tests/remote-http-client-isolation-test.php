<?php

$root   = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/includes/class-plugin.php' );
$sync   = file_get_contents( $root . '/includes/class-sync-service.php' );
$stub   = file_get_contents( $root . '/includes/class-remote-http-client.php' );

function expect_remote_http_isolation( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

expect_remote_http_isolation(
	false !== strpos( $plugin, "'remote' === \$this->config->get_role()" )
	&& false !== strpos( $plugin, 'new Remote_Http_Client' )
	&& false !== strpos( $plugin, 'new Http_Client' ),
	'Plugin bootstrap must select the fail-closed adapter only for remote peers.'
);
expect_remote_http_isolation(
	false === strpos( $sync, 'Http_Client $http_client' ),
	'Sync service must accept the remote outbound adapter without autoloading Http_Client.'
);
expect_remote_http_isolation(
	false !== strpos( $stub, 'ag_sync_bridge_remote_outbound_forbidden' )
	&& false !== strpos( $stub, 'public function __call' ),
	'Remote adapter must fail closed for every outbound method.'
);

echo "remote http client isolation: ok\n";
