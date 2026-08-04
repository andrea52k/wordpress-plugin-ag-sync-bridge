<?php
declare( strict_types=1 );

$root     = dirname( __DIR__ );
$database = file_get_contents( $root . '/includes/class-database-service.php' );
$import   = file_get_contents( $root . '/includes/class-import-service.php' );

if ( false === $database || false === $import ) {
	throw new RuntimeException( 'Unable to read full import sources.' );
}

function expect_full_import_liveness( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_full_import_liveness(
	false !== strpos( $database, 'public function replace_urls( array $replacements, $table_prefix = \'\', array $options = array() )' ),
	'URL replacement must accept liveness callbacks.'
);
expect_full_import_liveness(
	false !== strpos( $database, "\$this->replace_plain_text_columns( \$table, \$fast_columns, \$replacements, \$progress_callback, \$cancellation_check )" ),
	'Fast URL replacement must receive liveness callbacks.'
);
expect_full_import_liveness(
	false !== strpos( $database, "'fast-batch-start'" ) && false !== strpos( $database, "'fast-batch-complete'" ),
	'Fast URL replacement must emit a heartbeat before and after every micro-batch.'
);
expect_full_import_liveness(
	false !== strpos( $database, 'build_primary_key_rows_sql' ) && false !== strpos( $database, "' WHERE ' . \$key_where" ),
	'Fast URL replacement must update only the selected primary-key batch, never the full table at once.'
);
expect_full_import_liveness(
	substr_count( $database, 'report_url_replace_progress' ) >= 4,
	'URL replacement must report table and batch progress.'
);
expect_full_import_liveness(
	substr_count( $database, 'check_url_replace_cancellation' ) >= 3,
	'URL replacement must check cancellation before each table and batch.'
);

foreach ( array( 'before_environment_restore', 'after_environment_restore', 'before_prefix_remap', 'after_prefix_remap', 'before_url_replace', 'url-replace-' ) as $checkpoint ) {
	expect_full_import_liveness( false !== strpos( $import, $checkpoint ), 'Missing full import liveness checkpoint: ' . $checkpoint );
}

$after_database = strpos( $import, "'after_database_import'" );
$environment    = strpos( $import, "'before_environment_restore'" );
$prefix         = strpos( $import, "'before_prefix_remap'" );
$url_replace    = strpos( $import, "'before_url_replace'" );
expect_full_import_liveness(
	false !== $after_database && false !== $environment && false !== $prefix && false !== $url_replace && $after_database < $environment && $environment < $prefix && $prefix < $url_replace,
	'Full import checkpoints must cover the complete post-database path in order.'
);

echo "full import liveness regression: ok\n";
