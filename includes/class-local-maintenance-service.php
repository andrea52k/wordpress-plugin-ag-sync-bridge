<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the local WordPress installation current immediately before a push.
 *
 * Updates are intentionally performed only on the local source site. The
 * remote target receives the resulting, validated snapshot through the normal
 * AG Sync flow; it never runs package updates in the middle of an import.
 */
class Local_Maintenance_Service {
	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Check for and install WordPress.org plugin, theme and language-pack
	 * updates. Any failed check or update blocks the push before a remote call,
	 * backup, snapshot or upload can start.
	 *
	 * AG Sync Bridge itself is deliberately not self-updated from its own push
	 * request: replacing the running plugin is unsafe. Its update must be
	 * installed normally, then the push can be retried.
	 *
	 * @return array|WP_Error
	 */
	public function prepare_for_push() {
		$dependencies = $this->load_upgrader_dependencies();
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}

		wp_update_plugins();
		wp_update_themes();
		if ( function_exists( 'wp_update_languages' ) ) {
			wp_update_languages();
		}

		$plugin_updates      = $this->plugin_updates();
		$theme_updates       = $this->theme_updates();
		$translation_updates = function_exists( 'wp_get_translation_updates' ) ? wp_get_translation_updates() : array();
		$translation_updates = is_array( $translation_updates ) ? $translation_updates : array();

		$self_update = plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE );
		if ( isset( $plugin_updates[ $self_update ] ) ) {
			return new WP_Error(
				'ag_sync_bridge_self_update_required',
				__( 'AG Sync Bridge has an available update. Install it with the normal WordPress updater, then retry the push.', 'ag-sync-bridge' ),
				array( 'plugin' => $self_update )
			);
		}

		$summary = array(
			'checked_at'   => gmdate( 'c' ),
			'plugins'      => array( 'available' => count( $plugin_updates ), 'updated' => array() ),
			'themes'       => array( 'available' => count( $theme_updates ), 'updated' => array() ),
			'translations' => array( 'available' => count( $translation_updates ), 'updated' => array() ),
		);

		$plugin_result = $this->upgrade_plugins( array_keys( $plugin_updates ) );
		if ( is_wp_error( $plugin_result ) ) {
			return $plugin_result;
		}
		$summary['plugins']['updated'] = $plugin_result;

		$theme_result = $this->upgrade_themes( array_keys( $theme_updates ) );
		if ( is_wp_error( $theme_result ) ) {
			return $theme_result;
		}
		$summary['themes']['updated'] = $theme_result;

		// Refresh language-pack metadata after code updates; new versions can
		// declare newer translations than the first check returned.
		if ( function_exists( 'wp_update_languages' ) ) {
			wp_update_languages();
		}
		$translation_updates = function_exists( 'wp_get_translation_updates' ) ? wp_get_translation_updates() : array();
		$translation_updates = is_array( $translation_updates ) ? $translation_updates : array();
		$summary['translations']['available'] = count( $translation_updates );
		$translation_result = $this->upgrade_translations( $translation_updates );
		if ( is_wp_error( $translation_result ) ) {
			return $translation_result;
		}
		$summary['translations']['updated'] = $translation_result;

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( true );
		}

		$this->logger->info( 'Local pre-push maintenance completed.', $summary );
		return $summary;
	}

	/** @return true|WP_Error */
	protected function load_upgrader_dependencies() {
		$required = array(
			ABSPATH . 'wp-admin/includes/file.php',
			ABSPATH . 'wp-admin/includes/misc.php',
			ABSPATH . 'wp-admin/includes/update.php',
			ABSPATH . 'wp-admin/includes/class-wp-upgrader.php',
			ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php',
			ABSPATH . 'wp-admin/includes/class-theme-upgrader.php',
			ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php',
		);
		foreach ( $required as $file ) {
			if ( ! file_exists( $file ) ) {
				return new WP_Error( 'ag_sync_bridge_maintenance_dependency_missing', __( 'WordPress update support is incomplete on the local site.', 'ag-sync-bridge' ), array( 'file' => $file ) );
			}
			require_once $file;
		}
		return true;
	}

	/** @return array */
	protected function plugin_updates() {
		$updates = get_site_transient( 'update_plugins' );
		return is_object( $updates ) && is_array( $updates->response ) ? $updates->response : array();
	}

	/** @return array */
	protected function theme_updates() {
		$updates = get_site_transient( 'update_themes' );
		return is_object( $updates ) && is_array( $updates->response ) ? $updates->response : array();
	}

	/** @return array|WP_Error */
	protected function upgrade_plugins( array $plugins ) {
		if ( empty( $plugins ) ) {
			return array();
		}
		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		return $this->completed_updates( $upgrader->bulk_upgrade( $plugins ), 'plugin' );
	}

	/** @return array|WP_Error */
	protected function upgrade_themes( array $themes ) {
		if ( empty( $themes ) ) {
			return array();
		}
		$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		return $this->completed_updates( $upgrader->bulk_upgrade( $themes ), 'theme' );
	}

	/** @return array|WP_Error */
	protected function upgrade_translations( array $translations ) {
		if ( empty( $translations ) ) {
			return array();
		}
		$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
		return $this->completed_updates( $upgrader->bulk_upgrade( $translations ), 'translation' );
	}

	/**
	 * @param mixed  $results Result from a WordPress upgrader bulk operation.
	 * @param string $kind    Human-readable update type.
	 * @return array|WP_Error
	 */
	private function completed_updates( $results, $kind ) {
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		if ( ! is_array( $results ) ) {
			return new WP_Error( 'ag_sync_bridge_maintenance_failed', sprintf( __( 'WordPress could not complete the %s update check.', 'ag-sync-bridge' ), $kind ) );
		}

		$completed = array();
		foreach ( $results as $identifier => $result ) {
			if ( true === $result ) {
				$completed[] = (string) $identifier;
				continue;
			}
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'ag_sync_bridge_maintenance_failed', sprintf( __( 'The %1$s update failed for %2$s: %3$s', 'ag-sync-bridge' ), $kind, $identifier, $result->get_error_message() ), array( 'kind' => $kind, 'identifier' => $identifier ) );
			}
			return new WP_Error( 'ag_sync_bridge_maintenance_failed', sprintf( __( 'The %1$s update failed for %2$s.', 'ag-sync-bridge' ), $kind, $identifier ), array( 'kind' => $kind, 'identifier' => $identifier ) );
		}

		return $completed;
	}
}
