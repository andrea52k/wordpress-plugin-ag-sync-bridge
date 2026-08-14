<?php
declare( strict_types=1 );

$database = file_get_contents( dirname( __DIR__ ) . '/includes/class-database-service.php' );
$import   = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );

function expect_same_site_recovery( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_same_site_recovery( false !== strpos( $import, "! empty( \$args['recovery_import'] )" ), 'Direct streaming must require an explicit recovery import.' );
expect_same_site_recovery( false !== strpos( $import, 'is_bridge_php_database_stream' ), 'Direct recovery must verify the AG Sync PHP export marker.' );
expect_same_site_recovery( false !== strpos( $import, "hash_equals( \$source_prefix, \$this->database->get_table_prefix() )" ), 'Direct recovery must require identical table prefixes.' );
expect_same_site_recovery( substr_count( $import, "untrailingslashit( (string) array_get( \$pre_manifest" ) >= 2, 'Direct recovery must bind both site and home URLs.' );
expect_same_site_recovery( false !== strpos( $database, "! empty( \$args['trusted_same_site_php_restore'] )" ), 'Database import must require the trusted same-site flag.' );
expect_same_site_recovery( false !== strpos( $database, "'path'                    => \$file_path" ), 'Trusted recovery must import the verified source SQL directly.' );
expect_same_site_recovery( false !== strpos( $database, "'cleanup_path'            => ''" ), 'Trusted recovery must never delete the source SQL as a temporary rewrite.' );
expect_same_site_recovery( false !== strpos( $database, ': $this->prepare_sql_for_import(' ), 'All other imports must retain SQL preparation.' );
expect_same_site_recovery( false !== strpos( $import, "'skip_database_sql' => \$stream_database_sql" ), 'Recovery extraction must skip the multi-gigabyte database entry.' );
expect_same_site_recovery( false !== strpos( $import, "'#database.sql'" ), 'Recovery must bind the ZIP stream to the exact database.sql entry.' );
$import_method_start = strpos( $database, 'public function import_from_file(' );
$import_method_end   = strpos( $database, 'public function get_table_prefix(', $import_method_start );
$import_method       = false !== $import_method_start ? substr( $database, $import_method_start, false !== $import_method_end ? $import_method_end - $import_method_start : 5000 ) : '';
expect_same_site_recovery( false !== strpos( $import_method, "0 === strpos( (string) \$file_path, 'zip://' )" ), 'Database import must identify the ZIP stream before filesystem normalization.' );
expect_same_site_recovery( false !== strpos( $import_method, "str_replace( '\\\\', '/', (string) \$file_path ) : normalize_path" ), 'Database import must preserve the zip:// wrapper while normalizing ordinary paths normally.' );
expect_same_site_recovery( 1 === substr_count( $database, "0 === strpos( (string) \$file_path, 'zip://' )" ), 'ZIP wrapper handling must not leak into unrelated database methods.' );
expect_same_site_recovery( false !== strpos( $import, "\$trusted_same_site_php_restore = ! empty( \$prepared['stream_database_sql'] )" ), 'The strict package-level decision must be carried unchanged into database import.' );
expect_same_site_recovery( false !== strpos( $database, "'trusted_same_site_php_restore' => ! empty( \$args['trusted_same_site_php_restore'] )" ), 'Rejected streams must expose non-sensitive contract diagnostics.' );

echo "same-site recovery disk regression: ok\n";
