<?php
declare( strict_types=1 );

$database = file_get_contents( dirname( __DIR__ ) . '/includes/class-database-service.php' );
$import   = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );

function expect_same_site_recovery( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_same_site_recovery( false !== strpos( $import, "'php' === (string) array_get( \$manifest, 'database_method', '' )" ), 'Direct recovery must require a PHP-exported dump.' );
expect_same_site_recovery( false !== strpos( $import, "hash_equals( \$source_prefix, \$target_prefix )" ), 'Direct recovery must require identical table prefixes.' );
expect_same_site_recovery( substr_count( $import, "untrailingslashit( (string) array_get( \$manifest" ) >= 2, 'Direct recovery must bind both site and home URLs.' );
expect_same_site_recovery( false !== strpos( $database, "! empty( \$args['trusted_same_site_php_restore'] )" ), 'Database import must require the trusted same-site flag.' );
expect_same_site_recovery( false !== strpos( $database, "'path'                    => \$file_path" ), 'Trusted recovery must import the verified source SQL directly.' );
expect_same_site_recovery( false !== strpos( $database, "'cleanup_path'            => ''" ), 'Trusted recovery must never delete the source SQL as a temporary rewrite.' );
expect_same_site_recovery( false !== strpos( $database, ': $this->prepare_sql_for_import(' ), 'All other imports must retain SQL preparation.' );

echo "same-site recovery disk regression: ok\n";
