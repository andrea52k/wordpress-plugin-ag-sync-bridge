<?php
namespace AGSyncBridge;

use mysqli;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Service {
	const IMPORT_MAX_ALLOWED_PACKET = 268435456;
	const IMPORT_NET_TIMEOUT        = 120;
	const URL_REPLACE_BATCH_SIZE    = 500;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function export_to_file( $file_path, array $args = array() ) {
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '-1' );

		$file_path = normalize_path( $file_path );
		$progress_callback = array_get( $args, 'progress_callback', null );
		$expected_size_bytes = (int) array_get( $args, 'expected_size_bytes', 0 );
		$cancellation_check = array_get( $args, 'cancellation_check', null );

		if ( $this->can_use_cli_tools() ) {
			$result = $this->export_via_cli( $file_path, $progress_callback, $cancellation_check );
			if ( ! is_wp_error( $result ) ) {
				return array(
					'method'    => 'mysqldump',
					'file_path' => $file_path,
				);
			}

			$this->logger->warning( 'mysqldump export failed. Falling back to PHP exporter.', array( 'error' => $result->get_error_message() ) );
		}

		$result = $this->export_via_php( $file_path, $progress_callback, $cancellation_check );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'method'    => 'php',
			'file_path' => $file_path,
		);
	}

	public function import_from_file( $file_path, array $args = array() ) {
		@set_time_limit( 0 );

		$is_zip_stream_input = 0 === strpos( (string) $file_path, 'zip://' );
		$file_path = $is_zip_stream_input ? str_replace( '\\', '/', (string) $file_path ) : normalize_path( $file_path );
		$source_prefix = (string) array_get( $args, 'source_prefix', '' );
		$target_prefix = (string) array_get( $args, 'target_prefix', '' );
		$progress_callback = array_get( $args, 'progress_callback', null );
		$direct_same_site_restore = (
			! empty( $args['trusted_same_site_php_restore'] )
			&& '' !== $source_prefix
			&& hash_equals( $source_prefix, $target_prefix )
		);
		$is_zip_stream = 0 === strpos( $file_path, 'zip://' );
		if ( ! file_exists( $file_path ) && ! ( $direct_same_site_restore && $is_zip_stream ) ) {
			return new WP_Error(
				'ag_sync_bridge_missing_sql',
				__( 'Database SQL file not found.', 'ag-sync-bridge' ),
				array(
					'is_zip_stream' => $is_zip_stream,
					'trusted_same_site_php_restore' => ! empty( $args['trusted_same_site_php_restore'] ),
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix,
				)
			);
		}
		$prepared = $direct_same_site_restore
			? array(
				'path'                    => $file_path,
				'cleanup_path'            => '',
				'prefix_remapped'         => false,
				'sandbox_lines_removed'   => 0,
				'transient_rows_removed'  => 0,
				'direct_same_site_restore'=> true,
			)
			: $this->prepare_sql_for_import( $file_path, $source_prefix, $target_prefix, $progress_callback );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( $direct_same_site_restore ) {
			$this->logger->info( 'Using the verified same-site PHP dump directly to avoid a second multi-gigabyte SQL copy.' );
		}

		$import_path  = array_get( $prepared, 'path', $file_path );
		$cleanup_path = array_get( $prepared, 'cleanup_path', '' );

		if ( ! empty( $prepared['prefix_remapped'] ) || ! empty( $prepared['sandbox_lines_removed'] ) || ! empty( $prepared['transient_rows_removed'] ) ) {
			$this->logger->info(
				'Prepared SQL import.',
				array(
					'source_prefix'         => $source_prefix,
					'target_prefix'         => $target_prefix,
					'prefix_remapped'       => ! empty( $prepared['prefix_remapped'] ),
					'sandbox_lines_removed' => (int) array_get( $prepared, 'sandbox_lines_removed', 0 ),
					'transient_rows_removed'=> (int) array_get( $prepared, 'transient_rows_removed', 0 ),
				)
			);
		}

		try {
			if ( ! $direct_same_site_restore && $this->can_use_cli_tools() ) {
				$result = $this->import_via_cli( $import_path, $progress_callback );
				if ( ! is_wp_error( $result ) ) {
					return array(
						'method'        => 'mysql',
						'source_prefix' => $source_prefix,
						'target_prefix' => $target_prefix,
					);
				}

				$this->logger->warning( 'mysql import failed. Falling back to PHP importer.', array( 'error' => $result->get_error_message() ) );
			}

			$result = $this->import_via_php( $import_path, $target_prefix, $progress_callback, $expected_size_bytes );
			if ( ! is_wp_error( $result ) ) {
				return array(
					'method'        => 'php',
					'source_prefix' => $source_prefix,
					'target_prefix' => $target_prefix,
				);
			}
		} finally {
			if ( $cleanup_path && file_exists( $cleanup_path ) ) {
				@unlink( $cleanup_path );
			}
		}

		return $result;
	}

	public function get_table_prefix() {
		global $wpdb;

		return (string) $wpdb->prefix;
	}

	public function capture_environment_state() {
		return array(
			'settings'       => get_option( Config::OPTION_SETTINGS, array() ),
			'state'          => get_option( Config::OPTION_STATE, array() ),
			'recent_logs'    => get_option( Config::OPTION_RECENT_LOGS, array() ),
			'active_plugins' => $this->get_active_plugins(),
			'siteurl'        => get_option( 'siteurl' ),
			'home'           => get_option( 'home' ),
		);
	}

	public function get_active_plugins() {
		return $this->sanitize_active_plugins( get_option( 'active_plugins', array() ) );
	}

	public function refresh_runtime_cache() {
		wp_cache_flush();

		if ( function_exists( 'wp_load_alloptions' ) ) {
			wp_load_alloptions( true );
		}
	}

	public function restore_environment_state( array $state, $active_plugins = null ) {
		global $wpdb;

		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection( false );
		}

		$this->refresh_runtime_cache();

		Plugin::suspend_settings_update_handlers();

		try {
			update_option( Config::OPTION_SETTINGS, array_get( $state, 'settings', array() ), false );
			update_option( Config::OPTION_STATE, array_get( $state, 'state', array() ), false );
			update_option( Config::OPTION_RECENT_LOGS, array_get( $state, 'recent_logs', array() ), false );
		} finally {
			Plugin::resume_settings_update_handlers();
		}

		if ( null === $active_plugins ) {
			$active_plugins = array_get( $state, 'active_plugins', array() );
		}

		update_option( 'active_plugins', $this->normalize_active_plugins_for_target( $active_plugins ), false );

		if ( array_key_exists( 'siteurl', $state ) ) {
			update_option( 'siteurl', array_get( $state, 'siteurl', '' ), false );
		}

		if ( array_key_exists( 'home', $state ) ) {
			update_option( 'home', array_get( $state, 'home', '' ), false );
		}

		wp_cache_flush();
	}

	public function sync_active_plugins( array $desired_plugins ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		$current_plugins = array_values(
			array_filter(
				$this->get_active_plugins(),
				array( $this, 'is_not_bridge_plugin_basename' )
			)
		);
		$desired_plugins = array_values(
			array_filter(
				$this->normalize_active_plugins_for_target( $desired_plugins ),
				array( $this, 'is_not_bridge_plugin_basename' )
			)
		);

		$to_deactivate = array_values( array_diff( $current_plugins, $desired_plugins ) );
		if ( ! empty( $to_deactivate ) ) {
			deactivate_plugins( $to_deactivate, false, false );
		}

		foreach ( $desired_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				continue;
			}

			$result = activate_plugin( $plugin, '', false, false );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'ag_sync_bridge_plugin_activation_failed',
					sprintf(
						/* translators: %s: plugin basename */
						__( 'Unable to activate synced plugin: %s', 'ag-sync-bridge' ),
						$plugin
					),
					array(
						'plugin' => $plugin,
						'error'  => $result->get_error_message(),
					)
				);
			}
		}

		$final_plugins = $this->normalize_active_plugins_for_target( $desired_plugins );
		update_option( 'active_plugins', $final_plugins, false );

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		wp_cache_flush();

		return $final_plugins;
	}

	public function replace_urls( array $replacements, $table_prefix = '', array $options = array() ) {
		global $wpdb;
		$progress_callback   = array_get( $options, 'progress_callback', null );
		$cancellation_check  = array_get( $options, 'cancellation_check', null );

		if ( empty( $replacements ) ) {
			return array(
				'tables_scanned' => 0,
				'rows_updated'   => 0,
			);
		}

		$replacements = $this->expand_url_replacements( $replacements );

		uksort(
			$replacements,
			static function ( $left, $right ) {
				return strlen( $right ) <=> strlen( $left );
			}
		);

		$tables       = $wpdb->get_col( 'SHOW TABLES' );
		$tables       = is_array( $tables ) ? $tables : array();

		if ( $table_prefix ) {
			$tables = array_values(
				array_filter(
					$tables,
					static function ( $table ) use ( $table_prefix ) {
						return 0 === strpos( (string) $table, (string) $table_prefix );
					}
				)
			);
		}

		$tables_count = 0;
		$rows_updated = 0;
		$sources      = array_keys( $replacements );

		foreach ( $tables as $table ) {
			$cancelled = $this->check_url_replace_cancellation( $cancellation_check, $table, null );
			if ( is_wp_error( $cancelled ) ) {
				return $cancelled;
			}
			$this->report_url_replace_progress( $progress_callback, $table, 'table-start', $rows_updated );
			$text_columns = $this->get_text_columns( $table );
			if ( empty( $text_columns ) ) {
				continue;
			}

			$tables_count++;
			$fast_columns = $this->get_fast_replace_columns( $table, $text_columns );

			if ( ! empty( $fast_columns ) ) {
				$fast_rows = $this->replace_plain_text_columns( $table, $fast_columns, $replacements, $progress_callback, $cancellation_check );
				if ( is_wp_error( $fast_rows ) ) {
					return $fast_rows;
				}

				$rows_updated += (int) $fast_rows;
				$text_columns  = array_values( array_diff( $text_columns, $fast_columns ) );
			}

			if ( empty( $text_columns ) ) {
				continue;
			}

			$primary_keys = $this->get_primary_keys( $table );
			$single_key   = 1 === count( $primary_keys ) ? reset( $primary_keys ) : '';
			$last_key     = null;
			$batch_size   = self::URL_REPLACE_BATCH_SIZE;

			do {
				$cancelled = $this->check_url_replace_cancellation( $cancellation_check, $table, $last_key );
				if ( is_wp_error( $cancelled ) ) {
					return $cancelled;
				}
				$this->report_url_replace_progress( $progress_callback, $table, 'batch-start', $rows_updated, $last_key );
				$where_sql = $this->build_url_match_sql( $text_columns, $sources );
				if ( is_wp_error( $where_sql ) ) {
					return $where_sql;
				}

				if ( $single_key && null !== $last_key ) {
					$where_sql = $this->quote_identifier( $single_key ) . ' > ' . $this->prepare_sql_value( $last_key ) . ' AND (' . $where_sql . ')';
				}

				$select_columns = array_values( array_unique( array_merge( $primary_keys, $text_columns ) ) );
				$select_sql     = empty( $primary_keys ) ? '*' : implode( ', ', array_map( array( $this, 'quote_identifier' ), $select_columns ) );
				$order_sql      = $single_key ? ' ORDER BY ' . $this->quote_identifier( $single_key ) . ' ASC' : '';
				$rows           = $wpdb->get_results( "SELECT {$select_sql} FROM " . $this->quote_identifier( $table ) . " WHERE {$where_sql}{$order_sql} LIMIT {$batch_size}", ARRAY_A );
				$rows = is_array( $rows ) ? $rows : array();
				$batch_updates = 0;

				foreach ( $rows as $row ) {
					$updates = array();

					foreach ( $text_columns as $column ) {
						if ( ! array_key_exists( $column, $row ) ) {
							continue;
						}

						$changed   = false;
						$new_value = $this->replace_in_database_string( $row[ $column ], $replacements, $changed );

						if ( $changed ) {
							$updates[ $column ] = $new_value;
						}
					}

					if ( empty( $updates ) ) {
						continue;
					}

					$where = array();

					if ( ! empty( $primary_keys ) ) {
						foreach ( $primary_keys as $primary_key ) {
							$where[ $primary_key ] = array_get( $row, $primary_key );
						}
					} else {
						$where = $row;
					}

					$updated = $wpdb->update( $table, $updates, $where );
					if ( false !== $updated ) {
						$rows_updated++;
						$batch_updates++;
					}
				}

				if ( $single_key && ! empty( $rows ) ) {
					$last_row = end( $rows );
					$last_key = array_get( is_array( $last_row ) ? $last_row : array(), $single_key );
				} elseif ( ! $single_key && 0 === $batch_updates ) {
					break;
				}
				$this->report_url_replace_progress( $progress_callback, $table, 'batch-complete', $rows_updated, $last_key );
			} while ( count( $rows ) === $batch_size );
			$this->report_url_replace_progress( $progress_callback, $table, 'table-complete', $rows_updated, $last_key );
		}

		wp_cache_flush();

		return array(
			'tables_scanned' => $tables_count,
			'rows_updated'   => $rows_updated,
		);
	}

	private function report_url_replace_progress( $callback, $table, $phase, $rows_updated, $last_key = null ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, array( 'table' => (string) $table, 'phase' => (string) $phase, 'rows_updated' => (int) $rows_updated, 'last_key' => $last_key ) );
		}
	}

	private function check_url_replace_cancellation( $callback, $table, $last_key ) {
		if ( ! is_callable( $callback ) || ! call_user_func( $callback, array( 'table' => (string) $table, 'last_key' => $last_key ) ) ) {
			return true;
		}
		return new WP_Error( 'ag_sync_bridge_operation_cancelled', __( 'Import cancellation was requested during URL replacement. Restore the pre-import backup before treating the site as healthy.', 'ag-sync-bridge' ), array( 'cancelled' => true, 'rollback_required' => true, 'stage' => 'url_replace' ) );
	}

	private function get_fast_replace_columns( $table, array $text_columns ) {
		$table = strtolower( (string) $table );

		if ( ! preg_match( '/(^|_)mpg_(?:runtime_)?dataset_rows$/', $table ) ) {
			return array();
		}

		return in_array( 'row_data', $text_columns, true ) ? array( 'row_data' ) : array();
	}

	private function replace_plain_text_columns( $table, array $columns, array $replacements, $progress_callback = null, $cancellation_check = null ) {
		global $wpdb;

		$rows_updated = 0;
		$primary_keys = $this->get_primary_keys( $table );
		if ( empty( $primary_keys ) ) {
			return new WP_Error( 'ag_sync_bridge_url_replace_fast_key_missing', __( 'Fast URL replacement requires a primary key.', 'ag-sync-bridge' ), array( 'table' => $table ) );
		}
		$key_select = implode( ', ', array_map( array( $this, 'quote_identifier' ), $primary_keys ) );

		foreach ( $columns as $column ) {
			foreach ( $replacements as $source => $target ) {
				if ( '' === $source || $source === $target ) {
					continue;
				}

				$last_key = null;
				do {
					$cancelled = $this->check_url_replace_cancellation( $cancellation_check, $table, $last_key );
					if ( is_wp_error( $cancelled ) ) {
						return $cancelled;
					}
					$this->report_url_replace_progress( $progress_callback, $table, 'fast-batch-start', $rows_updated, $last_key );
					$where = $this->quote_identifier( $column ) . ' LIKE ' . $this->prepare_sql_value( '%' . $wpdb->esc_like( $source ) . '%' );
					if ( null !== $last_key ) {
						$keyset_where = $this->build_primary_key_after_sql( $primary_keys, $last_key );
						if ( is_wp_error( $keyset_where ) ) {
							return $keyset_where;
						}
						$where = $keyset_where . ' AND (' . $where . ')';
					}
					$key_rows = $wpdb->get_results( 'SELECT ' . $key_select . ' FROM ' . $this->quote_identifier( $table ) . ' WHERE ' . $where . ' ORDER BY ' . $key_select . ' ASC LIMIT ' . self::URL_REPLACE_BATCH_SIZE, ARRAY_A );
					$key_rows = is_array( $key_rows ) ? array_values( $key_rows ) : array();
					if ( empty( $key_rows ) ) {
						break;
					}
					$key_where = $this->build_primary_key_rows_sql( $primary_keys, $key_rows );
					if ( is_wp_error( $key_where ) ) {
						return $key_where;
					}
					$replacement = 'REPLACE(' . $this->quote_identifier( $column ) . ', %s, %s)';
					$assignments = array( $this->quote_identifier( $column ) . ' = ' . $replacement );
					$prepare_values = array( $source, $target );
					if ( $this->is_runtime_dataset_rows_table( $table ) && 'row_data' === $column ) {
						$assignments[] = $this->quote_identifier( 'row_sha256' ) . ' = SHA2(' . $replacement . ', 256)';
						$prepare_values[] = $source;
						$prepare_values[] = $target;
					}
					$sql = $wpdb->prepare( 'UPDATE ' . $this->quote_identifier( $table ) . ' SET ' . implode( ', ', $assignments ) . ' WHERE ' . $key_where, $prepare_values );
					$result = $wpdb->query( $sql );
					if ( false === $result ) {
						return new WP_Error( 'ag_sync_bridge_url_replace_fast_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Fast URL replacement failed.', 'ag-sync-bridge' ), array( 'table' => $table, 'column' => $column ) );
					}
					$rows_updated += (int) $result;
					$last_key = end( $key_rows );
					$this->report_url_replace_progress( $progress_callback, $table, 'fast-batch-complete', $rows_updated, $last_key );
				} while ( count( $key_rows ) === self::URL_REPLACE_BATCH_SIZE );
			}
		}

		if ( $rows_updated > 0 ) {
			$this->logger->info(
				'Fast URL replacement completed.',
				array(
					'table'        => $table,
					'columns'      => array_values( $columns ),
					'rows_updated' => $rows_updated,
				)
			);
		}

		return $rows_updated;
	}

	private function is_runtime_dataset_rows_table( $table ) {
		return (bool) preg_match( '/(^|_)mpg_runtime_dataset_rows$/', strtolower( (string) $table ) );
	}

	private function build_primary_key_after_sql( array $primary_keys, $last_key ) {
		$last_key = is_array( $last_key ) ? $last_key : array( reset( $primary_keys ) => $last_key );
		$branches = array();

		foreach ( array_values( $primary_keys ) as $index => $primary_key ) {
			if ( ! array_key_exists( $primary_key, $last_key ) ) {
				return new WP_Error( 'ag_sync_bridge_url_replace_fast_key_invalid', __( 'Fast URL replacement lost its primary-key checkpoint.', 'ag-sync-bridge' ), array( 'key' => $primary_key ) );
			}
			$parts = array();
			for ( $prefix = 0; $prefix < $index; $prefix++ ) {
				$equal_key = $primary_keys[ $prefix ];
				$parts[] = $this->quote_identifier( $equal_key ) . ' = ' . $this->prepare_sql_value( $last_key[ $equal_key ] );
			}
			$parts[] = $this->quote_identifier( $primary_key ) . ' > ' . $this->prepare_sql_value( $last_key[ $primary_key ] );
			$branches[] = '(' . implode( ' AND ', $parts ) . ')';
		}

		return '(' . implode( ' OR ', $branches ) . ')';
	}

	private function build_primary_key_rows_sql( array $primary_keys, array $key_rows ) {
		$rows = array();

		foreach ( $key_rows as $key_row ) {
			if ( ! is_array( $key_row ) ) {
				return new WP_Error( 'ag_sync_bridge_url_replace_fast_key_invalid', __( 'Fast URL replacement received an invalid primary-key row.', 'ag-sync-bridge' ) );
			}
			$parts = array();
			foreach ( $primary_keys as $primary_key ) {
				if ( ! array_key_exists( $primary_key, $key_row ) ) {
					return new WP_Error( 'ag_sync_bridge_url_replace_fast_key_invalid', __( 'Fast URL replacement received an incomplete primary-key row.', 'ag-sync-bridge' ), array( 'key' => $primary_key ) );
				}
				$parts[] = $this->quote_identifier( $primary_key ) . ' = ' . $this->prepare_sql_value( $key_row[ $primary_key ] );
			}
			$rows[] = '(' . implode( ' AND ', $parts ) . ')';
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'ag_sync_bridge_url_replace_fast_key_empty', __( 'Fast URL replacement received an empty primary-key batch.', 'ag-sync-bridge' ) );
		}

		return '(' . implode( ' OR ', $rows ) . ')';
	}

	private function build_url_match_sql( array $columns, array $sources ) {
		global $wpdb;

		$conditions = array();
		$values     = array();

		foreach ( $columns as $column ) {
			foreach ( $sources as $source ) {
				if ( '' === $source ) {
					continue;
				}

				$conditions[] = $this->quote_identifier( $column ) . ' LIKE %s';
				$values[]     = '%' . $wpdb->esc_like( $source ) . '%';
			}
		}

		if ( empty( $conditions ) ) {
			return new WP_Error( 'ag_sync_bridge_url_replace_empty_match', __( 'URL replacement has no searchable source values.', 'ag-sync-bridge' ) );
		}

		return $wpdb->prepare( '(' . implode( ' OR ', $conditions ) . ')', $values );
	}

	private function expand_url_replacements( array $replacements ) {
		$expanded = array();

		foreach ( $replacements as $source => $target ) {
			$source = (string) $source;
			$target = (string) $target;

			if ( '' === $source ) {
				continue;
			}

			$expanded[ $source ] = $target;

			$escaped_source = str_replace( '/', '\\/', $source );
			$escaped_target = str_replace( '/', '\\/', $target );

			if ( $escaped_source !== $source ) {
				$expanded[ $escaped_source ] = $escaped_target;
			}
		}

		return $expanded;
	}

	public function remap_site_prefix_keys( $source_prefix, $target_prefix ) {
		global $wpdb;

		$source_prefix = (string) $source_prefix;
		$target_prefix = (string) $target_prefix;

		if ( '' === $source_prefix || '' === $target_prefix || $source_prefix === $target_prefix ) {
			return array(
				'options_renamed'  => 0,
				'usermeta_renamed' => 0,
			);
		}

		$options_renamed  = 0;
		$usermeta_renamed = 0;

		if ( $this->table_exists( $wpdb->options ) ) {
			$renamed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET option_name = %s WHERE option_name = %s",
					$target_prefix . 'user_roles',
					$source_prefix . 'user_roles'
				)
			);

			if ( false === $renamed ) {
				return new WP_Error( 'ag_sync_bridge_option_prefix_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Unable to remap prefixed options.', 'ag-sync-bridge' ) );
			}

			$options_renamed += (int) $renamed;
		}

		if ( $this->table_exists( $wpdb->usermeta ) ) {
			$like    = $wpdb->esc_like( $source_prefix ) . '%';
			$renamed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->usermeta}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
					$target_prefix,
					strlen( $source_prefix ) + 1,
					$like
				)
			);

			if ( false === $renamed ) {
				return new WP_Error( 'ag_sync_bridge_usermeta_prefix_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Unable to remap prefixed usermeta keys.', 'ag-sync-bridge' ) );
			}

			$usermeta_renamed += (int) $renamed;
		}

		if ( $options_renamed || $usermeta_renamed ) {
			$this->logger->info(
				'Remapped prefixed WordPress keys after import.',
				array(
					'source_prefix'   => $source_prefix,
					'target_prefix'   => $target_prefix,
					'options_renamed' => $options_renamed,
					'usermeta_renamed'=> $usermeta_renamed,
				)
			);
		}

		return array(
			'options_renamed'  => $options_renamed,
			'usermeta_renamed' => $usermeta_renamed,
		);
	}

	private function export_via_cli( $file_path, $progress_callback = null, $cancellation_check = null ) {
		$binary = $this->locate_binary( 'mysqldump' );
		if ( ! $binary ) {
			return new WP_Error( 'ag_sync_bridge_mysqldump_missing', __( 'mysqldump binary not found.', 'ag-sync-bridge' ) );
		}

		$charset = $this->get_mysql_charset();
		$args = $this->build_mysql_command_arguments( $binary );
		array_splice(
			$args,
			1,
			0,
			array(
				'--single-transaction',
				'--hex-blob',
				'--skip-comments',
				'--add-drop-table',
				'--no-tablespaces',
				'--skip-extended-insert',
				'--max-allowed-packet=' . self::IMPORT_MAX_ALLOWED_PACKET,
				'--default-character-set=' . $charset,
			)
		);

		$tables = $this->get_export_tables();
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}

		$command     = $this->build_shell_command( array_merge( $args, array( DB_NAME ), $tables ) );
		$descriptors = array(
			1 => array( 'file', $file_path, 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = proc_open( $command, $descriptors, $pipes, ABSPATH );

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'ag_sync_bridge_mysqldump_process', __( 'Unable to start mysqldump.', 'ag-sync-bridge' ) );
		}

		$completed = $this->wait_for_process( $process, $pipes, $progress_callback, 'database-export-cli', $cancellation_check );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}
		$exit_code = $completed['exit_code'];
		$error_output = array_get( $completed['output'], 2, '' );

		if ( 0 !== $exit_code ) {
			return new WP_Error( 'ag_sync_bridge_mysqldump_failed', trim( (string) $error_output ) ?: __( 'mysqldump failed.', 'ag-sync-bridge' ) );
		}

		return true;
	}

	private function import_via_cli( $file_path, $progress_callback = null ) {
		$binary = $this->locate_binary( 'mysql' );
		if ( ! $binary ) {
			return new WP_Error( 'ag_sync_bridge_mysql_missing', __( 'mysql binary not found.', 'ag-sync-bridge' ) );
		}

		if ( ! defined( 'AG_SYNC_BRIDGE_ALLOW_GLOBAL_MYSQL_LIMITS' ) || AG_SYNC_BRIDGE_ALLOW_GLOBAL_MYSQL_LIMITS ) {
			$this->prepare_mysql_import_limits();
		}

		$args = $this->build_mysql_command_arguments( $binary );
		array_splice(
			$args,
			1,
			0,
			array(
				'--default-character-set=' . $this->get_mysql_charset(),
				'--binary-mode',
				'--init-command=' . $this->get_mysql_import_init_command(),
				'--max-allowed-packet=' . self::IMPORT_MAX_ALLOWED_PACKET,
			)
		);
		$args[] = DB_NAME;

		$command     = $this->build_shell_command( $args );
		$descriptors = array(
			0 => array( 'file', $file_path, 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = proc_open( $command, $descriptors, $pipes, ABSPATH );

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'ag_sync_bridge_mysql_process', __( 'Unable to start mysql import.', 'ag-sync-bridge' ) );
		}

		$completed = $this->wait_for_process( $process, $pipes, $progress_callback, 'database-import-cli' );
		$exit_code = $completed['exit_code'];
		$stdout = array_get( $completed['output'], 1, '' );
		$stderr = array_get( $completed['output'], 2, '' );

		if ( 0 !== $exit_code ) {
			return new WP_Error( 'ag_sync_bridge_mysql_failed', trim( $stderr ?: $stdout ) ?: __( 'mysql import failed.', 'ag-sync-bridge' ) );
		}

		return true;
	}

	private function wait_for_process( $process, array $pipes, $progress_callback, $stage, $cancellation_check = null ) {
		$output      = array();
		$exit_code   = -1;
		$last_report = 0.0;
		$cancelled   = false;

		foreach ( $pipes as $index => $pipe ) {
			if ( is_resource( $pipe ) ) {
				stream_set_blocking( $pipe, false );
				$output[ $index ] = '';
			}
		}

		while ( true ) {
			if ( is_callable( $cancellation_check ) && call_user_func( $cancellation_check, $stage, false ) ) {
				$cancelled = true;
				proc_terminate( $process );
				break;
			}

			foreach ( $pipes as $index => $pipe ) {
				if ( is_resource( $pipe ) ) {
					$chunk = stream_get_contents( $pipe );
					if ( false !== $chunk && '' !== $chunk ) {
						$output[ $index ] .= $chunk;
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! is_array( $status ) || empty( $status['running'] ) ) {
				if ( is_array( $status ) && isset( $status['exitcode'] ) && (int) $status['exitcode'] >= 0 ) {
					$exit_code = (int) $status['exitcode'];
				}
				break;
			}

			if ( is_callable( $progress_callback ) && ( microtime( true ) - $last_report ) >= 5 ) {
				call_user_func( $progress_callback, $stage, null, array( 'pid' => (int) array_get( $status, 'pid', 0 ) ) );
				$last_report = microtime( true );
			}
			usleep( 250000 );
		}

		foreach ( $pipes as $index => $pipe ) {
			if ( is_resource( $pipe ) ) {
				$chunk = stream_get_contents( $pipe );
				if ( false !== $chunk && '' !== $chunk ) {
					$output[ $index ] .= $chunk;
				}
				fclose( $pipe );
			}
		}

		$closed = proc_close( $process );
		if ( $exit_code < 0 ) {
			$exit_code = (int) $closed;
		}
		if ( $cancelled ) {
			return new WP_Error(
				'ag_sync_bridge_operation_cancelled',
				__( 'Database export was cancelled before the target changed.', 'ag-sync-bridge' ),
				array( 'cancelled' => true, 'rollback_required' => false, 'stage' => sanitize_key( $stage ) )
			);
		}
		return array( 'exit_code' => $exit_code, 'output' => $output );
	}

	private function prepare_mysql_import_limits() {
		$mysqli = $this->connect();
		if ( is_wp_error( $mysqli ) ) {
			$this->logger->warning( 'Unable to prepare MySQL import limits.', array( 'error' => $mysqli->get_error_message() ) );
			return false;
		}

		$limits = array(
			'max_allowed_packet' => self::IMPORT_MAX_ALLOWED_PACKET,
			'net_read_timeout'   => self::IMPORT_NET_TIMEOUT,
			'net_write_timeout'  => self::IMPORT_NET_TIMEOUT,
		);

		foreach ( $limits as $name => $value ) {
			if ( ! $mysqli->query( 'SET GLOBAL ' . $name . ' = ' . absint( $value ) ) ) {
				$this->logger->warning(
					'Unable to update MySQL import limit.',
					array(
						'variable' => $name,
						'value'    => $value,
						'error'    => $mysqli->error,
					)
				);
			}
		}

		$mysqli->close();
		return true;
	}

	private function export_via_php( $file_path, $progress_callback = null, $cancellation_check = null ) {
		global $wpdb;

		$mysqli = $this->connect();
		if ( is_wp_error( $mysqli ) ) {
			return $mysqli;
		}

		$handle = fopen( $file_path, 'wb' );
		if ( ! $handle ) {
			$mysqli->close();
			return new WP_Error( 'ag_sync_bridge_sql_open', __( 'Unable to open SQL export file for writing.', 'ag-sync-bridge' ) );
		}

		fwrite( $handle, "-- AG Sync Bridge database export\n" );
		fwrite( $handle, '-- Exported: ' . gmdate( 'c' ) . "\n\n" );
		fwrite( $handle, 'SET NAMES ' . $this->get_mysql_charset() . ";\n" );

		if ( $this->get_mysql_collation() ) {
			fwrite( $handle, "SET collation_connection = '" . $this->get_mysql_collation() . "';\n" );
		}

		fwrite( $handle, "\n" );

		$tables = $this->get_export_tables();
		if ( is_wp_error( $tables ) ) {
			fclose( $handle );
			$mysqli->close();
			return $tables;
		}

		foreach ( $tables as $table ) {
			if ( is_callable( $cancellation_check ) && call_user_func( $cancellation_check, 'database-export-php', false ) ) {
				fclose( $handle );
				$mysqli->close();
				return new WP_Error( 'ag_sync_bridge_operation_cancelled', __( 'Database export was cancelled before the target changed.', 'ag-sync-bridge' ), array( 'cancelled' => true, 'rollback_required' => false, 'stage' => 'database-export-php' ) );
			}
			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, 'database-export-php', null, array( 'table' => $table ) );
			}
			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( empty( $create_row[1] ) ) {
				continue;
			}

			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create_row[1] . ";\n\n" );

			$result = $this->export_table_rows_via_php( $mysqli, $handle, $table, $progress_callback, $cancellation_check );
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
				$mysqli->close();
				return $result;
			}

			fwrite( $handle, "\n\n" );
		}

		fclose( $handle );
		$mysqli->close();

		return true;
	}

	private function export_table_rows_via_php( &$mysqli, $handle, $table, $progress_callback = null, $cancellation_check = null ) {
		global $wpdb;

		$batch_size  = 100;
		$offset      = 0;
		$order_by    = $this->get_export_order_clause( $table );
		$table_name  = $this->quote_identifier( $table );
		$skip_option_transients = isset( $wpdb->options ) && strtolower( $table ) === strtolower( $wpdb->options );
		$last_report = microtime( true );

		while ( true ) {
			if ( is_callable( $cancellation_check ) && call_user_func( $cancellation_check, 'database-export-php', false ) ) {
				return new WP_Error( 'ag_sync_bridge_operation_cancelled', __( 'Database export was cancelled before the target changed.', 'ag-sync-bridge' ), array( 'cancelled' => true, 'rollback_required' => false, 'stage' => 'database-export-php' ) );
			}
			if ( is_callable( $progress_callback ) && ( microtime( true ) - $last_report ) >= 5 ) {
				call_user_func( $progress_callback, 'database-export-php', null, array( 'table' => $table, 'rows_processed' => $offset ) );
				$last_report = microtime( true );
			}
			$query  = "SELECT * FROM {$table_name}{$order_by} LIMIT {$batch_size} OFFSET {$offset}";
			$result = $this->mysqli_query_with_reconnect( $mysqli, $query );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$fields = $result->fetch_fields();
			if ( empty( $fields ) ) {
				$result->free();
				return true;
			}

			$column_names = array();
			foreach ( $fields as $field ) {
				$column_names[] = $this->quote_identifier( $field->name );
			}

			$batch       = array();
			$batch_bytes = 0;
			$row_count   = 0;

			while ( $row = $result->fetch_assoc() ) {
				$row_count++;

				if ( $skip_option_transients && isset( $row['option_name'] ) && $this->is_skipped_option_name( $row['option_name'] ) ) {
					continue;
				}

				$values = array();

				foreach ( $fields as $field ) {
					$value = $row[ $field->name ];
					$values[] = $this->format_php_export_value( $mysqli, $field, $value );
				}

				$row_sql       = '(' . implode( ',', $values ) . ')';
				$batch[]       = $row_sql;
				$batch_bytes  += strlen( $row_sql );

				if ( count( $batch ) >= 100 || $batch_bytes >= 1048576 ) {
					$write = $this->write_insert_batch( $handle, $table, $column_names, $batch );
					if ( is_wp_error( $write ) ) {
						$result->free();
						return $write;
					}

					$batch       = array();
					$batch_bytes = 0;
				}
			}

			if ( ! empty( $batch ) ) {
				$write = $this->write_insert_batch( $handle, $table, $column_names, $batch );
				if ( is_wp_error( $write ) ) {
					$result->free();
					return $write;
				}
			}

			$result->free();

			if ( $row_count < $batch_size ) {
				return true;
			}

			$offset += $batch_size;
		}
	}

	private function format_php_export_value( $mysqli, $field, $value ) {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( $this->is_binary_mysql_field( $field ) ) {
			$hex = strtoupper( bin2hex( (string) $value ) );
			return '' === $hex ? "X''" : '0x' . $hex;
		}

		return "'" . $mysqli->real_escape_string( (string) $value ) . "'";
	}

	private function is_binary_mysql_field( $field ) {
		$type = isset( $field->type ) ? (int) $field->type : -1;
		if ( in_array( $type, array( 249, 250, 251, 252 ), true ) ) {
			return true;
		}

		$binary_charset = isset( $field->charsetnr ) && 63 === (int) $field->charsetnr;
		return $binary_charset && in_array( $type, array( 15, 253, 254 ), true );
	}

	private function write_insert_batch( $handle, $table, array $column_names, array $batch ) {
		if ( empty( $batch ) ) {
			return true;
		}

		$sql = 'INSERT INTO ' . $this->quote_identifier( $table ) . ' (' . implode( ',', $column_names ) . ") VALUES\n" . implode( ",\n", $batch ) . ";\n";

		if ( false === fwrite( $handle, $sql ) ) {
			return new WP_Error( 'ag_sync_bridge_sql_write_failed', __( 'Unable to write SQL export file.', 'ag-sync-bridge' ) );
		}

		return true;
	}

	private function mysqli_query_with_reconnect( &$mysqli, $query ) {
		$result = $mysqli->query( $query );
		if ( false !== $result ) {
			return $result;
		}

		$error = $mysqli->error;
		$errno = (int) $mysqli->errno;

		if ( ! $this->is_mysql_connection_error( $errno, $error ) ) {
			return new WP_Error( 'ag_sync_bridge_sql_export_query', $error );
		}

		$this->logger->warning(
			'MySQL connection lost during PHP database export. Reconnecting and retrying query.',
			array(
				'errno' => $errno,
				'error' => $error,
			)
		);

		@$mysqli->close();
		$mysqli = $this->connect();
		if ( is_wp_error( $mysqli ) ) {
			return $mysqli;
		}

		$result = $mysqli->query( $query );
		if ( false !== $result ) {
			return $result;
		}

		return new WP_Error( 'ag_sync_bridge_sql_export_query', $mysqli->error ? $mysqli->error : $error );
	}

	private function is_mysql_connection_error( $errno, $error ) {
		if ( in_array( (int) $errno, array( 2006, 2013, 2055 ), true ) ) {
			return true;
		}

		$error = strtolower( (string) $error );
		return false !== strpos( $error, 'server has gone away' ) || false !== strpos( $error, 'lost connection' );
	}

	private function get_export_order_clause( $table ) {
		$keys = $this->get_primary_keys( $table );
		if ( empty( $keys ) ) {
			return '';
		}

		$quoted = array();
		foreach ( $keys as $key ) {
			$quoted[] = $this->quote_identifier( $key );
		}

		return ' ORDER BY ' . implode( ',', $quoted );
	}

	private function import_via_php( $file_path, $target_prefix = '', $progress_callback = null, $expected_size_bytes = 0 ) {
		global $wpdb;

		$charset = $this->get_mysql_charset();
		$wpdb->query( 'SET NAMES ' . $charset );

		if ( $this->get_mysql_collation() ) {
			$wpdb->query( "SET collation_connection = '" . esc_sql( $this->get_mysql_collation() ) . "'" );
		}

		$handle = $this->open_import_stream_at_offset( $file_path, 0 );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$statement = '';
		$in_string = false;
		$quote     = '';
		$last_report = microtime( true );
		$bytes_processed = 0;
		$stream_reopens = 0;

		while ( true ) {
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$bytes_processed += strlen( $line );
			if ( is_callable( $progress_callback ) && ( microtime( true ) - $last_report ) >= 5 ) {
				call_user_func( $progress_callback, 'database-import-php', null, array( 'bytes_processed' => $bytes_processed, 'stream_reopens' => $stream_reopens ) );
				$last_report = microtime( true );
			}
			$trimmed = trim( $line );

			if ( ! $in_string && '' === $trimmed ) {
				continue;
			}

			if ( ! $in_string && ( 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '#' ) || ( 0 === strpos( $trimmed, '/*' ) && 0 !== strpos( $trimmed, '/*!' ) ) ) ) {
				continue;
			}

			$length = strlen( $line );

			for ( $index = 0; $index < $length; $index++ ) {
				$char      = $line[ $index ];
				$statement .= $char;
				$prev_char = $index > 0 ? $line[ $index - 1 ] : '';
				$next_char = ( $index + 1 < $length ) ? $line[ $index + 1 ] : '';

				if ( $in_string ) {
					if ( $char === $quote && ! $this->is_sql_quote_escaped( $line, $index ) && $quote !== $next_char ) {
						$in_string = false;
						$quote     = '';
					}
					continue;
				}

				if ( '\'' === $char || '"' === $char ) {
					$in_string = true;
					$quote     = $char;
					continue;
				}

				if ( ';' === $char ) {
					$query = trim( $statement );
					$statement = '';

					if ( '' === $query ) {
						continue;
					}

					$filtered = $this->filter_import_statement( $query, $target_prefix );
					$query    = array_get( $filtered, 'statement', $query );
					$query    = $this->normalize_empty_hex_literals( $query );

					if ( '' === trim( $query ) ) {
						continue;
					}

					$result = $wpdb->query( $query );
					if ( false === $result ) {
						// Capture the connection-level error before UNLOCK TABLES runs
						// another query and can replace wpdb's diagnostic state.
						$connection_errno = 0;
						$connection_error = '';
						if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) ) {
							$connection_errno = isset( $wpdb->dbh->errno ) ? (int) $wpdb->dbh->errno : 0;
							$connection_error = isset( $wpdb->dbh->error ) ? (string) $wpdb->dbh->error : '';
						}
						$import_error = $wpdb->last_error ? (string) $wpdb->last_error : $connection_error;
						// A dump can leave the connection inside LOCK TABLES when a
						// later statement fails. Release it before the caller performs
						// environment restore or recovery queries on other tables.
						$wpdb->query( 'UNLOCK TABLES' );
						fclose( $handle );
						return new WP_Error(
							'ag_sync_bridge_import_query_failed',
							$import_error ? $import_error : __( 'Database import query failed.', 'ag-sync-bridge' ),
							array(
								'query'       => substr( $query, 0, 500 ),
								'mysql_errno' => $connection_errno,
								'mysql_error' => $connection_error,
							)
						);
					}
				}
			}
		}

			if ( $expected_size_bytes > 0 && $bytes_processed < $expected_size_bytes ) {
				fclose( $handle );
				if ( $stream_reopens >= 6 ) {
					return new WP_Error(
						'ag_sync_bridge_import_stream_truncated',
						__( 'The verified database stream ended early after repeated resume attempts.', 'ag-sync-bridge' ),
						array(
							'bytes_processed' => $bytes_processed,
							'expected_size_bytes' => $expected_size_bytes,
							'stream_reopens' => $stream_reopens,
						)
					);
				}
				$handle = $this->open_import_stream_at_offset( $file_path, $bytes_processed );
				if ( is_wp_error( $handle ) ) {
					return $handle;
				}
				$stream_reopens++;
				$this->logger->warning(
					'Resuming a verified database ZIP stream after an early EOF.',
					array(
						'bytes_processed' => $bytes_processed,
						'expected_size_bytes' => $expected_size_bytes,
						'stream_reopens' => $stream_reopens,
					)
				);
				continue;
			}
			break;
		}

		$incomplete_statement = '' !== trim( $statement ) || $in_string;
		fclose( $handle );
		if ( $incomplete_statement ) {
			$wpdb->query( 'UNLOCK TABLES' );
			return new WP_Error(
				'ag_sync_bridge_import_incomplete_statement',
				__( 'SQL import ended with an incomplete statement.', 'ag-sync-bridge' ),
				array( 'query' => substr( trim( $statement ), 0, 500 ) )
			);
		}
		$unlocked = $wpdb->query( 'UNLOCK TABLES' );
		if ( false === $unlocked ) {
			return new WP_Error(
				'ag_sync_bridge_import_unlock_failed',
				$wpdb->last_error ? $wpdb->last_error : __( 'Unable to release database table locks after the PHP import.', 'ag-sync-bridge' )
			);
		}
		return true;
	}

	private function open_import_stream_at_offset( $file_path, $offset ) {
		$attempt_limit = 0 === strpos( (string) $file_path, 'zip://' ) ? 6 : 1;
		$offset        = max( 0, (int) $offset );

		for ( $attempt = 1; $attempt <= $attempt_limit; $attempt++ ) {
			$handle = fopen( $file_path, 'rb' );
			if ( ! $handle ) {
				continue;
			}

			$remaining = $offset;
			while ( $remaining > 0 ) {
				$chunk = fread( $handle, min( 8388608, $remaining ) );
				if ( false === $chunk || '' === $chunk ) {
					fclose( $handle );
					continue 2;
				}
				$remaining -= strlen( $chunk );
			}

			return $handle;
		}

		return new WP_Error(
			'ag_sync_bridge_import_stream_resume_failed',
			__( 'Unable to open the verified SQL stream at the required resume offset.', 'ag-sync-bridge' ),
			array(
				'offset' => $offset,
				'attempts' => $attempt_limit,
			)
		);
	}

	/**
	 * Repair the bare `0x` token emitted for zero-length BLOB/TEXT values by
	 * older PHP-export snapshots. Only standalone value tokens outside quoted
	 * strings or identifiers are changed; valid non-empty hex literals remain
	 * byte-for-byte unchanged.
	 */
	private function normalize_empty_hex_literals( $statement ) {
		$length    = strlen( (string) $statement );
		$output    = '';
		$in_quote  = false;
		$quote     = '';

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $statement[ $index ];

			if ( $in_quote ) {
				$output .= $char;
				$next_char = ( $index + 1 < $length ) ? $statement[ $index + 1 ] : '';
				if ( $char === $quote && ! $this->is_sql_quote_escaped( $statement, $index ) && $quote !== $next_char ) {
					$in_quote = false;
					$quote    = '';
				}
				continue;
			}

			if ( in_array( $char, array( '\'', '"', '`' ), true ) ) {
				$in_quote = true;
				$quote    = $char;
				$output  .= $char;
				continue;
			}

			if ( '0' === $char && $index + 1 < $length && in_array( $statement[ $index + 1 ], array( 'x', 'X' ), true ) ) {
				$previous = $index - 1;
				while ( $previous >= 0 && ctype_space( $statement[ $previous ] ) ) {
					$previous--;
				}
				$next = $index + 2;
				while ( $next < $length && ctype_space( $statement[ $next ] ) ) {
					$next++;
				}
				$previous_char = $previous >= 0 ? $statement[ $previous ] : '';
				$next_char     = $next < $length ? $statement[ $next ] : '';
				if ( in_array( $previous_char, array( '(', ',' ), true ) && in_array( $next_char, array( ',', ')' ), true ) ) {
					$output .= "X''";
					$index++;
					continue;
				}
			}

			$output .= $char;
		}

		return $output;
	}

	private function get_export_tables() {
		global $wpdb;

		$prefix = (string) $wpdb->prefix;
		if ( '' === $prefix || ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			return new WP_Error(
				'ag_sync_bridge_export_prefix_invalid',
				__( 'The active WordPress table prefix is invalid.', 'ag-sync-bridge' )
			);
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$tables = is_array( $tables ) ? array_values( array_filter(
			$tables,
			static function ( $table ) use ( $prefix ) {
				return 0 === strpos( (string) $table, $prefix );
			}
		) ) : array();

		if ( empty( $tables ) || ! in_array( $wpdb->options, $tables, true ) ) {
			return new WP_Error(
				'ag_sync_bridge_export_tables_missing',
				__( 'No complete active-prefix WordPress table set was found for export.', 'ag-sync-bridge' )
			);
		}

		sort( $tables, SORT_STRING );
		return $tables;
	}

	private function prepare_sql_for_import( $file_path, $source_prefix, $target_prefix, $progress_callback = null ) {
		$temp_dir = $this->config->get_data_dir( 'temp' );
		ensure_directory( $temp_dir );

		$destination = normalize_path( $temp_dir . '/import-prefix-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.sql' );
		$source      = fopen( $file_path, 'rb' );

		if ( ! $source ) {
			return new WP_Error( 'ag_sync_bridge_sql_rewrite_read', __( 'Unable to read SQL file for prefix rewrite.', 'ag-sync-bridge' ) );
		}

		$target = fopen( $destination, 'wb' );
		if ( ! $target ) {
			fclose( $source );
			return new WP_Error( 'ag_sync_bridge_sql_rewrite_write', __( 'Unable to create rewritten SQL file.', 'ag-sync-bridge' ) );
		}

		$should_remap = ( $source_prefix && $target_prefix && $source_prefix !== $target_prefix );
		$pattern      = $should_remap ? '/`' . preg_quote( $source_prefix, '/' ) . '([A-Za-z0-9_]+)`/' : '';
		$removed      = 0;
		$transients   = 0;
		$statement    = '';
		$in_string    = false;
		$quote        = '';
		$last_report  = microtime( true );

		try {
			while ( false !== ( $line = fgets( $source ) ) ) {
				if ( is_callable( $progress_callback ) && ( microtime( true ) - $last_report ) >= 5 ) {
					call_user_func( $progress_callback, 'database-prepare', null, array( 'bytes_processed' => (int) ftell( $source ) ) );
					$last_report = microtime( true );
				}
				if ( $this->is_mariadb_sandbox_comment( $line ) ) {
					$removed++;
					continue;
				}

				if ( $should_remap ) {
					$line = preg_replace( $pattern, '`' . $target_prefix . '$1`', $line );
				}

				if ( '' === $statement && $this->is_passthrough_sql_line( $line ) ) {
					fwrite( $target, $line );
					continue;
				}

				$length = strlen( $line );
				for ( $index = 0; $index < $length; $index++ ) {
					$char       = $line[ $index ];
					$statement .= $char;
					$prev_char  = $index > 0 ? $line[ $index - 1 ] : '';
					$next_char  = ( $index + 1 < $length ) ? $line[ $index + 1 ] : '';

					if ( $in_string ) {
						if ( $char === $quote && ! $this->is_sql_quote_escaped( $line, $index ) && $quote !== $next_char ) {
							$in_string = false;
							$quote     = '';
						}
						continue;
					}

					if ( '\'' === $char || '"' === $char ) {
						$in_string = true;
						$quote     = $char;
						continue;
					}

					if ( ';' === $char ) {
						$filtered   = $this->filter_import_statement( $statement, $target_prefix );
						$statement  = array_get( $filtered, 'statement', $statement );
						$transients += (int) array_get( $filtered, 'transient_rows_removed', 0 );

						if ( '' !== trim( $statement ) ) {
							fwrite( $target, $statement );
							if ( "\n" !== substr( $statement, -1 ) ) {
								fwrite( $target, "\n" );
							}
						}

						$statement = '';
					}
				}
			}

			if ( '' !== trim( $statement ) ) {
				$filtered   = $this->filter_import_statement( $statement, $target_prefix );
				$statement  = array_get( $filtered, 'statement', $statement );
				$transients += (int) array_get( $filtered, 'transient_rows_removed', 0 );

				if ( '' !== trim( $statement ) ) {
					fwrite( $target, $statement );
				}
			}
		} finally {
			fclose( $source );
			fclose( $target );
		}

		return array(
			'path'                  => $destination,
			'cleanup_path'          => $destination,
			'prefix_remapped'       => $should_remap,
			'sandbox_lines_removed' => $removed,
			'transient_rows_removed'=> $transients,
		);
	}

	private function is_passthrough_sql_line( $line ) {
		$trimmed = trim( (string) $line );

		if ( '' === $trimmed ) {
			return true;
		}

		if ( 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '#' ) ) {
			return true;
		}

		return 0 === strpos( $trimmed, '/*' ) && 0 !== strpos( $trimmed, '/*!' );
	}

	private function filter_import_statement( $statement, $target_prefix ) {
		$parsed = $this->parse_options_insert_statement( $statement, $target_prefix );

		if ( empty( $parsed ) ) {
			return array(
				'statement'              => $statement,
				'transient_rows_removed' => 0,
			);
		}

		$rows = $this->split_sql_value_rows( $parsed['values_sql'] );
		if ( empty( $rows ) ) {
			return array(
				'statement'              => $statement,
				'transient_rows_removed' => 0,
			);
		}

		$kept    = array();
		$removed = 0;

		foreach ( $rows as $row ) {
			$values      = $this->split_sql_row_values( $row );
			$option_name = array_key_exists( $parsed['option_name_index'], $values ) ? $this->parse_sql_string_literal( $values[ $parsed['option_name_index'] ] ) : '';

			if ( $this->is_skipped_option_name( $option_name ) ) {
				$removed++;
				continue;
			}

			$kept[] = $row;
		}

		if ( 0 === $removed ) {
			return array(
				'statement'              => $statement,
				'transient_rows_removed' => 0,
			);
		}

		return array(
			'statement'              => empty( $kept ) ? '' : rtrim( $parsed['head'] ) . "\n" . implode( ",\n", $kept ) . ";\n",
			'transient_rows_removed' => $removed,
		);
	}

	private function parse_options_insert_statement( $statement, $target_prefix ) {
		global $wpdb;

		if ( ! preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([^`\s(]+)`?/i', $statement, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$table_name     = $matches[1][0];
		$options_table  = $target_prefix ? $target_prefix . 'options' : $wpdb->options;

		if ( strtolower( $table_name ) !== strtolower( $options_table ) ) {
			return array();
		}

		$values_pos = $this->find_sql_keyword_position( $statement, 'VALUES' );
		if ( false === $values_pos ) {
			return array();
		}

		$table_match_end   = $matches[0][1] + strlen( $matches[0][0] );
		$between_table_and_values = substr( $statement, $table_match_end, $values_pos - $table_match_end );
		$option_name_index = 1;

		if ( preg_match( '/^\s*\((.*)\)\s*$/s', $between_table_and_values, $column_matches ) ) {
			$columns = array_map( 'trim', explode( ',', $column_matches[1] ) );
			foreach ( $columns as $index => $column ) {
				$column = strtolower( trim( $column, "` \t\n\r\0\x0B" ) );
				if ( 'option_name' === $column ) {
					$option_name_index = $index;
					break;
				}
			}
		}

		$values_sql = trim( substr( $statement, $values_pos + 6 ) );
		$values_sql = preg_replace( '/;\s*$/', '', $values_sql );

		return array(
			'head'              => substr( $statement, 0, $values_pos + 6 ),
			'values_sql'        => $values_sql,
			'option_name_index' => $option_name_index,
		);
	}

	private function find_sql_keyword_position( $sql, $keyword ) {
		$length    = strlen( $sql );
		$needle    = strtoupper( $keyword );
		$in_string = false;
		$quote     = '';

		for ( $index = 0; $index < $length; $index++ ) {
			$char      = $sql[ $index ];
			$prev_char = $index > 0 ? $sql[ $index - 1 ] : '';
			$next_char = ( $index + 1 < $length ) ? $sql[ $index + 1 ] : '';

			if ( $in_string ) {
				if ( $char === $quote && ! $this->is_sql_quote_escaped( $sql, $index ) && $quote !== $next_char ) {
					$in_string = false;
					$quote     = '';
				}
				continue;
			}

			if ( '\'' === $char || '"' === $char ) {
				$in_string = true;
				$quote     = $char;
				continue;
			}

			if ( strtoupper( substr( $sql, $index, strlen( $needle ) ) ) === $needle ) {
				$before = $index > 0 ? $sql[ $index - 1 ] : ' ';
				$after  = ( $index + strlen( $needle ) < $length ) ? $sql[ $index + strlen( $needle ) ] : ' ';
				if ( ! preg_match( '/[A-Za-z0-9_]/', $before ) && ! preg_match( '/[A-Za-z0-9_]/', $after ) ) {
					return $index;
				}
			}
		}

		return false;
	}

	private function split_sql_value_rows( $values_sql ) {
		$rows      = array();
		$current   = '';
		$started   = false;
		$depth     = 0;
		$in_string = false;
		$quote     = '';
		$length    = strlen( $values_sql );

		for ( $index = 0; $index < $length; $index++ ) {
			$char      = $values_sql[ $index ];
			$prev_char = $index > 0 ? $values_sql[ $index - 1 ] : '';
			$next_char = ( $index + 1 < $length ) ? $values_sql[ $index + 1 ] : '';

			if ( ! $started ) {
				if ( ctype_space( $char ) || ',' === $char ) {
					continue;
				}

				if ( '(' !== $char ) {
					return array();
				}

				$started = true;
				$depth   = 1;
				$current = '(';
				continue;
			}

			$current .= $char;

			if ( $in_string ) {
				if ( $char === $quote && ! $this->is_sql_quote_escaped( $values_sql, $index ) && $quote !== $next_char ) {
					$in_string = false;
					$quote     = '';
				}
				continue;
			}

			if ( '\'' === $char || '"' === $char ) {
				$in_string = true;
				$quote     = $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					$rows[]  = trim( $current );
					$current = '';
					$started = false;
				}
			}
		}

		return $rows;
	}

	private function split_sql_row_values( $row ) {
		$row = trim( $row );
		if ( '(' === substr( $row, 0, 1 ) && ')' === substr( $row, -1 ) ) {
			$row = substr( $row, 1, -1 );
		}

		$values    = array();
		$current   = '';
		$in_string = false;
		$quote     = '';
		$length    = strlen( $row );

		for ( $index = 0; $index < $length; $index++ ) {
			$char      = $row[ $index ];
			$prev_char = $index > 0 ? $row[ $index - 1 ] : '';
			$next_char = ( $index + 1 < $length ) ? $row[ $index + 1 ] : '';

			if ( $in_string ) {
				$current .= $char;
				if ( $char === $quote && ! $this->is_sql_quote_escaped( $row, $index ) && $quote !== $next_char ) {
					$in_string = false;
					$quote     = '';
				}
				continue;
			}

			if ( '\'' === $char || '"' === $char ) {
				$in_string = true;
				$quote     = $char;
				$current  .= $char;
				continue;
			}

			if ( ',' === $char ) {
				$values[] = trim( $current );
				$current  = '';
				continue;
			}

			$current .= $char;
		}

		$values[] = trim( $current );
		return $values;
	}

	private function parse_sql_string_literal( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$quote = substr( $value, 0, 1 );
		if ( '\'' !== $quote && '"' !== $quote ) {
			return trim( $value, "'\"" );
		}

		$result = '';
		$length = strlen( $value );

		for ( $index = 1; $index < $length; $index++ ) {
			$char = $value[ $index ];

			if ( '\\' === $char && $index + 1 < $length ) {
				$result .= $value[ $index + 1 ];
				$index++;
				continue;
			}

			if ( $char === $quote ) {
				if ( $index + 1 < $length && $value[ $index + 1 ] === $quote ) {
					$result .= $quote;
					$index++;
					continue;
				}

				break;
			}

			$result .= $char;
		}

		return $result;
	}

	private function is_sql_quote_escaped( $sql, $quote_index ) {
		$backslashes = 0;
		for ( $index = (int) $quote_index - 1; $index >= 0 && '\\' === $sql[ $index ]; $index-- ) {
			$backslashes++;
		}

		return 1 === ( $backslashes % 2 );
	}

	private function is_skipped_option_name( $option_name ) {
		$option_name = (string) $option_name;

		foreach ( array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ) as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_mariadb_sandbox_comment( $line ) {
		$line = ltrim( (string) $line );

		if ( false === stripos( $line, 'sandbox' ) ) {
			return false;
		}

		return (bool) preg_match( '/^\/\*(?:!|M!)?\d+\\\\-/', $line );
	}

	private function connect() {
		$host_parts = $this->parse_db_host( DB_HOST );
		$mysqli     = mysqli_init();

		if ( ! $mysqli ) {
			return new WP_Error( 'ag_sync_bridge_mysqli_init', __( 'Unable to initialize mysqli.', 'ag-sync-bridge' ) );
		}

		if ( defined( 'MYSQLI_OPT_CONNECT_TIMEOUT' ) ) {
			@$mysqli->options( MYSQLI_OPT_CONNECT_TIMEOUT, 30 );
		}

		if ( defined( 'MYSQLI_OPT_READ_TIMEOUT' ) ) {
			@$mysqli->options( MYSQLI_OPT_READ_TIMEOUT, 300 );
		}

		$connected = $mysqli->real_connect(
			$host_parts['host'],
			DB_USER,
			DB_PASSWORD,
			DB_NAME,
			$host_parts['port'],
			$host_parts['socket']
		);

		if ( ! $connected ) {
			return new WP_Error( 'ag_sync_bridge_mysqli_connect', $mysqli->connect_error );
		}

		$mysqli->set_charset( DB_CHARSET );

		return $mysqli;
	}

	private function can_use_cli_tools() {
		return function_exists( 'proc_open' ) && $this->locate_binary( 'mysqldump' ) && $this->locate_binary( 'mysql' );
	}

	private function locate_binary( $name ) {
		$constant = 'mysql' === $name ? 'AG_SYNC_BRIDGE_MYSQL_BIN' : 'AG_SYNC_BRIDGE_MYSQLDUMP_BIN';
		if ( defined( $constant ) && file_exists( constant( $constant ) ) ) {
			return constant( $constant );
		}

		$candidates = array();
		$php_dir    = dirname( PHP_BINARY );
		$root_dir   = dirname( $php_dir );

		if ( 'Windows' === PHP_OS_FAMILY ) {
			$candidates[] = $root_dir . '\\mysql\\bin\\' . $name . '.exe';
			$candidates[] = $name . '.exe';
		} else {
			$candidates[] = '/usr/bin/' . $name;
			$candidates[] = '/usr/local/bin/' . $name;
			$candidates[] = $name;
		}

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) || $candidate === $name || $candidate === $name . '.exe' ) {
				return $candidate;
			}
		}

		return '';
	}

	private function build_mysql_command_arguments( $binary ) {
		$host_parts = $this->parse_db_host( DB_HOST );
		$args       = array(
			$binary,
			'--host=' . $host_parts['host'],
			'--user=' . DB_USER,
		);

		if ( DB_PASSWORD ) {
			$args[] = '--password=' . DB_PASSWORD;
		}

		if ( ! empty( $host_parts['port'] ) ) {
			$args[] = '--port=' . $host_parts['port'];
		}

		if ( ! empty( $host_parts['socket'] ) ) {
			$args[] = '--socket=' . $host_parts['socket'];
		}

		return $args;
	}

	private function get_mysql_charset() {
		$charset = defined( 'DB_CHARSET' ) ? trim( (string) DB_CHARSET ) : '';

		if ( '' === $charset ) {
			$charset = 'utf8mb4';
		}

		return $charset;
	}

	private function get_mysql_collation() {
		$collation = defined( 'DB_COLLATE' ) ? trim( (string) DB_COLLATE ) : '';

		if ( '' !== $collation ) {
			return $collation;
		}

		global $wpdb;

		if ( isset( $wpdb->collate ) && is_string( $wpdb->collate ) && '' !== trim( $wpdb->collate ) ) {
			return trim( $wpdb->collate );
		}

		return '';
	}

	private function build_shell_command( array $arguments ) {
		$escaped = array_map( array( $this, 'escape_shell_argument' ), $arguments );
		return implode( ' ', $escaped );
	}

	private function get_mysql_import_init_command() {
		$modes      = array(
			'NO_ZERO_DATE',
			'NO_ZERO_IN_DATE',
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
			'TRADITIONAL',
			'ANSI',
			'ONLY_FULL_GROUP_BY',
		);
		$expression = "CONCAT(',', @@SESSION.sql_mode, ',')";

		foreach ( $modes as $mode ) {
			$expression = "REPLACE({$expression}, ',{$mode},', ',')";
		}

		return "SET SESSION sql_mode = TRIM(BOTH ',' FROM {$expression})";
	}

	private function escape_shell_argument( $argument ) {
		$argument = (string) $argument;

		if ( 'Windows' !== PHP_OS_FAMILY ) {
			return escapeshellarg( $argument );
		}

		if ( '' === $argument ) {
			return '""';
		}

		if ( preg_match( '/[\s"]/u', $argument ) ) {
			return '"' . str_replace( '"', '\"', $argument ) . '"';
		}

		return $argument;
	}

	private function parse_db_host( $host_value ) {
		$parts = array(
			'host'   => 'localhost',
			'port'   => null,
			'socket' => null,
		);

		$host_value = (string) $host_value;

		if ( false !== strpos( $host_value, ':' ) ) {
			list( $host, $port ) = explode( ':', $host_value, 2 );
			$parts['host'] = $host ? $host : 'localhost';
			$parts['port'] = ctype_digit( (string) $port ) ? (int) $port : null;
			return $parts;
		}

		$parts['host'] = $host_value ?: 'localhost';
		return $parts;
	}

	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	private function prepare_sql_value( $value ) {
		global $wpdb;

		return $wpdb->prepare( '%s', $value );
	}

	private function get_text_columns( $table ) {
		global $wpdb;

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		$columns = is_array( $columns ) ? $columns : array();
		$textual = array();

		foreach ( $columns as $column ) {
			$field = array_get( $column, 'Field', '' );
			$type  = strtolower( (string) array_get( $column, 'Type', '' ) );

			if ( 'guid' === $field ) {
				continue;
			}

			if ( preg_match( '/char|text|json|enum|set/', $type ) ) {
				$textual[] = $field;
			}
		}

		return $textual;
	}

	private function get_primary_keys( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		$keys = is_array( $keys ) ? $keys : array();
		$list = array();

		foreach ( $keys as $key ) {
			$list[] = array_get( $key, 'Column_name', '' );
		}

		return array_filter( $list );
	}

	private function replace_in_database_string( $value, array $replacements, &$changed ) {
		$changed = false;

		if ( null === $value || ! is_string( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$data          = maybe_unserialize( $value );
			$inner_changed = false;

			try {
				$data = $this->replace_in_structure( $data, $replacements, $inner_changed );
			} catch ( \Throwable $throwable ) {
				$this->logger->warning(
					'Skipping serialized value during URL replace after unserialize error.',
					array(
						'message' => $throwable->getMessage(),
					)
				);
				return $value;
			}

			if ( $inner_changed ) {
				$changed = true;
				return maybe_serialize( $data );
			}

			return $value;
		}

		$new_value = strtr( $value, $replacements );

		if ( $new_value !== $value ) {
			$changed = true;
		}

		return $new_value;
	}

	private function replace_in_structure( $value, array $replacements, &$changed ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->replace_in_structure( $item, $replacements, $changed );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			if ( $this->is_incomplete_object( $value ) ) {
				return $value;
			}

			foreach ( get_object_vars( $value ) as $property => $item ) {
				$value->$property = $this->replace_in_structure( $item, $replacements, $changed );
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			$new_value = strtr( $value, $replacements );
			if ( $new_value !== $value ) {
				$changed = true;
			}
			return $new_value;
		}

		return $value;
	}

	private function is_incomplete_object( $value ) {
		return is_object( $value ) && '__PHP_Incomplete_Class' === get_class( $value );
	}

	private function sanitize_active_plugins( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugins = array_map( 'strval', $plugins );
		$plugins = array_filter(
			$plugins,
			static function ( $plugin ) {
				return '' !== trim( $plugin );
			}
		);

		return array_values( array_unique( $plugins ) );
	}

	private function normalize_active_plugins_for_target( $plugins ) {
		$plugins         = $this->sanitize_active_plugins( $plugins );
		$plugin_basename = $this->config->get_plugin_basename();

		$plugins = array_values(
			array_filter(
				$plugins,
				array( $this, 'is_not_bridge_plugin_basename' )
			)
		);

		$plugins[] = $plugin_basename;

		return array_values( array_unique( $plugins ) );
	}

	private function is_not_bridge_plugin_basename( $plugin ) {
		return ! preg_match( '#(^|.*/)(ag-sync-bridge[^/]*)/ag-sync-bridge\.php$#i', (string) $plugin );
	}

	private function table_exists( $table ) {
		global $wpdb;

		$table = (string) $table;
		if ( '' === $table ) {
			return false;
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (string) $exists === $table;
	}
}
