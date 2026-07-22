<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'AG_SYNC_BRIDGE_PLUGIN_FILE', 'ag-sync-bridge/ag-sync-bridge.php' );

	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $value ) { return $value; }
	function plugin_basename( $plugin ) { return $plugin; }
	function wp_update_plugins() { $GLOBALS['maintenance_calls'][] = 'check_plugins'; }
	function wp_update_themes() { $GLOBALS['maintenance_calls'][] = 'check_themes'; }
	function wp_update_languages() { $GLOBALS['maintenance_calls'][] = 'check_translations'; }
	function wp_get_translation_updates() { return $GLOBALS['maintenance_translations']; }
	function wp_clean_plugins_cache() { $GLOBALS['maintenance_calls'][] = 'clean_plugins'; }
	function wp_clean_themes_cache() { $GLOBALS['maintenance_calls'][] = 'clean_themes'; }
}

namespace AGSyncBridge {
	class Logger {
		public $entries = array();
		public function info( $message, $context = array() ) { $this->entries[] = array( $message, $context ); }
	}

	require_once dirname( __DIR__ ) . '/includes/class-local-maintenance-service.php';

	class Test_Maintenance_Service extends Local_Maintenance_Service {
		public $plugins = array();
		public $themes = array();
		public $plugin_result = array();
		public $theme_result = array();
		public $translation_result = array();
		public $calls = array();

		protected function load_upgrader_dependencies() { return true; }
		protected function plugin_updates() { return $this->plugins; }
		protected function theme_updates() { return $this->themes; }
		protected function upgrade_plugins( array $plugins ) { $this->calls[] = array( 'plugins', $plugins ); return $this->plugin_result; }
		protected function upgrade_themes( array $themes ) { $this->calls[] = array( 'themes', $themes ); return $this->theme_result; }
		protected function upgrade_translations( array $translations ) { $this->calls[] = array( 'translations', $translations ); return $this->translation_result; }
	}

	function expect( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}

	function make_service() {
		$GLOBALS['maintenance_calls'] = array();
		$GLOBALS['maintenance_translations'] = array();
		return new Test_Maintenance_Service( new Logger() );
	}

	$service = make_service();
	$result = $service->prepare_for_push();
	expect( ! \is_wp_error( $result ), 'No-update maintenance should succeed.' );
	expect( 0 === $result['plugins']['available'] && 0 === $result['themes']['available'], 'No-update counts should stay at zero.' );
	expect( array( 'plugins', 'themes', 'translations' ) === array_column( $service->calls, 0 ), 'All maintenance categories should be checked.' );

	$service = make_service();
	$service->plugins = array( 'ag-sync-bridge/ag-sync-bridge.php' => (object) array( 'new_version' => '9.9.9' ) );
	$result = $service->prepare_for_push();
	expect( \is_wp_error( $result ), 'An AG Sync self-update must block the push.' );
	expect( 'ag_sync_bridge_self_update_required' === $result->get_error_code(), 'The self-update blocker must be explicit.' );
	expect( empty( $service->calls ), 'No package update may start when AG Sync itself needs an update.' );

	$service = make_service();
	$service->plugins = array( 'vendor/example.php' => (object) array( 'new_version' => '1.2.0' ) );
	$service->themes = array( 'example-theme' => array( 'new_version' => '2.0.0' ) );
	$GLOBALS['maintenance_translations'] = array( (object) array( 'type' => 'plugin', 'slug' => 'example' ) );
	$service->plugin_result = array( 'vendor/example.php' );
	$service->theme_result = array( 'example-theme' );
	$service->translation_result = array( 'plugin-example-it_IT' );
	$result = $service->prepare_for_push();
	expect( ! \is_wp_error( $result ), 'Successful package updates must allow the push.' );
	expect( array( 'vendor/example.php' ) === $result['plugins']['updated'], 'Plugin update result should be recorded.' );
	expect( array( 'example-theme' ) === $result['themes']['updated'], 'Theme update result should be recorded.' );
	expect( array( 'plugin-example-it_IT' ) === $result['translations']['updated'], 'Translation update result should be recorded.' );

	$service = make_service();
	$service->plugins = array( 'vendor/example.php' => (object) array() );
	$service->plugin_result = new \WP_Error( 'failed', 'Plugin update failed.' );
	$result = $service->prepare_for_push();
	expect( \is_wp_error( $result ) && 'failed' === $result->get_error_code(), 'A failed local update must stop the push.' );
	expect( array( 'plugins' ) === array_column( $service->calls, 0 ), 'A failed plugin update must stop subsequent categories.' );

	echo "local maintenance service: ok\n";
}
