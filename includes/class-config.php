<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {
	const OPTION_SETTINGS    = 'ag_sync_bridge_settings';
	const OPTION_STATE       = 'ag_sync_bridge_state';
	const OPTION_RECENT_LOGS = 'ag_sync_bridge_recent_logs';

	public function get_defaults() {
		return array(
			'role'                 => '',
			'remote_url'           => '',
			'shared_secret'        => '',
			'storage_dir'          => '',
			'auto_pull_enabled'    => false,
			'include_htaccess'     => false,
			'retention_count'      => 1,
			'request_timeout'      => 900,
			'exclude_patterns'     => implode( "\n", $this->get_default_exclude_patterns() ),
			'external_backup_dirs' => '',
		);
	}

	public function get_default_state() {
		return array(
			'last_snapshot'      => array(),
			'last_pull'          => array(),
			'last_push'          => array(),
			'last_auto_pull'     => array(),
			'last_connection'    => array(),
			'last_authenticated_request' => array(),
			'current_operation'  => array(),
			'last_operation_log' => '',
			'storage_policy_version' => AG_SYNC_BRIDGE_VERSION,
		);
	}

	public function ensure_defaults() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( empty( $stored['role'] ) ) {
			$stored['role'] = $this->detect_role();
		}

		if ( empty( $stored['shared_secret'] ) ) {
			$stored['shared_secret'] = wp_generate_password( 64, true, true );
		}

		$state = get_option( self::OPTION_STATE, false );
		$state = is_array( $state ) ? $state : array();

		$storage_policy_version = (string) array_get( $state, 'storage_policy_version', '0' );
		$needs_storage_policy_migration = version_compare( $storage_policy_version, '0.1.24', '<' );

		if ( $needs_storage_policy_migration && ! defined( 'AG_SYNC_BRIDGE_RETENTION_COUNT' ) && isset( $stored['retention_count'] ) && 3 === absint( $stored['retention_count'] ) ) {
			$stored['retention_count'] = 1;
		}

		$stored['storage_dir'] = $this->normalize_storage_dir( array_get( $stored, 'storage_dir', '' ) );

		update_option( self::OPTION_SETTINGS, wp_parse_args( $stored, $this->get_defaults() ), false );

		if ( false === get_option( self::OPTION_STATE, false ) ) {
			add_option( self::OPTION_STATE, $this->get_default_state(), '', false );
		} elseif ( $needs_storage_policy_migration ) {
			$state['storage_policy_version'] = AG_SYNC_BRIDGE_VERSION;
			update_option( self::OPTION_STATE, wp_parse_args( $state, $this->get_default_state() ), false );
		}

		if ( false === get_option( self::OPTION_RECENT_LOGS, false ) ) {
			add_option( self::OPTION_RECENT_LOGS, array(), '', false );
		}
	}

	public function sanitize_settings( $input ) {
		$current = $this->get_stored_settings();
		$input   = is_array( $input ) ? $input : array();

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : '';
		if ( ! in_array( $role, array( 'local', 'remote' ), true ) ) {
			$role = $this->detect_role();
		}

		$remote_url = isset( $input['remote_url'] ) ? trim( (string) $input['remote_url'] ) : '';
		$remote_url = $remote_url ? untrailingslashit( esc_url_raw( $remote_url ) ) : '';

		$secret = isset( $input['shared_secret'] ) ? trim( wp_unslash( (string) $input['shared_secret'] ) ) : '';
		$secret = preg_replace( '/[^\x20-\x7E]/', '', $secret );
		if ( '' === $secret ) {
			$secret = array_get( $current, 'shared_secret', '' );
		}

		$storage_dir = isset( $input['storage_dir'] ) ? trim( wp_unslash( (string) $input['storage_dir'] ) ) : '';
		$storage_dir = $this->normalize_storage_dir( $storage_dir );

		$retention_count = isset( $input['retention_count'] ) ? absint( $input['retention_count'] ) : 1;
		$retention_count = max( 1, min( 10, $retention_count ) );

		$request_timeout = isset( $input['request_timeout'] ) ? absint( $input['request_timeout'] ) : 300;
		$request_timeout = max( 30, min( 900, $request_timeout ) );

		$exclude_patterns = isset( $input['exclude_patterns'] ) ? (string) wp_unslash( $input['exclude_patterns'] ) : '';
		$exclude_patterns = implode( "\n", sanitize_line_list( $exclude_patterns ) );
		$external_dirs    = isset( $input['external_backup_dirs'] ) ? (string) wp_unslash( $input['external_backup_dirs'] ) : '';
		$external_dirs    = implode( "\n", $this->sanitize_path_lines( $external_dirs ) );

		return array(
			'role'                 => $role,
			'remote_url'           => $remote_url,
			'shared_secret'        => $secret,
			'storage_dir'          => $storage_dir,
			'auto_pull_enabled'    => ! empty( $input['auto_pull_enabled'] ),
			'include_htaccess'     => ! empty( $input['include_htaccess'] ),
			'retention_count'      => $retention_count,
			'request_timeout'      => $request_timeout,
			'exclude_patterns'     => $exclude_patterns,
			'external_backup_dirs' => $external_dirs,
		);
	}

	public function get_stored_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public function get_settings() {
		$settings = wp_parse_args( $this->get_stored_settings(), $this->get_defaults() );

		if ( defined( 'AG_SYNC_BRIDGE_ROLE' ) ) {
			$settings['role'] = sanitize_key( AG_SYNC_BRIDGE_ROLE );
		}

		if ( defined( 'AG_SYNC_BRIDGE_REMOTE_URL' ) ) {
			$settings['remote_url'] = untrailingslashit( (string) AG_SYNC_BRIDGE_REMOTE_URL );
		}

		if ( defined( 'AG_SYNC_BRIDGE_SHARED_SECRET' ) ) {
			$settings['shared_secret'] = (string) AG_SYNC_BRIDGE_SHARED_SECRET;
		}

		if ( defined( 'AG_SYNC_BRIDGE_STORAGE_DIR' ) ) {
			$settings['storage_dir'] = $this->normalize_storage_dir( (string) AG_SYNC_BRIDGE_STORAGE_DIR );
		}

		if ( defined( 'AG_SYNC_BRIDGE_AUTO_PULL_ENABLED' ) ) {
			$settings['auto_pull_enabled'] = (bool) AG_SYNC_BRIDGE_AUTO_PULL_ENABLED;
		}

		if ( defined( 'AG_SYNC_BRIDGE_INCLUDE_HTACCESS' ) ) {
			$settings['include_htaccess'] = (bool) AG_SYNC_BRIDGE_INCLUDE_HTACCESS;
		}

		if ( defined( 'AG_SYNC_BRIDGE_RETENTION_COUNT' ) ) {
			$settings['retention_count'] = max( 1, absint( AG_SYNC_BRIDGE_RETENTION_COUNT ) );
		}

		if ( defined( 'AG_SYNC_BRIDGE_REQUEST_TIMEOUT' ) ) {
			$settings['request_timeout'] = max( 30, absint( AG_SYNC_BRIDGE_REQUEST_TIMEOUT ) );
		}

		if ( defined( 'AG_SYNC_BRIDGE_EXCLUDE_PATTERNS' ) ) {
			$extra_patterns               = AG_SYNC_BRIDGE_EXCLUDE_PATTERNS;
			$settings['exclude_patterns'] = is_array( $extra_patterns ) ? implode( "\n", $extra_patterns ) : (string) $extra_patterns;
		}

		if ( defined( 'AG_SYNC_BRIDGE_EXTERNAL_BACKUP_DIRS' ) ) {
			$extra_dirs                     = AG_SYNC_BRIDGE_EXTERNAL_BACKUP_DIRS;
			$settings['external_backup_dirs'] = is_array( $extra_dirs ) ? implode( "\n", $extra_dirs ) : (string) $extra_dirs;
		}

		if ( empty( $settings['role'] ) || ! in_array( $settings['role'], array( 'local', 'remote' ), true ) ) {
			$settings['role'] = $this->detect_role();
		}

		$settings['storage_dir'] = $this->normalize_storage_dir( array_get( $settings, 'storage_dir', '' ) );

		return $settings;
	}

	public function get( $key, $default = null ) {
		return array_get( $this->get_settings(), $key, $default );
	}

	public function get_role() {
		return $this->get( 'role', $this->detect_role() );
	}

	public function get_role_source() {
		if ( defined( 'AG_SYNC_BRIDGE_ROLE' ) ) {
			return 'constant';
		}

		$stored = $this->get_stored_settings();
		if ( ! empty( $stored['role'] ) ) {
			return 'setting';
		}

		return 'auto';
	}

	public function detect_role() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( (string) $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return 'local';
		}

		foreach ( array( '.test', '.local', '.localhost', '.invalid' ) as $suffix ) {
			if ( $suffix && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return 'local';
			}
		}

		return 'remote';
	}

	public function get_remote_url() {
		return untrailingslashit( (string) $this->get( 'remote_url', '' ) );
	}

	public function get_secret() {
		return (string) $this->get( 'shared_secret', '' );
	}

	public function get_request_timeout() {
		return max( 30, absint( $this->get( 'request_timeout', 300 ) ) );
	}

	public function get_default_storage_dir() {
		return normalize_path( WP_CONTENT_DIR . '/ag-sync-bridge-data' );
	}

	public function normalize_storage_dir( $storage_dir ) {
		$storage_dir = trim( (string) $storage_dir );

		if ( '' === $storage_dir ) {
			return $this->get_default_storage_dir();
		}

		$storage_dir = str_replace( '\\', '/', $storage_dir );

		if ( $this->is_foreign_absolute_path( $storage_dir ) ) {
			return $this->get_default_storage_dir();
		}

		if ( ! path_is_absolute( $storage_dir ) ) {
			$storage_dir = WP_CONTENT_DIR . '/' . ltrim( $storage_dir, '/' );
		}

		return rtrim( normalize_path( $storage_dir ), '/' );
	}

	public function get_storage_dir() {
		return $this->normalize_storage_dir( (string) $this->get( 'storage_dir', $this->get_default_storage_dir() ) );
	}

	public function get_data_dir( $subdir = '' ) {
		$dir = $this->get_storage_dir();

		if ( $subdir ) {
			$dir .= '/' . trim( str_replace( '\\', '/', $subdir ), '/' );
		}

		return normalize_path( $dir );
	}

	public function get_exclude_patterns() {
		$settings = $this->get_settings();
		$custom   = sanitize_line_list( array_get( $settings, 'exclude_patterns', '' ) );
		$patterns = array_merge( $this->get_default_exclude_patterns(), $custom );

		return array_values( array_unique( array_filter( array_map( 'trim', $patterns ) ) ) );
	}

	public function get_external_backup_dirs() {
		$settings = $this->get_settings();
		return $this->sanitize_path_lines( array_get( $settings, 'external_backup_dirs', '' ) );
	}

	public function get_default_exclude_patterns() {
		$plugin_dir = dirname( $this->get_plugin_basename() );
		$plugin_dir = '.' === $plugin_dir ? 'ag-sync-bridge' : trim( str_replace( '\\', '/', $plugin_dir ), '/' );

		return array(
			'wp-content/ag-sync-bridge-data/*',
			'wp-content/plugins/' . $plugin_dir . '/*',
			'wp-content/cache/*',
			'wp-content/upgrade/*',
			'wp-content/ai1wm-backups/*',
			'wp-content/backup-*',
			'wp-content/debug.log',
			'wp-content/*.log',
			'wp-content/mpg-cache/*',
			'wp-content/uploads/al_opt_content/*',
			'wp-content/uploads/cache/*',
			'*.log',
			'*.tmp',
			'*.temp',
			'Thumbs.db',
			'.DS_Store',
		);
	}

	public function get_state() {
		$state = get_option( self::OPTION_STATE, $this->get_default_state() );
		return is_array( $state ) ? wp_parse_args( $state, $this->get_default_state() ) : $this->get_default_state();
	}

	public function update_state( array $changes ) {
		$state = array_merge( $this->get_state(), $changes );
		update_option( self::OPTION_STATE, $state, false );
		return $state;
	}

	public function set_state_value( $key, $value ) {
		$state         = $this->get_state();
		$state[ $key ] = $value;
		update_option( self::OPTION_STATE, $state, false );
		return $state;
	}

	public function get_plugin_basename() {
		return plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE );
	}

	public function get_preserved_options() {
		return array(
			self::OPTION_SETTINGS,
			self::OPTION_STATE,
			self::OPTION_RECENT_LOGS,
		);
	}

	private function sanitize_path_lines( $value ) {
		$paths = sanitize_line_list( $value );
		$clean = array();

		foreach ( $paths as $path ) {
			$normalized = $this->normalize_generic_path( $path );

			if ( '' !== $normalized ) {
				$clean[] = $normalized;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function normalize_generic_path( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		if ( $this->is_foreign_absolute_path( $path ) ) {
			return '';
		}

		if ( ! path_is_absolute( $path ) ) {
			$path = ABSPATH . ltrim( str_replace( '\\', '/', $path ), '/' );
		}

		return rtrim( normalize_path( $path ), '/' );
	}

	private function is_foreign_absolute_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		if ( preg_match( '#^[A-Za-z]:/#', $path ) ) {
			return 'Windows' !== PHP_OS_FAMILY;
		}

		if ( 0 === strpos( $path, '/' ) && 'Windows' === PHP_OS_FAMILY ) {
			return true;
		}

		return false;
	}
}
