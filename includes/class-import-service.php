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
		$prepared   = $this->prepare_package( $package_path, array_get( $args, 'expected_sha256', '' ), $args );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$manifest      = $prepared['manifest'];
		$is_partial    = $this->is_partial_manifest( $manifest );
		$current_state = $this->database->capture_environment_state();
		$target_site   = array_get( $args, 'target_site_url', array_get( $current_state, 'siteurl', site_url() ) );
		$target_home   = array_get( $args, 'target_home_url', array_get( $current_state, 'home', home_url() ) );
		$source_prefix = $this->resolve_source_table_prefix( $manifest, $prepared['temp_dir'] );
		$target_prefix = $this->database->get_table_prefix();
		$source_active_plugins = array();
		$sync_active_plugins   = false;
		$rollback_required     = false;

		try {
			$cancelled = $this->check_cancellation( $args, 'prepared', false );
			if ( is_wp_error( $cancelled ) ) {
				return $cancelled;
			}

			$this->enable_maintenance_mode();

			$import_result = array(
				'method' => 'none',
				'scope'  => 'partial',
			);
			$prefix_remap  = array();

			if ( ! $is_partial ) {
				$cancelled = $this->check_cancellation( $args, 'before_database_import', false );
				if ( is_wp_error( $cancelled ) ) {
					return $cancelled;
				}

				$rollback_required = true;
				$this->report_mutation_started( $args, 'database_import_started' );
				$import_result = $this->database->import_from_file(
					$prepared['database_sql'],
					array(
						'source_prefix' => $source_prefix,
						'target_prefix' => $target_prefix,
						'progress_callback' => array_get( $args, 'progress_callback', null ),
					)
				);
				if ( is_wp_error( $import_result ) ) {
					return $this->with_failure_context( $import_result, 'database_import', true );
				}

				$cancelled = $this->check_cancellation( $args, 'after_database_import', true );
				if ( is_wp_error( $cancelled ) ) {
					return $cancelled;
				}

				$this->database->refresh_runtime_cache();
				$source_active_plugins = $this->database->get_active_plugins();

				/*
				 * Restore the target identity before URL replacement and file sync.
				 *
				 * Large imports can outlive a hosting worker even when PHP's time
				 * limit is disabled. Keeping this only in `finally` can therefore
				 * leave a live site with the source home/site URL, bridge settings
				 * and plugin activation list when the worker is killed externally.
				 * The final restore remains as an idempotent safety net.
				 */
				$this->database->restore_environment_state( $current_state );
				$this->logger->info(
					'Target environment restored immediately after database import.',
					array(
						'target_site_url' => $target_site,
						'target_home_url' => $target_home,
					)
				);

				$prefix_remap = $this->database->remap_site_prefix_keys( $source_prefix, $target_prefix );
				if ( is_wp_error( $prefix_remap ) ) {
					return $this->with_failure_context( $prefix_remap, 'prefix_remap', true );
				}
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

			$replace_result = array(
				'rows_updated' => 0,
				'scope'        => 'partial',
			);

			if ( ! $is_partial ) {
				$replace_result = $this->database->replace_urls( $replacements, $target_prefix );
				if ( is_wp_error( $replace_result ) ) {
					return $this->with_failure_context( $replace_result, 'url_replace', true );
				}

				$cancelled = $this->check_cancellation( $args, 'after_url_replace', true );
				if ( is_wp_error( $cancelled ) ) {
					return $cancelled;
				}
			}

			$files_root = normalize_path( $prepared['temp_dir'] . '/files' );
			$this->refresh_maintenance_mode();
			$cancelled = $this->check_cancellation( $args, 'before_files_import', ! $is_partial );
			if ( is_wp_error( $cancelled ) ) {
				return $cancelled;
			}
			$rollback_required = true;
			$this->report_mutation_started( $args, 'files_import_started' );
			$result     = $is_partial ? $this->import_partial_files( $files_root, $manifest, $replacements, $args ) : $this->import_files( $files_root, $replacements, $args );

			if ( is_wp_error( $result ) ) {
				return $this->with_failure_context( $result, 'files_import', true );
			}

			$cancelled = $this->check_cancellation( $args, 'after_files_import', true );
			if ( is_wp_error( $cancelled ) ) {
				return $cancelled;
			}

			$this->clear_target_builder_caches();

			if ( ! $is_partial ) {
				$sync_active_plugins = true;
			}

			$response = array(
				'basename'        => basename( $package_path ),
				'path'            => normalize_path( $package_path ),
				'sha256'          => $prepared['sha256'],
				'manifest'        => $manifest,
				'import_scope'    => $is_partial ? 'partial' : 'full',
				'database_method' => array_get( $import_result, 'method', 'php' ),
				'table_prefix'    => array(
					'source' => $source_prefix,
					'target' => $target_prefix,
					'remap'  => $prefix_remap,
				),
				'url_replace'     => $replace_result,
				'file_import'     => $result,
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

			do_action(
				'ag_sync_bridge_after_import',
				$response,
				array(
					'package_path'     => normalize_path( $package_path ),
					'import_scope'     => $is_partial ? 'partial' : 'full',
					'target_site_url'  => $target_site,
					'target_home_url'  => $target_home,
					'source_site_url'  => $source_site,
					'source_home_url'  => $source_home,
					'replacements'     => $replacements,
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
				__( 'Snapshot import failed because the target site hit a runtime error during URL replacement.', 'ag-sync-bridge' ),
				array(
					'rollback_required' => (bool) $rollback_required,
					'stage'             => 'runtime-error',
				)
			);
		} finally {
			if ( ! $is_partial ) {
				$this->database->restore_environment_state( $current_state );
			}

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
					return $this->with_failure_context( $plugin_sync, 'plugin_sync', true );
				}
			}

			$this->disable_maintenance_mode();
			$this->file_system->cleanup_path( $prepared['temp_dir'] );
		}
	}

	/**
	 * Stops only at durable phase boundaries. A request received after database
	 * or file mutation must be reported as requiring recovery; it is never
	 * presented as an intact cancelled import.
	 *
	 * @param array  $args Import arguments.
	 * @param string $stage Current durable stage.
	 * @param bool   $rollback_required Whether the target may already be changed.
	 * @return true|WP_Error
	 */
	private function check_cancellation( array $args, $stage, $rollback_required ) {
		$progress_callback = array_get( $args, 'progress_callback', null );
		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, $stage, null, array( 'rollback_required_at_checkpoint' => (bool) $rollback_required ) );
		}

		$checkpoint = array_get( $args, 'checkpoint_callback', null );
		if ( is_callable( $checkpoint ) ) {
			call_user_func( $checkpoint, $stage, $rollback_required );
		}

		$callback = array_get( $args, 'cancellation_check', null );
		if ( ! is_callable( $callback ) || ! call_user_func( $callback, $stage, $rollback_required ) ) {
			return true;
		}

		return new WP_Error(
			'ag_sync_bridge_operation_cancelled',
			$rollback_required
				? __( 'Import cancellation was requested after target data changed. Restore the pre-import backup before treating the site as healthy.', 'ag-sync-bridge' )
				: __( 'Import was cancelled before target data changed.', 'ag-sync-bridge' ),
			array(
				'cancelled'         => true,
				'rollback_required' => (bool) $rollback_required,
				'stage'             => sanitize_key( $stage ),
			)
		);
	}

	private function report_mutation_started( array $args, $stage ) {
		$progress_callback = array_get( $args, 'progress_callback', null );
		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, $stage, null, array( 'rollback_required_at_checkpoint' => true, 'target_mutated' => true ) );
		}

		$checkpoint = array_get( $args, 'checkpoint_callback', null );
		if ( is_callable( $checkpoint ) ) {
			call_user_func( $checkpoint, $stage, true );
		}
	}

	private function with_failure_context( WP_Error $error, $stage, $rollback_required ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array( 'original_data' => $data );
		$data['rollback_required'] = (bool) $rollback_required;
		$data['stage']             = sanitize_key( $stage );

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			$data
		);
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

	private function prepare_package( $package_path, $expected_sha256 = '', array $args = array() ) {
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

		$result = $this->archive->extract_package(
			$package_path,
			$temp_dir,
			function ( $stage, $progress, array $details = array() ) use ( $args ) {
				$callback = array_get( $args, 'progress_callback', null );
				if ( is_callable( $callback ) ) {
					call_user_func( $callback, $stage, $progress, $details );
				}
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return $result;
		}

		$manifest_path = normalize_path( $temp_dir . '/manifest.json' );
		$database_sql  = normalize_path( $temp_dir . '/database.sql' );

		if ( ! file_exists( $manifest_path ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_package', __( 'Snapshot package is missing manifest.json.', 'ag-sync-bridge' ) );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_manifest', __( 'Snapshot manifest is invalid.', 'ag-sync-bridge' ) );
		}

		$is_partial = $this->is_partial_manifest( $manifest );
		if ( ! $is_partial && ! file_exists( $database_sql ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_package', __( 'Snapshot package is missing database.sql.', 'ag-sync-bridge' ) );
		}

		if ( $is_partial && empty( $manifest['partial_entries'] ) ) {
			$this->file_system->cleanup_path( $temp_dir );
			return new WP_Error( 'ag_sync_bridge_invalid_partial_package', __( 'Partial snapshot package is missing partial_entries metadata.', 'ag-sync-bridge' ) );
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

	private function is_partial_manifest( array $manifest ) {
		return 'partial' === $this->file_system->get_snapshot_scope_from_manifest( $manifest );
	}

	private function import_partial_files( $files_root, array $manifest, array $replacements = array(), array $args = array() ) {
		$entries = array_get( $manifest, 'partial_entries', array() );
		$entries = is_array( $entries ) ? $entries : array();

		$summary = array(
			'scope'      => 'partial',
			'file_count' => 0,
			'dir_count'  => 0,
			'paths'      => array(),
		);

		foreach ( $entries as $entry ) {
			$result = $this->import_partial_entry( $files_root, is_array( $entry ) ? $entry : array(), $replacements, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$summary['paths'][] = array_get( $result, 'path', '' );
			if ( 'directory' === array_get( $result, 'type', '' ) ) {
				$summary['dir_count']++;
			} else {
				$summary['file_count']++;
			}
		}

		return $summary;
	}

	private function import_partial_entry( $files_root, array $entry, array $replacements = array(), array $args = array() ) {
		$relative = $this->sanitize_partial_entry_path( array_get( $entry, 'path', '' ) );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		$type        = 'directory' === array_get( $entry, 'type', '' ) ? 'directory' : 'file';
		$source_path = $this->resolve_partial_source_path( $files_root, $relative );
		$target_path = normalize_path( ABSPATH . $relative );

		if ( ! file_exists( $source_path ) ) {
			return new WP_Error(
				'ag_sync_bridge_partial_source_missing',
				sprintf(
					/* translators: %s: relative path */
					__( 'Partial snapshot is missing the archived path: %s', 'ag-sync-bridge' ),
					$relative
				)
			);
		}

		if ( 'directory' === $type ) {
			if ( ! is_dir( $source_path ) ) {
				return new WP_Error( 'ag_sync_bridge_partial_source_type', __( 'Partial snapshot entry expected a directory but found a file.', 'ag-sync-bridge' ) );
			}

			$result = $this->file_system->replace_directory( $source_path, $target_path, array(), $this->get_file_progress_callback( $args ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $this->is_mpg_uploads_path( $relative ) ) {
				$result = $this->file_system->replace_urls_in_dataset_files( $target_path, $replacements );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		} else {
			if ( is_dir( $source_path ) ) {
				return new WP_Error( 'ag_sync_bridge_partial_source_type', __( 'Partial snapshot entry expected a file but found a directory.', 'ag-sync-bridge' ) );
			}

			$result = $this->is_text_like_partial_file( $relative )
				? $this->file_system->copy_text_file_with_replacements( $source_path, $target_path, $replacements )
				: $this->file_system->copy_file( $source_path, $target_path );

			if ( false === $result ) {
				return new WP_Error(
					'ag_sync_bridge_partial_copy_failed',
					sprintf(
						/* translators: %s: relative path */
						__( 'Unable to copy partial snapshot file: %s', 'ag-sync-bridge' ),
						$relative
					)
				);
			}

			if ( $this->is_mpg_uploads_path( $relative ) ) {
				$result = $this->file_system->replace_urls_in_dataset_files( dirname( $target_path ), $replacements );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return array(
			'path' => $relative,
			'type' => $type,
		);
	}

	private function sanitize_partial_entry_path( $path ) {
		$relative = trim( str_replace( '\\', '/', (string) $path ) );
		$relative = preg_replace( '#/+#', '/', $relative );
		$relative = trim( $relative, '/' );

		if ( '' === $relative || false !== strpos( $relative, "\0" ) || preg_match( '#^[a-zA-Z]:/#', $relative ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_entry_unsafe', __( 'Partial snapshot contains an unsafe target path.', 'ag-sync-bridge' ) );
		}

		$relative_lc = strtolower( $relative );
		$plugin_dir  = dirname( $this->config->get_plugin_basename() );
		$plugin_dir  = '.' === $plugin_dir ? 'ag-sync-bridge' : trim( str_replace( '\\', '/', $plugin_dir ), '/' );
		$plugin_rel  = strtolower( 'wp-content/plugins/' . $plugin_dir );

		if ( 'wp-config.php' === $relative_lc || 0 === strpos( $relative_lc, 'wp-admin/' ) || 0 === strpos( $relative_lc, 'wp-includes/' ) || 0 === strpos( $relative_lc, 'wp-content/ag-sync-bridge-data/' ) || $relative_lc === $plugin_rel || 0 === strpos( $relative_lc, $plugin_rel . '/' ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_entry_forbidden', __( 'Partial snapshot contains a forbidden target path.', 'ag-sync-bridge' ) );
		}

		if ( false === strpos( $relative, '/' ) && ! preg_match( '/^(\.htaccess|robots\.txt|llms\.txt|llms-full\.txt|ads\.txt|app-ads\.txt|humans\.txt|.+\.xml)$/i', $relative ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_entry_root_forbidden', __( 'Partial snapshot contains an unsupported root file.', 'ag-sync-bridge' ) );
		}

		if ( false !== strpos( $relative, '/' ) && 0 !== strpos( $relative_lc, 'wp-content/' ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_entry_root_forbidden', __( 'Partial snapshot target must be under wp-content or be a supported root file.', 'ag-sync-bridge' ) );
		}

		$target = normalize_path( ABSPATH . $relative );
		$root   = rtrim( normalize_path( ABSPATH ), '/' );
		if ( 0 !== strpos( $target . '/', $root . '/' ) ) {
			return new WP_Error( 'ag_sync_bridge_partial_entry_outside_root', __( 'Partial snapshot target resolves outside the WordPress root.', 'ag-sync-bridge' ) );
		}

		return $relative;
	}

	private function resolve_partial_source_path( $files_root, $relative ) {
		$files_root = rtrim( normalize_path( $files_root ), '/\\' );

		if ( false === strpos( $relative, '/' ) ) {
			return normalize_path( $files_root . '/root/' . basename( $relative ) );
		}

		return normalize_path( $files_root . '/' . $relative );
	}

	private function is_text_like_partial_file( $relative ) {
		if ( false === strpos( $relative, '/' ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(?:css|csv|htm|html|js|json|md|php|svg|txt|xml|yml|yaml)$/i', $relative );
	}

	private function is_mpg_uploads_path( $relative ) {
		$relative = strtolower( trim( str_replace( '\\', '/', (string) $relative ), '/' ) );
		return 'wp-content/mpg-uploads' === $relative || 0 === strpos( $relative, 'wp-content/mpg-uploads/' );
	}

	private function import_files( $files_root, array $replacements = array(), array $args = array() ) {
		$wp_content_root = normalize_path( $files_root . '/wp-content' );
		$root_files      = normalize_path( $files_root . '/root' );
		$file_progress   = $this->get_file_progress_callback( $args );

		if ( is_dir( $wp_content_root . '/uploads' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/uploads', WP_CONTENT_DIR . '/uploads', array(), $file_progress );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/mpg-uploads' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/mpg-uploads', WP_CONTENT_DIR . '/mpg-uploads', array(), $file_progress );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = $this->file_system->replace_urls_in_dataset_files( WP_CONTENT_DIR . '/mpg-uploads', $replacements );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/themes' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/themes', WP_CONTENT_DIR . '/themes', array(), $file_progress );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/plugins' ) ) {
			$plugin_dir = dirname( $this->config->get_plugin_basename() );
			$plugin_dir = '.' === $plugin_dir ? 'ag-sync-bridge' : trim( str_replace( '\\', '/', $plugin_dir ), '/' );
			$result     = $this->file_system->replace_directory( $wp_content_root . '/plugins', WP_CONTENT_DIR . '/plugins', array( $plugin_dir ), $file_progress );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( is_dir( $wp_content_root . '/mu-plugins' ) ) {
			$result = $this->file_system->replace_directory( $wp_content_root . '/mu-plugins', WP_CONTENT_DIR . '/mu-plugins', array(), $file_progress );
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

	private function get_file_progress_callback( array $args ) {
		$callback = array_get( $args, 'progress_callback', null );
		if ( ! is_callable( $callback ) ) {
			return null;
		}
		return static function ( array $details ) use ( $callback ) {
			call_user_func( $callback, 'files-import', null, $details );
		};
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
