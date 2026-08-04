<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $message ) { return $message; }
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = null ) {}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_active_prefix_export( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	class Active_Prefix_Export_Wpdb {
		public $prefix = 'wp_';
		public $options = 'wp_options';

		public function get_col( $query ) {
			return array(
				'wpim_options',
				'wp_posts',
				'wp_options',
				'foreign_table',
				'wp_mpg_runtime_dataset_rows',
			);
		}
	}

	global $wpdb;
	$wpdb = new Active_Prefix_Export_Wpdb();

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$method = $reflection->getMethod( 'get_export_tables' );
	$method->setAccessible( true );
	$tables = $method->invoke( $service );

	expect_active_prefix_export(
		array(
			'wp_mpg_runtime_dataset_rows',
			'wp_options',
			'wp_posts',
		) === $tables,
		'Database export must include only the active WordPress prefix and exclude stale live-prefix tables.'
	);

	echo "active prefix export regression: ok\n";
}
