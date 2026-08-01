<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', str_replace( '\\', '/', dirname( __DIR__ ) ) . '/' );

	function __( $message ) { return $message; }
	function normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
	function ensure_directory( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}

	function agsb_build_test_zip( $path, array $manifest, array $members = array() ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create ZIP fixture.' );
		}
		$zip->addFromString( 'manifest.json', json_encode( $manifest, JSON_UNESCAPED_SLASHES ) );
		foreach ( $members as $name => $content ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
			} else {
				$zip->addFromString( $name, $content );
			}
		}
		$zip->close();
	}

	function agsb_remove_archive_fixture( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( new DirectoryIterator( $path ) as $item ) {
			if ( $item->isDot() ) {
				continue;
			}
			agsb_remove_archive_fixture( normalize_path( $item->getPathname() ) );
		}
		rmdir( $path );
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-archive-service.php';

	function expect_archive_preflight( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$fixture = str_replace( '\\', '/', sys_get_temp_dir() ) . '/agsb-archive-preflight-' . bin2hex( random_bytes( 6 ) );
	ensure_directory( $fixture );
	$archive = new Archive_Service( new Config(), new Logger() );

	$full_manifest = array(
		'snapshot_scope' => 'full',
		'database'       => array( 'filename' => 'database.sql' ),
	);
	$full_zip = $fixture . '/full.zip';

	$partial_path = 'wp-content/uploads/selected.txt';
	$partial_manifest = array(
		'snapshot_scope'  => 'partial',
		'partial_paths'   => array( $partial_path ),
		'partial_entries' => array(
			array(
				'path'    => $partial_path,
				'type'    => 'file',
				'exists'  => true,
				'archive' => 'files/' . $partial_path,
			),
		),
		'database'        => array( 'included' => false ),
	);
	$partial_zip = $fixture . '/partial.zip';

	try {
		\agsb_build_test_zip(
			$full_zip,
			$full_manifest,
			array(
				'database.sql'                           => 'CREATE TABLE test (id INT);',
				'files/wp-content/uploads/full-data.txt' => 'full payload',
			)
		);

		$full_inspection = $archive->inspect_package( $full_zip );
		expect_archive_preflight( is_array( $full_inspection ) && 'full' === $full_inspection['snapshot_scope'], 'A bounded full snapshot must pass ZIP inventory inspection.' );
		$full_target = $fixture . '/full-extract';
		$full_extract = $archive->extract_package( $full_zip, $full_target );
		expect_archive_preflight( true === $full_extract, 'A bounded full snapshot must retain extraction compatibility.' );
		expect_archive_preflight( 'full payload' === file_get_contents( $full_target . '/files/wp-content/uploads/full-data.txt' ), 'Full snapshot member must extract unchanged.' );

		$count_target = $fixture . '/count-limit-target';
		$count_limit = $archive->extract_package( $full_zip, $count_target, null, array( 'max_archive_entries' => 2 ) );
		expect_archive_preflight( is_wp_error( $count_limit ) && 'ag_sync_bridge_zip_entry_limit' === $count_limit->get_error_code(), 'Entry-count ceiling must be checked before extraction.' );
		expect_archive_preflight( ! file_exists( $count_target ), 'Rejected entry inventory must not create an extraction directory.' );

		$package_target = $fixture . '/package-limit-target';
		$package_limit = $archive->extract_package( $full_zip, $package_target, null, array( 'max_archive_bytes' => 1 ) );
		expect_archive_preflight( is_wp_error( $package_limit ) && 'ag_sync_bridge_zip_package_size' === $package_limit->get_error_code(), 'Compressed ZIP size ceiling must be enforced before extraction.' );
		expect_archive_preflight( ! file_exists( $package_target ), 'Rejected compressed size must not create an extraction directory.' );

		$entry_target = $fixture . '/entry-limit-target';
		$entry_limit = $archive->extract_package( $full_zip, $entry_target, null, array( 'max_archive_entry_bytes' => 16 ) );
		expect_archive_preflight( is_wp_error( $entry_limit ) && 'ag_sync_bridge_zip_entry_size' === $entry_limit->get_error_code(), 'Per-entry uncompressed size ceiling must be enforced.' );
		expect_archive_preflight( ! file_exists( $entry_target ), 'Rejected member size must not create an extraction directory.' );

		$expansion_target = $fixture . '/expansion-limit-target';
		$expansion_limit = $archive->extract_package( $full_zip, $expansion_target, null, array( 'max_archive_uncompressed_bytes' => 32 ) );
		expect_archive_preflight( is_wp_error( $expansion_limit ) && 'ag_sync_bridge_zip_expansion_limit' === $expansion_limit->get_error_code(), 'Total uncompressed size ceiling must be enforced.' );
		expect_archive_preflight( ! file_exists( $expansion_target ), 'Rejected expansion size must not create an extraction directory.' );

		\agsb_build_test_zip(
			$partial_zip,
			$partial_manifest,
			array( 'files/' . $partial_path => 'selected payload' )
		);
		$partial_target = $fixture . '/partial-extract';
		$partial_extract = $archive->extract_package(
			$partial_zip,
			$partial_target,
			null,
			array( 'expected_partial_paths' => array( $partial_path ) )
		);
		expect_archive_preflight( true === $partial_extract, 'Exact bounded partial inventory must extract.' );
		expect_archive_preflight( 'selected payload' === file_get_contents( $partial_target . '/files/' . $partial_path ), 'Exact partial member must extract unchanged.' );

		$extra_zip = $fixture . '/partial-extra.zip';
		\agsb_build_test_zip(
			$extra_zip,
			$partial_manifest,
			array(
				'files/' . $partial_path                => 'selected payload',
				'files/wp-content/uploads/extra.txt'   => 'out-of-contract payload',
			)
		);
		$extra_target = $fixture . '/partial-extra-target';
		$extra_result = $archive->extract_package(
			$extra_zip,
			$extra_target,
			null,
			array( 'expected_partial_paths' => array( $partial_path ) )
		);
		expect_archive_preflight( is_wp_error( $extra_result ) && 'ag_sync_bridge_zip_partial_inventory' === $extra_result->get_error_code(), 'Partial archive member outside the manifest must be rejected.' );
		expect_archive_preflight( ! file_exists( $extra_target ), 'Out-of-contract partial inventory must be rejected before extraction.' );

		$mismatch_target = $fixture . '/partial-mismatch-target';
		$mismatch = $archive->extract_package(
			$partial_zip,
			$mismatch_target,
			null,
			array( 'expected_partial_paths' => array( 'wp-content/uploads/different.txt' ) )
		);
		expect_archive_preflight( is_wp_error( $mismatch ) && 'ag_sync_bridge_zip_partial_paths_mismatch' === $mismatch->get_error_code(), 'Caller partial path expectation must be checked against the pre-extraction manifest.' );
		expect_archive_preflight( ! file_exists( $mismatch_target ), 'Expected-path mismatch must not create an extraction directory.' );

		$missing_zip = $fixture . '/partial-missing.zip';
		\agsb_build_test_zip( $missing_zip, $partial_manifest );
		$missing_result = $archive->inspect_package( $missing_zip, array( 'expected_partial_paths' => array( $partial_path ) ) );
		expect_archive_preflight( is_wp_error( $missing_result ) && 'ag_sync_bridge_zip_partial_inventory' === $missing_result->get_error_code(), 'Declared partial file missing from ZIP inventory must be rejected.' );

		$tombstone_path = 'wp-content/uploads/removed.txt';
		$tombstone_manifest = array(
			'snapshot_scope'  => 'partial',
			'partial_paths'   => array( $tombstone_path ),
			'partial_entries' => array(
				array(
					'path'    => $tombstone_path,
					'type'    => 'missing',
					'exists'  => false,
					'archive' => 'files/' . $tombstone_path,
				),
			),
			'database'        => array( 'included' => false ),
		);
		$tombstone_zip = $fixture . '/partial-tombstone.zip';
		\agsb_build_test_zip( $tombstone_zip, $tombstone_manifest );
		$tombstone_result = $archive->inspect_package( $tombstone_zip, array( 'expected_partial_paths' => array( $tombstone_path ) ) );
		expect_archive_preflight( is_array( $tombstone_result ) && 'partial' === $tombstone_result['snapshot_scope'], 'A partial tombstone with no payload member must remain valid.' );

		echo "archive preflight regression: ok\n";
	} finally {
		\agsb_remove_archive_fixture( $fixture );
	}
}
