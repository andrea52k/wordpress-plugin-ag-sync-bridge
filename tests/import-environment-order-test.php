<?php
declare( strict_types=1 );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
if ( false === $source ) {
	throw new RuntimeException( 'Unable to read import service source.' );
}

$database_import = strpos( $source, '$this->database->import_from_file(' );
$early_restore   = strpos( $source, '$this->database->restore_environment_state( $current_state );', $database_import );
$url_replace     = strpos( $source, '$this->database->replace_urls(', $database_import );
$file_import     = strpos( $source, '$this->import_files(', $database_import );

if ( false === $database_import || false === $early_restore || false === $url_replace || false === $file_import ) {
	throw new RuntimeException( 'Expected import safety checkpoints are missing.' );
}

if ( ! ( $database_import < $early_restore && $early_restore < $url_replace && $url_replace < $file_import ) ) {
	throw new RuntimeException( 'Target environment must be restored before URL replacement and file import.' );
}

echo "import environment order: ok\n";
