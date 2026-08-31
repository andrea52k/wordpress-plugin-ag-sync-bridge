<?php
declare( strict_types=1 );

namespace {
	$root = sys_get_temp_dir() . '/agsync-full-htaccess-' . bin2hex( random_bytes( 4 ) ) . '/';
	mkdir( $root . 'wp-content/uploads', 0777, true );
	mkdir( $root . 'wp-content/mpg-uploads', 0777, true );
	mkdir( $root . 'wp-content/plugins', 0777, true );
	mkdir( $root . 'wp-content/themes', 0777, true );
	define( 'ABSPATH', str_replace( '\\', '/', $root ) );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );

	function __( $message ) { return $message; }
	function normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function array_get( $array, $key, $default = null ) { return isset( $array[ $key ] ) ? $array[ $key ] : $default; }
	function ensure_directory( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }

	class WP_Error {}
}

namespace AGSyncBridge {
	class Config {
		private $include_htaccess;
		public function __construct( $include_htaccess ) { $this->include_htaccess = (bool) $include_htaccess; }
		public function get( $key, $default = null ) { return 'include_htaccess' === $key ? $this->include_htaccess : $default; }
		public function get_exclude_patterns() { return array(); }
	}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-file-system-service.php';

	function expect_full_htaccess( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	file_put_contents( ABSPATH . 'wp-config.php', "<?php\n" );
	file_put_contents( ABSPATH . '.htaccess', "RewriteBase /local-subdir/\n" );
	file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\n" );

	$disabled = new File_System_Service( new Config( false ), new Logger() );
	$disabled_components = array_column( $disabled->get_export_entries(), 'component' );
	expect_full_htaccess( ! in_array( '.htaccess', $disabled_components, true ), 'A full export must exclude .htaccess when the option is disabled.' );
	expect_full_htaccess( in_array( 'robots.txt', $disabled_components, true ), 'Other supported root text files must remain in full exports.' );

	$enabled = new File_System_Service( new Config( true ), new Logger() );
	$enabled_components = array_column( $enabled->get_export_entries(), 'component' );
	expect_full_htaccess( 1 === count( array_keys( $enabled_components, '.htaccess', true ) ), 'An enabled full export must include .htaccess exactly once.' );

	$source = ABSPATH . 'source-root';
	$target = ABSPATH . 'target-root';
	mkdir( $source, 0777, true );
	mkdir( $target, 0777, true );
	file_put_contents( $source . '/.htaccess', "RewriteBase /source/\n" );
	file_put_contents( $source . '/robots.txt', "User-agent: source\n" );
	file_put_contents( $target . '/.htaccess', "RewriteBase /target/\n" );
	file_put_contents( $target . '/robots.txt', "User-agent: target\n" );

	$result = $disabled->sync_root_text_files( $source, $target );
	expect_full_htaccess( true === $result, 'Generic root text sync failed.' );
	expect_full_htaccess( "RewriteBase /target/\n" === file_get_contents( $target . '/.htaccess' ), 'Generic root text sync must preserve target .htaccess.' );
	expect_full_htaccess( "User-agent: source\n" === file_get_contents( $target . '/robots.txt' ), 'Generic root text files must still synchronize.' );

	echo "full-htaccess-option-test: ok\n";
}
