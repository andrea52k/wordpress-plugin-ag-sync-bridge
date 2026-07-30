<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'AG_SYNC_BRIDGE_VERSION', '0.1.38' );
	define( 'AG_SYNC_BRIDGE_PLUGIN_FILE', __FILE__ );

	function __( $message ) { return $message; }
	class WP_Error {
		private $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_parse_url( $url ) { return parse_url( $url ); }
}

namespace AGSyncBridge {
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }

	class Config {
		public function get_role() { return 'remote'; }
	}
	class Logger {}
	class Remote_Operation_Runtime {
		public $status = 'complete';
		public function inspect() { return array( 'status' => $this->status ); }
	}
	class GitHub_Updater {
		const OWNER = 'andrea52k';
		const REPOSITORY = 'wordpress-plugin-ag-sync-bridge';
		const ASSET_NAME = 'ag-sync-bridge.zip';
	}

	require_once dirname( __DIR__ ) . '/includes/class-remote-update-service.php';

	class Test_Remote_Update_Service extends Remote_Update_Service {
		public function validate_test_package( $path, $version ) {
			return $this->validate_package( $path, $version );
		}
		public function official_url( $url, $version ) {
			return $this->is_official_asset_url( $url, $version );
		}
	}
}

namespace {
	function expect_update( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		throw new RuntimeException( 'ZipArchive is required for remote update safety tests.' );
	}

	$runtime = new \AGSyncBridge\Remote_Operation_Runtime();
	$service = new \AGSyncBridge\Test_Remote_Update_Service(
		new \AGSyncBridge\Config(),
		new \AGSyncBridge\Logger(),
		$runtime
	);
	$sha = str_repeat( 'a', 64 );
	$result = $service->update_from_github_release( '0.1.39', $sha, '0.1.38', 'WRONG' );
	expect_update( is_wp_error( $result ) && 'ag_sync_bridge_remote_update_confirmation' === $result->get_error_code(), 'exact confirmation required' );
	$result = $service->update_from_github_release( '0.1.39', 'bad', '0.1.38', 'UPDATE AG SYNC' );
	expect_update( is_wp_error( $result ) && 'ag_sync_bridge_remote_update_invalid_release' === $result->get_error_code(), 'valid checksum required' );
	$result = $service->update_from_github_release( '0.1.39', $sha, '0.1.37', 'UPDATE AG SYNC' );
	expect_update( is_wp_error( $result ) && 'ag_sync_bridge_remote_update_version_race' === $result->get_error_code(), 'current version race blocked' );
	$result = $service->update_from_github_release( '0.1.37', $sha, '0.1.38', 'UPDATE AG SYNC' );
	expect_update( is_wp_error( $result ) && 'ag_sync_bridge_remote_update_not_newer' === $result->get_error_code(), 'downgrade blocked' );
	$runtime->status = 'rollback_required';
	$result = $service->update_from_github_release( '0.1.39', $sha, '0.1.38', 'UPDATE AG SYNC' );
	expect_update( is_wp_error( $result ) && 'ag_sync_bridge_remote_update_operation_active' === $result->get_error_code(), 'rollback-required state blocks update' );
	$runtime->status = 'complete';
	expect_update(
		$service->official_url( 'https://github.com/andrea52k/wordpress-plugin-ag-sync-bridge/releases/download/v0.1.39/ag-sync-bridge.zip', '0.1.39' ),
		'official asset URL accepted'
	);
	expect_update(
		! $service->official_url( 'https://example.com/ag-sync-bridge.zip', '0.1.39' ),
		'arbitrary asset URL rejected'
	);
	$update_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-remote-update-service.php' );
	expect_update( false !== strpos( $update_source, '.lsphp_restart.txt' ) && false !== strpos( $update_source, 'opcache_reset' ), 'runtime recycle is required after update' );
	$valid_path = sys_get_temp_dir() . '/agsync-update-valid-' . bin2hex( random_bytes( 4 ) ) . '.zip';
	$zip = new ZipArchive();
	$zip->open( $valid_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'ag-sync-bridge/ag-sync-bridge.php', "<?php\n/**\n * Plugin Name: AG Sync Bridge\n * Version: 0.1.38\n */\n" );
	$zip->addFromString( 'ag-sync-bridge/includes/class-example.php', "<?php\n" );
	$zip->close();
	expect_update( true === $service->validate_test_package( $valid_path, '0.1.38' ), 'valid canonical package accepted' );
	expect_update( is_wp_error( $service->validate_test_package( $valid_path, '0.1.39' ) ), 'version mismatch rejected' );

	$unsafe_path = sys_get_temp_dir() . '/agsync-update-unsafe-' . bin2hex( random_bytes( 4 ) ) . '.zip';
	$zip = new ZipArchive();
	$zip->open( $unsafe_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'ag-sync-bridge/ag-sync-bridge.php', "<?php\n/** Version: 0.1.38 */\n" );
	$zip->addFromString( 'other-plugin/payload.php', "<?php\n" );
	$zip->close();
	$unsafe = $service->validate_test_package( $unsafe_path, '0.1.38' );
	expect_update( is_wp_error( $unsafe ) && 'ag_sync_bridge_remote_update_zip_layout' === $unsafe->get_error_code(), 'foreign package path rejected' );

	@unlink( $valid_path );
	@unlink( $unsafe_path );
	echo "remote update safety: ok\n";
}
