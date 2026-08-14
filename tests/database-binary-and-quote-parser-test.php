<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $message ) { return $message; }
	class WP_Error {}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';

	function expect_database_safety( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	class Fake_Mysqli_Escaper {
		public function real_escape_string( $value ) {
			return str_replace( "'", "\\'", (string) $value );
		}
	}

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$format = $reflection->getMethod( 'format_php_export_value' );
	$format->setAccessible( true );
	$escaped = $reflection->getMethod( 'is_sql_quote_escaped' );
	$escaped->setAccessible( true );
	$split_rows = $reflection->getMethod( 'split_sql_value_rows' );
	$split_rows->setAccessible( true );

	$binary_field = (object) array( 'type' => 252, 'charsetnr' => 63 );
	$text_field = (object) array( 'type' => 253, 'charsetnr' => 45 );
	$binary_value = "\x00\xFFquote'\\tail";
	expect_database_safety( '0x00FF71756F7465275C7461696C' === $format->invoke( $service, new Fake_Mysqli_Escaper(), $binary_field, $binary_value ), 'PHP fallback must emit byte-exact hexadecimal BLOB literals.' );
	expect_database_safety( "X''" === $format->invoke( $service, new Fake_Mysqli_Escaper(), $binary_field, '' ), 'Empty BLOB/TEXT fallback values must use a valid empty hexadecimal literal.' );
	expect_database_safety( "'text\\'value'" === $format->invoke( $service, new Fake_Mysqli_Escaper(), $text_field, "text'value" ), 'Text fields must retain escaped SQL string output.' );
	expect_database_safety( 'NULL' === $format->invoke( $service, new Fake_Mysqli_Escaper(), $binary_field, null ), 'NULL binary values must remain NULL.' );

	$one_slash = "'x\\'";
	$two_slashes = "'x\\\\'";
	$three_slashes = "'x\\\\\\'";
	expect_database_safety( true === $escaped->invoke( $service, $one_slash, strlen( $one_slash ) - 1 ), 'Odd one-backslash quote must remain escaped.' );
	expect_database_safety( false === $escaped->invoke( $service, $two_slashes, strlen( $two_slashes ) - 1 ), 'Even two-backslash quote must close the SQL string.' );
	expect_database_safety( true === $escaped->invoke( $service, $three_slashes, strlen( $three_slashes ) - 1 ), 'Odd three-backslash quote must remain escaped.' );

	$rows = $split_rows->invoke( $service, "('ends\\\\',1),('next',2)" );
	expect_database_safety( 2 === count( $rows ), 'Row parser must split after a quote preceded by an even backslash run.' );
	$rows = $split_rows->invoke( $service, "('has\\'quote',1),('next',2)" );
	expect_database_safety( 2 === count( $rows ), 'Row parser must keep a quote preceded by an odd backslash run inside its value.' );

	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-database-service.php' );
	expect_database_safety( false !== strpos( $source, "'--hex-blob'" ), 'mysqldump command must request hexadecimal binary output.' );

	echo "database binary and quote parser: ok\n";
}
