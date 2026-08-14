<?php
declare( strict_types=1 );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
if ( false === $source ) {
	throw new RuntimeException( 'Unable to read import service source.' );
}

function expect_maintenance_heartbeat( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_maintenance_heartbeat(
	substr_count( $source, '$this->refresh_maintenance_mode();' ) >= 4,
	'Every long mutation phase must refresh WordPress maintenance mode.'
);
expect_maintenance_heartbeat(
	false !== strpos( $source, "'progress_callback' => function ( \$stage, \$progress, array \$details = array() ) use ( \$args )" ),
	'Database import progress must refresh maintenance mode before forwarding its heartbeat.'
);
expect_maintenance_heartbeat(
	false !== strpos( $source, "'progress_callback' => function ( array \$details ) use ( \$args )" ),
	'URL replacement progress must refresh maintenance mode.'
);
expect_maintenance_heartbeat(
	false !== strpos( $source, 'return function ( array $details ) use ( $callback )' ),
	'File import progress must refresh maintenance mode even without an external progress observer.'
);

echo "maintenance heartbeat regression: ok\n";
