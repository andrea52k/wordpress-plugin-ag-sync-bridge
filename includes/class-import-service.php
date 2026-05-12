<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Import_Service {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var File_System_Service
	 */
	private $file_system;

	/**
	 * @var Database_Service
	 */
	private $database;

	/**
	 * @var Archive_Service
	 */
	private $archive;

	/**
	 * @var bool
	 */
	private $maintenance_mode_enabled = false;

	/**
	 * @var bool
	 */
	private $maintenance_file_existed = false;

	/**
	 * @var string
	 */
	private $maintenance_previous_content = '';

	public function __construct( Config $config, Logger $logger, File_System_Service $file_system, Database_Service $database, Archive_Service $archive ) {
		$this->config      = $config;
		$this->logger      = $logger;
		$this->file_system = $file_system;
		$this->database    = $database;
		$this->archive     = $archive;
	}

	public function validate_package( $package_path, $expected_sha256 = '' ) {
		$prepared = $this->prepare_package( $package_path, $expected_sha256 );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$result = array(
			'basename'   => basename( $package_path ),
			'path'       => normalize_path( $package_path ),
			'sha256'     => $prepared['sha256'],
			'size_bytes' => filesize( $package_path ),
			'manifest'   => $prepared['manifest'],
		);

		$this->file_system->cleanup_path( $prepared['temp_dir'] );
		return $result;
	}

	public function import_snapshot( $package_path, array $args = array() ) {
		@set_time_limit( 0 );

		$started_at = microtime( true );
		$prepared   = $this->prepare_package( $package_path, array_get( $args, 'expected_sha256', '' ) );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$manifest      = $prepared['manifest'];
		$current_state = $this->database->capture_environment_state();
		$target_site   = array_get( $args, 'target_site_url', array_get( $current_state, 'siteurl', site_url() ) );
		$target_home   = array_get( $args, 'target_home_url', array_get( $current_state, 'home', home_url() ) );
		$source_prefix = $this->resolve_source_table_prefix( $manifest, $prepared['temp_dir'] );
		$target_prefix = $this->database->get_table_prefix();
		$source_active_plugins = array();
		$sync_active_plugins   = false;

		try {
			$this->enable_maintenance_mode();

			$import_result = $this->database->import_from_file(
				$prepared['database_sql'],
				array(
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix,
				)
			);
			if ( is_wp_error( $import_result ) ) {
				return $import_result;
			}

			$this->database->refresh_runtime_cache();
			$source_active_plugins = $this->database->get_active_plugins();

			$prefix_remap = $this->database->remap_site_prefix_keys( $source_prefix, $target_prefix );
			if ( is_wp_error( $prefix_remap ) ) {
				return $prefix_remap;
			}

			$replacements = array();
			$source_site  = array_get( $manifest, 'source_site_url', '' );
			$source_home  = array_get( $manifest, 'source_home_url', '' );

			if ( $source_site && $target_site ) {
				$replacements[ $source_site ] = $target_site;
			}

			if ( $source_home && $target_home ) {
				$replacements[ $source_home ] = $target_home;
			}

			$replace_result = $this->database->replace_urls( $replacements, $target_prefix );
			if ( is_wp_error( $replace_result ) ) {
				return $replace_result;
			}

			$files_root = normalize_path( $prepared['temp_dir'] . '/files' );
			$this->refresh_maintenance_mode();
			$result     = $this->import_files( $files_root, $replacements );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->clear_target_builder_caches();

			$sync_active_plugins = true;

			$response = array(
				'basename'        => basename( $package_path ),
				'path'            => normalize_path( $package_path ),
				'sha256'          => $prepared['sha256'],
				'manifest'        => $manifest,
				'database_method' => array_get( $import_result, 'method', 'php' ),
				'table_prefix'    => array(
					'source' => $source_prefix,
					'target' => $target_prefix,
					'remap'  => $prefix_remap,
				),
				'url_replace'     => $replace_result,
				'duration_ms'     => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			);

			$this->logger->info(
				'Snapshot imported.',
				array(
					'basename'     => $response['basename'],
					'duration_ms'  => $response['duration_ms'],
					'rows_updated' => array_get( $replace_result, 'rows_updated', 0 ),
				)
			);

			return $response;
		} catch ( \Throwable $throwable ) {
			$this->logger->error(
				'Snapshot import failed with runtime error.',
				array(
					'message' => $throwable->getMessage(),
					'file'    => $throwable->getFile(),
					'line'    => $throwable->getLine(),
				)
			);

			return new WP_Error(
				'ag_sync_bridge_import_runtime_error',
				__( 'Snapshot import failed because the target site hit a runtime error during URL replacement.', 'ag-sync-bridge' )
			);
		} finally {
			$this->database->restore_environment_state( $current_state );

			if ( $sync_active_plugins ) {
				$plugin_sync = $this->database->sync_active_plugins( $source_active_plugins );
				if ( is_wp_error( $plugin_sync ) ) {
					$this->logger->error(
						'Plugin activation sync failed after import.',
						array(
							'message' => $plugin_sync->get_error_message(),
							'data'    => $plugin_sync->get_error_data(),
						)
					);
					$this->file_system->cleanup_path( $prepared['temp_dir'] );
					$this->disable_maintenance_mode();
					return $plugin_sync;
				}
			}

			$this->disable_maintenance_mode();
			$this->file_system->cleanup_path( $prepared['temp_dir'] );
		}
	}

	private function enable_maintenance_mode() {
		$path = $this->get_maintenance_file_path();

		if ( ! $this->maintenance_mode_enabled ) {
			$this->maintenance_file_existed      = file_exists( $path );
			$this->maintenance_previous_content = $this->maintenance_file_existed ? (string) file_get_contents( $path ) : '';
		}

		$content = "<?php\n" . '$upgrading = ' . time() . ";\n";
		if ( false === file_put_contents( $path, $content, LOCK_EX ) ) {
			$this->logger->warning( 'Unable to enable WordPress maintenance mode during import.', array( 'path' => $path ) );
			return;
		}

		$this->maintenance_mode_enabled = true;
	}

	private function refresh_maintenance_mode() {
		if ( $this->maintenance_mode_enabled ) {
			$this->enable_maintenance_mode();
		}
	}

	private function disable_maintenance_mode() {
		if ( ! $this->maintenance_mode_enabled ) {
			return;
		}

		$path = $this->get_maintenance_file_path();
		if ( $this->maintenance_file_existed ) {
			file_put_contents( $path, $this->maintenance_previous_content, LOCK_EX );
		} elseif ( file_exists( $path ) ) {
			@unlink( $path );
		}

		$this->maintenance_mode_enabled     = false;
		$this->maintenance_file_existed     = false;
		$this->maintenance_previous_content = '';
	}

	private function get_maintenance_file_path() {
		return normalize_path( ABSPATH . '.maintenance' );
	}

	private function prepare_package( $package_path, $expected_sha256 = '' ) {
		$package_path = normalize_path( $package_path );

		if ( ! file_exists( $package_path ) ) {
			return new WP_Error( 'ag_sync_bridge_missing_package', __( 'Snapshot package not found.', 'ag-sync-bridge' ) );
		}

		$sha256 = hash_file( 'sha256', $package_path );

		if ( $expected_sha256 && ! hash_equals( strtolower( $expected_sha256 ), strtolower( $sha256 ) ) ) {
			return new WP_Error( 'ag_sync_bridge_checksum_failed', __( 'Snapshot checksum verification failed.', 'ag-sync-bridge' ) );
		}

		$temp_dir = $this->file_system->create_temp_dir( 'import' );
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$result = $this->archive->extract_package( $package_path, $temp_dir );
		if ( is_wp_error( $result ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return $result;
		}

		$manifest_path = normalize_path( $temp_dir . '/manifest.json' );
		$database_sql  = normalize_path( $temp_dir . '/database.sql' );

		if ( ! file_exists( $manifest_path ) || ! file_exists( $database_sql ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_package', __( 'Snapshot package is missing manifest.json or database.sql.', 'ag-sync-bridge' ) );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_manifest', __( 'Snapshot manifest is invalid.', 'ag-sync-bridge' ) );
		}

		return array(
			'sha256'      => $sha256,
			'temp_dir'    => $temp_dir,
			'manifest'    => $manifest,
			'database_sql'=> $database_sql,
		);
	}

	private function resolve_source_table_prefix( array $manifest, $temp_dir ) {
		$prefix = (string) array_get( $manifest, 'source_table_prefix', '' );
		if ( '' !== $prefix ) {
			return $prefix;
		}

		$wp_config = normalize_path( $temp_dir . '/files/root/wp-config.php' );
		if ( ! file_exists( $wp_config ) ) {
			return '';
		}

		$content = (string) file_get_contents( $wp_config );
		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches ) ) {
			return (string) $matches[1];
		}

		return '';
	}

	private function import_files( $files_root, array $replacements = array() ) {
		$wp_content_root = normalize_path( $files_root . '/wp-content' );
		$root_files      = normalize_path( $files_root . '/root' );

		if ( is_dir( $wp_content_root . '/uploads' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/uploads', WP_CONTENT_DIR . '/uploads' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/mpg-uploads' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/mpg-uploads', WP_CONTENT_DIR . '/mpg-uploads' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = $this->file_system->replace_urls_in_dataset_files( WP_CONTENT_DIR . '/mpg-uploads', $replacements );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/themes' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/themes', WP_CONTENT_DIR . '/themes' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/plugins' ) ) {
			$plugin_dir = dirname( $this->config->get_plugin_basename() );
			$plugin_dir = '.' === $plugin_dir ? 'ag-sync-bridge' : trim( str_replace( '\\', '/', $plugin_dir ), '/' );
			$result     = $this->file_system->replace_directory( $wp_content_root . '/plugins', WP_CONTENT_DIR . '/plugins', array( $plugin_dir ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/mu-plugins' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/mu-plugins', WP_CONTENT_DIR . '/mu-plugins' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( file_exists( $root_files . '/wp-config.php' ) ) {
			$this->file_system->merge_wp_config( $root_files . '/wp-config.php', ABSPATH . 'wp-config.php' );
		}

		if ( $this->config->get( 'include_htaccess', false ) && file_exists( $root_files . '/.htaccess' ) ) {
			$this->file_system->copy_file( $root_files . '/.htaccess', ABSPATH . '.htaccess' );
		}

		$result = $this->file_system->sync_root_text_files( $root_files, ABSPATH, $replacements );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	private function clear_target_builder_caches() {
		wp_cache_flush();

		$elementor_css_dir = normalize_path( WP_CONTENT_DIR . '/uploads/elementor/css' );
		if ( is_dir( $elementor_css_dir ) ) {
			$this->file_system->cleanup_path( $elementor_css_dir );
			ensure_directory( $elementor_css_dir );
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		do_action( 'elementor/core/files/clear_cache' );
		do_action( 'litespeed_purge_all' );
	}
}
