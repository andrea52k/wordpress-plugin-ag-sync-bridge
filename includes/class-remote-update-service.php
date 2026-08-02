<?php
namespace AGSyncBridge;

use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs one explicitly requested official GitHub release on a remote peer.
 * The REST controller provides HMAC authentication; this service validates the
 * release identity, checksum, package shape and local runtime safety.
 */
class Remote_Update_Service {
	const CONFIRMATION = 'UPDATE AG SYNC';
	const MAX_PACKAGE_BYTES = 52428800;
	const MAX_UNCOMPRESSED_BYTES = 209715200;

	private $config;
	private $logger;
	private $runtime;

	public function __construct( Config $config, Logger $logger, Remote_Operation_Runtime $runtime ) {
		$this->config  = $config;
		$this->logger  = $logger;
		$this->runtime = $runtime;
	}

	public function update_from_github_release( $version, $expected_sha256, $expected_current_version, $confirmation ) {
		$version                  = trim( (string) $version );
		$expected_sha256          = strtolower( trim( (string) $expected_sha256 ) );
		$expected_current_version = trim( (string) $expected_current_version );

		if ( 'remote' !== $this->config->get_role() ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_wrong_role', __( 'Remote self-update is available only on a configured remote peer.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}
		if ( self::CONFIRMATION !== (string) $confirmation ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_confirmation', __( 'Exact remote update confirmation is required.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_invalid_release', __( 'A valid target version and SHA-256 checksum are required.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}
		if ( '' === $expected_current_version || AG_SYNC_BRIDGE_VERSION !== $expected_current_version ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_version_race', __( 'Installed AG Sync version changed after local preflight.', 'ag-sync-bridge' ), array( 'status' => 409, 'installed_version' => AG_SYNC_BRIDGE_VERSION ) );
		}
		if ( ! version_compare( $version, AG_SYNC_BRIDGE_VERSION, '>' ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_not_newer', __( 'Remote self-update blocks reinstall and downgrade operations.', 'ag-sync-bridge' ), array( 'status' => 409, 'installed_version' => AG_SYNC_BRIDGE_VERSION ) );
		}

		$operation = $this->runtime->inspect();
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}
		$status = (string) array_get( $operation, 'status', '' );
		$stale_quarantine = 'reconcile_requested' === $status && (bool) array_get( array_get( $operation, 'heartbeat', array() ), 'is_stale', false );
		if ( ! empty( $operation ) && ! $stale_quarantine && ! in_array( $status, array( 'complete', 'error', 'cancelled', 'reconciled' ), true ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_operation_active', __( 'Remote self-update is blocked while an async operation is active or unresolved.', 'ag-sync-bridge' ), array( 'status' => 409, 'operation' => $operation ) );
		}
		if ( $stale_quarantine ) {
			$this->logger->warning( 'Allowing signed bridge update for stale quarantined operation.', array( 'operation_id' => array_get( $operation, 'id', '' ), 'status' => $status ) );
		}

		$this->load_wordpress_update_dependencies();
		if ( ! function_exists( 'get_filesystem_method' ) || 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_filesystem', __( 'Remote self-update requires direct WordPress filesystem access without credential prompts.', 'ag-sync-bridge' ), array( 'status' => 409 ) );
		}

		$release = $this->fetch_release( $version );
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		$asset = $this->find_asset( $release );
		if ( empty( $asset ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_asset_missing', __( 'The official release does not contain ag-sync-bridge.zip.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$url = (string) array_get( $asset, 'browser_download_url', '' );
		if ( ! $this->is_official_asset_url( $url, $version ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_asset_url', __( 'The release asset URL is not an allowed official GitHub URL.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$temp_file = download_url( $url, 300 );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		try {
			$size = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;
			if ( $size <= 0 || $size > self::MAX_PACKAGE_BYTES ) {
				return new WP_Error( 'ag_sync_bridge_remote_update_package_size', __( 'Downloaded update package has an invalid size.', 'ag-sync-bridge' ), array( 'status' => 400, 'size_bytes' => $size ) );
			}

			$actual_sha256 = strtolower( (string) hash_file( 'sha256', $temp_file ) );
			if ( ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
				return new WP_Error( 'ag_sync_bridge_remote_update_checksum', __( 'Downloaded update package checksum does not match.', 'ag-sync-bridge' ), array( 'status' => 400, 'actual_sha256' => $actual_sha256 ) );
			}

			$package = $this->validate_package( $temp_file, $version );
			if ( is_wp_error( $package ) ) {
				return $package;
			}

			$this->write_audit_state(
				array(
					'status'       => 'installing',
					'requested_at' => gmdate( 'c' ),
					'from_version' => AG_SYNC_BRIDGE_VERSION,
					'to_version'   => $version,
					'sha256'       => $actual_sha256,
				)
			);

			$was_active = is_plugin_active( plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE ) );
			$upgrader   = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result     = $upgrader->install( $temp_file, array( 'overwrite_package' => true ) );
			if ( is_wp_error( $result ) ) {
				$this->write_audit_state( array( 'status' => 'error', 'finished_at' => gmdate( 'c' ), 'message' => $result->get_error_message(), 'to_version' => $version ) );
				return $result;
			}
			if ( ! $result ) {
				$error = new WP_Error( 'ag_sync_bridge_remote_update_install_failed', __( 'WordPress did not install the remote update package.', 'ag-sync-bridge' ), array( 'status' => 500 ) );
				$this->write_audit_state( array( 'status' => 'error', 'finished_at' => gmdate( 'c' ), 'message' => $error->get_error_message(), 'to_version' => $version ) );
				return $error;
			}

			wp_clean_plugins_cache( true );
			$disk_version = $this->read_installed_version();
			if ( $version !== $disk_version ) {
				$this->write_audit_state( array( 'status' => 'error', 'finished_at' => gmdate( 'c' ), 'message' => 'postcheck_version_mismatch', 'to_version' => $version, 'disk_version' => $disk_version ) );
				return new WP_Error( 'ag_sync_bridge_remote_update_postcheck', __( 'Installed plugin version does not match the requested release.', 'ag-sync-bridge' ), array( 'status' => 500, 'disk_version' => $disk_version ) );
			}

			if ( $was_active && ! is_plugin_active( plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE ) ) ) {
				$activation = activate_plugin( plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE ), '', false, true );
				if ( is_wp_error( $activation ) ) {
					$this->write_audit_state( array( 'status' => 'error', 'finished_at' => gmdate( 'c' ), 'message' => 'reactivation_failed', 'to_version' => $version, 'disk_version' => $disk_version ) );
					return new WP_Error( 'ag_sync_bridge_remote_update_reactivation', __( 'Plugin files updated but automatic reactivation failed.', 'ag-sync-bridge' ), array( 'status' => 500, 'activation_error' => $activation->get_error_message(), 'disk_version' => $disk_version ) );
				}
			}

			$runtime_recycle = $this->recycle_php_runtime();
			$audit = array(
				'status'       => 'updated',
				'finished_at'  => gmdate( 'c' ),
				'from_version' => AG_SYNC_BRIDGE_VERSION,
				'to_version'   => $disk_version,
				'sha256'       => $actual_sha256,
				'active'       => is_plugin_active( plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE ) ),
				'runtime_recycle' => $runtime_recycle,
			);
			$this->write_audit_state( $audit );
			$this->logger->warning( 'AG Sync Bridge remotely updated from verified GitHub release.', $audit );
			return $audit;
		} finally {
			if ( is_string( $temp_file ) && file_exists( $temp_file ) ) {
				@unlink( $temp_file );
			}
		}
	}

	protected function fetch_release( $version ) {
		$url = sprintf( 'https://api.github.com/repos/%s/%s/releases/tags/v%s', GitHub_Updater::OWNER, GitHub_Updater::REPOSITORY, rawurlencode( $version ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'User-Agent'           => 'AG-Sync-Bridge/' . AG_SYNC_BRIDGE_VERSION,
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_release_lookup', __( 'Unable to resolve the requested official GitHub release.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}

		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || 'v' . $version !== (string) array_get( $release, 'tag_name', '' ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_release_invalid', __( 'Requested GitHub release is invalid, draft or prerelease.', 'ag-sync-bridge' ), array( 'status' => 400 ) );
		}
		return $release;
	}

	protected function find_asset( array $release ) {
		foreach ( (array) array_get( $release, 'assets', array() ) as $asset ) {
			if ( is_array( $asset ) && GitHub_Updater::ASSET_NAME === (string) array_get( $asset, 'name', '' ) ) {
				return $asset;
			}
		}
		return array();
	}

	protected function validate_package( $path, $version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_zip_missing', __( 'ZipArchive is required to validate the update package.', 'ag-sync-bridge' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_update_zip_invalid', __( 'Update package is not a readable ZIP archive.', 'ag-sync-bridge' ) );
		}
		try {
			if ( $zip->numFiles <= 0 || $zip->numFiles > 1000 ) {
				return new WP_Error( 'ag_sync_bridge_remote_update_zip_entries', __( 'Update package contains an unsafe number of files.', 'ag-sync-bridge' ) );
			}
			$uncompressed_bytes = 0;
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $index ) );
				if ( 0 !== strpos( $name, 'ag-sync-bridge/' ) || false !== strpos( $name, '../' ) ) {
					return new WP_Error( 'ag_sync_bridge_remote_update_zip_layout', __( 'Update package contains an unexpected or unsafe path.', 'ag-sync-bridge' ) );
				}
				$stat = $zip->statIndex( $index );
				$uncompressed_bytes += is_array( $stat ) ? (int) array_get( $stat, 'size', 0 ) : 0;
				if ( $uncompressed_bytes > self::MAX_UNCOMPRESSED_BYTES ) {
					return new WP_Error( 'ag_sync_bridge_remote_update_zip_expansion', __( 'Update package expands beyond the allowed safety limit.', 'ag-sync-bridge' ) );
				}
			}

			$main = $zip->getFromName( 'ag-sync-bridge/ag-sync-bridge.php' );
			if ( false === $main || ! preg_match( '/^[ \t*#@]*Version:\s*([^\s]+)/mi', $main, $matches ) || $version !== trim( $matches[1] ) ) {
				return new WP_Error( 'ag_sync_bridge_remote_update_package_version', __( 'Update package plugin version does not match the requested release.', 'ag-sync-bridge' ) );
			}
		} finally {
			$zip->close();
		}
		return true;
	}

	protected function is_official_asset_url( $url, $version ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) array_get( $parts, 'scheme', '' ) ) || 'github.com' !== strtolower( (string) array_get( $parts, 'host', '' ) ) ) {
			return false;
		}
		$expected_prefix = sprintf( '/%s/%s/releases/download/v%s/', GitHub_Updater::OWNER, GitHub_Updater::REPOSITORY, $version );
		return 0 === strpos( (string) array_get( $parts, 'path', '' ), $expected_prefix )
			&& GitHub_Updater::ASSET_NAME === basename( (string) array_get( $parts, 'path', '' ) );
	}

	private function load_wordpress_update_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	private function read_installed_version() {
		$data = get_plugin_data( AG_SYNC_BRIDGE_PLUGIN_FILE, false, false );
		return trim( (string) array_get( $data, 'Version', '' ) );
	}

	private function recycle_php_runtime() {
		$plugin_dir = dirname( AG_SYNC_BRIDGE_PLUGIN_FILE );
		if ( function_exists( 'opcache_invalidate' ) && is_dir( $plugin_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					@opcache_invalidate( $file->getPathname(), true );
				}
			}
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		$user_home = dirname( rtrim( wp_normalize_path( ABSPATH ), '/' ) );
		return array(
			'lsphp_restart' => @touch( $user_home . '/.lsphp_restart.txt' ),
			'mod_lsapi_reset' => @touch( $user_home . '/mod_lsapi_reset_me' ),
		);
	}

	private function write_audit_state( array $state ) {
		$dir = $this->config->get_data_dir( 'operations' );
		ensure_directory( $dir );
		$path = normalize_path( $dir . '/remote-update.json' );
		file_put_contents( $path, wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), LOCK_EX );
	}
}
