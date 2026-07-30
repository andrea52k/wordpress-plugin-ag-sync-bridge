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
		public $dependencies_loaded = 0;

		protected function load_upgrader_dependencies() { $this->dependencies_loaded++; return true; }
		protected function plugin_updates() { return $this->plugins; }
		protected function theme_updates() { return $this->themes; }
		protected function upgrade_plugins( array $plugins ) { $this->calls[] = array( 'plugins', $plugins ); return $this->plugin_result; }
		protected function upgrade_themes( array $themes ) { $this->calls[] = array( 'themes', $themes ); return $this->theme_result; }
		protected function upgrade_translations( array $translations ) { $this->calls[] = array( 'translations', $translations ); return $this->translation_result; }
	}

	function expect( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}

	function make_service( &$logger = null ) {
		$GLOBALS['maintenance_calls'] = array();
		$GLOBALS['maintenance_translations'] = array();
		$logger = new Logger();
		return new Test_Maintenance_Service( $logger );
	}

	$service = make_service();
	$result = $service->prepare_for_push();
	expect( ! \is_wp_error( $result ), 'No-update maintenance should succeed.' );
	expect( 'full' === $result['scope'], 'Default maintenance must retain the full-push policy.' );
	expect( 0 === $result['plugins']['available'] && 0 === $result['themes']['available'], 'No-update counts should stay at zero.' );
	expect( array( 'plugins', 'themes', 'translations' ) === array_column( $service->calls, 0 ), 'All maintenance categories should be checked.' );
	expect( 1 === $service->dependencies_loaded, 'Full maintenance must still load WordPress updater dependencies.' );

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
	$service->plugins = array( 'click-to-chat-for-whatsapp/click-to-chat.php' => (object) array() );
	$service->plugin_result = new \WP_Error( 'click_to_chat_update_failed', 'Click to Chat update failed.' );
	$result = $service->prepare_for_push();
	expect( \is_wp_error( $result ) && 'click_to_chat_update_failed' === $result->get_error_code(), 'A failed Click to Chat update must still stop a full push.' );
	expect( array( 'plugins' ) === array_column( $service->calls, 0 ), 'A failed plugin update must stop subsequent categories.' );

	$service = make_service( $logger );
	$service->plugins = array( 'click-to-chat-for-whatsapp/click-to-chat.php' => (object) array( 'new_version' => '99.0.0' ) );
	$service->plugin_result = new \WP_Error( 'click_to_chat_update_failed', 'Click to Chat update failed.' );
	$result = $service->prepare_for_push(
		array(
			'scope' => 'partial',
			'paths' => array( '.htaccess' ),
		)
	);
	expect( ! \is_wp_error( $result ), 'An unrelated Click to Chat update failure must not block an explicit .htaccess push.' );
	expect( 'partial' === $result['scope'], 'Partial maintenance must record its scope.' );
	expect( array( '.htaccess' ) === $result['paths'], 'Partial maintenance must record the validated deployment paths.' );
	expect( 'skipped' === $result['automatic_updates']['status'], 'Partial maintenance must clearly record the updater skip.' );
	expect( 'explicit_partial_push_out_of_scope' === $result['automatic_updates']['reason'], 'Partial maintenance must record why package updates were skipped.' );
	expect( empty( $service->calls ), 'Partial maintenance must not invoke plugin, theme or translation upgraders.' );
	expect( empty( $GLOBALS['maintenance_calls'] ), 'Partial maintenance must not refresh update metadata or clean package caches.' );
	expect( 0 === $service->dependencies_loaded, 'Partial maintenance must not load WordPress updater dependencies.' );
	expect( 1 === count( $logger->entries ), 'Partial maintenance must emit one explicit policy log.' );
	expect( 'Local pre-push package updates skipped for explicit partial push.' === $logger->entries[0][0], 'Partial maintenance log must clearly identify the skip.' );

	$service = make_service();
	$result = $service->prepare_for_push( array( 'scope' => 'partial', 'paths' => array() ) );
	expect( \is_wp_error( $result ), 'A malformed partial maintenance request without validated paths must fail closed.' );
	expect( 'ag_sync_bridge_partial_maintenance_paths_missing' === $result->get_error_code(), 'Missing partial paths must have an explicit error code.' );
	expect( empty( $service->calls ) && 0 === $service->dependencies_loaded, 'Malformed partial maintenance must not start package update work.' );

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-sync-service.php' );
	expect( false !== strpos( $sync_source, "'scope' => \$is_partial_push ? 'partial' : 'full'" ), 'Sync orchestration must pass the resolved deployment scope to maintenance.' );
	expect( false !== strpos( $sync_source, "'paths' => \$partial_paths" ), 'Sync orchestration must pass normalized partial paths to maintenance.' );

	echo "local maintenance service: ok\n";
}
