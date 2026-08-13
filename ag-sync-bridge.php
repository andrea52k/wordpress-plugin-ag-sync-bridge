<?php
/**
 * Plugin Name: AG Sync Bridge
 * Plugin URI: https://github.com/andrea52k/wordpress-plugin-ag-sync-bridge
 * Description: Full snapshot bridge to sync a local WordPress installation with a live WordPress site.
 * Version: 0.1.61
 * Author: Codex
 * License: GPL-2.0-or-later
 * Text Domain: ag-sync-bridge
 * Update URI: https://github.com/andrea52k/wordpress-plugin-ag-sync-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AG_SYNC_BRIDGE_VERSION', '0.1.61' );
define( 'AG_SYNC_BRIDGE_PLUGIN_FILE', __FILE__ );
define( 'AG_SYNC_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AG_SYNC_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'AGSyncBridge\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'AGSyncBridge\\' ) );
		$relative = strtolower( str_replace( array( '\\', '_' ), '-', $relative ) );
		$path     = AG_SYNC_BRIDGE_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

require_once AG_SYNC_BRIDGE_PLUGIN_DIR . 'includes/helpers.php';

\AGSyncBridge\Plugin::instance();

register_activation_hook( __FILE__, array( 'AGSyncBridge\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AGSyncBridge\\Plugin', 'deactivate' ) );
