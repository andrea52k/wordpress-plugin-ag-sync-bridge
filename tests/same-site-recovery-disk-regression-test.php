<?php
declare( strict_types=1 );

$database = file_get_contents( dirname( __DIR__ ) . '/includes/class-database-service.php' );
$import   = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );

function expect_same_site_recovery( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_same_site_recovery( false !== strpos( $import, "'' !== (string) \$expected_sha256" ), 'Direct streaming must require an externally checksum-bound full package.' );
expect_same_site_recovery( false !== strpos( $import, 'is_readable_database_stream' ), 'Direct streaming must prove the exact database entry is readable.' );
expect_same_site_recovery( false !== strpos( $database, "! empty( \$args['trusted_verified_database_stream'] )" ), 'Database import must require the verified stream flag.' );
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
expect_same_site_recovery( 2 === substr_count( $database, "0 === strpos( (string) \$file_path, 'zip://' )" ), 'ZIP wrapper handling must remain confined to import normalization and bounded stream resume.' );
expect_same_site_recovery( false !== strpos( $import, "\$trusted_verified_database_stream = ! empty( \$prepared['stream_database_sql'] )" ), 'The package-level verified stream decision must be carried unchanged into database import.' );
expect_same_site_recovery( false !== strpos( $database, "'trusted_verified_database_stream' => \$trusted_verified_stream" ), 'Rejected streams must expose non-sensitive contract diagnostics.' );
expect_same_site_recovery( false !== strpos( $import, "'expected_size_bytes' => \$trusted_verified_database_stream" ), 'The signed database size must be passed only for the verified ZIP stream.' );
expect_same_site_recovery( false !== strpos( $database, 'open_import_stream_at_offset' ), 'The PHP importer must support bounded byte-exact stream resume.' );
expect_same_site_recovery( false !== strpos( $database, 'remap_import_table_prefix' ), 'Cross-site streaming must remap table identifiers statement by statement.' );

echo "same-site recovery disk regression: ok\n";
