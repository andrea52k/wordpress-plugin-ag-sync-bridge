<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function __( $message ) { return $message; }
	function array_get( $array, $key, $default = null ) { return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : $default; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}

	class AGSB_Site_Kit_User_Meta_Wpdb {
		public $prefix = 'wp_';
		public $users = 'wp_users';
		public $usermeta = 'wp_usermeta';
		public $last_error = '';
		public $user_rows = array();
		public $meta_rows = array();
		public $fail_next_insert = false;
		public $queries = array();
		private $transaction_snapshot = null;

		public function esc_like( $value ) { return addcslashes( (string) $value, '_%\\' ); }
		public function prepare( $query, ...$values ) {
			foreach ( $values as $value ) {
				$string_position = strpos( $query, '%s' );
				$integer_position = strpos( $query, '%d' );
				if ( false !== $integer_position && ( false === $string_position || $integer_position < $string_position ) ) {
					$query = preg_replace( '/%d/', (string) (int) $value, $query, 1 );
				} else {
					$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 );
				}
			}
			return $query;
		}
		public function get_var( $query ) {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $match ) ) {
				return in_array( $match[1], array( $this->users, $this->usermeta ), true ) ? $match[1] : null;
			}
			if ( preg_match( "/SELECT ID FROM `wp_users` WHERE user_login = '([^']+)'/", $query, $match ) ) {
				foreach ( $this->user_rows as $id => $login ) {
					if ( $login === str_replace( "''", "'", $match[1] ) ) { return $id; }
				}
			}
			return null;
		}
		public function get_results( $query, $output = null ) {
			$rows = array();
			foreach ( $this->meta_rows as $row ) {
				if ( isset( $this->user_rows[ $row['user_id'] ] ) && 0 === strpos( $row['meta_key'], 'wp_googlesitekit' ) ) {
					$rows[] = array( 'user_login' => $this->user_rows[ $row['user_id'] ], 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value'] );
				}
			}
			return $rows;
		}
		public function query( $query ) {
			$this->queries[] = $query;
			if ( 'START TRANSACTION' === $query ) { $this->transaction_snapshot = $this->meta_rows; return true; }
			if ( 'ROLLBACK' === $query ) { $this->meta_rows = $this->transaction_snapshot; return true; }
			if ( 'COMMIT' === $query ) { $this->transaction_snapshot = null; return true; }
			if ( 0 === strpos( $query, 'DELETE FROM `wp_usermeta`' ) ) {
				$this->meta_rows = array_values( array_filter( $this->meta_rows, static function ( $row ) { return 0 !== strpos( $row['meta_key'], 'wp_googlesitekit' ); } ) );
				return 1;
			}
			if ( preg_match( "/INSERT INTO `wp_usermeta` .* VALUES \(([0-9]+), '([^']+)', '([^']*)'\)/", $query, $match ) ) {
				if ( $this->fail_next_insert ) { $this->fail_next_insert = false; $this->last_error = 'insert failed'; return false; }
				$this->meta_rows[] = array( 'user_id' => (int) $match[1], 'meta_key' => $match[2], 'meta_value' => str_replace( "''", "'", $match[3] ) );
				return 1;
			}
			return false;
		}
	}
}

namespace AGSyncBridge {
	class Config {}
	class Logger {}
	require_once dirname( __DIR__ ) . '/includes/class-database-service.php';
	function expect_site_kit_user_meta( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }

	global $wpdb;
	$wpdb = new \AGSB_Site_Kit_User_Meta_Wpdb();
	$wpdb->user_rows = array( 7 => 'owner', 9 => 'removed-owner' );
	$wpdb->meta_rows = array(
		array( 'user_id' => 7, 'meta_key' => 'wp_googlesitekit_access_token', 'meta_value' => 'live-token' ),
		array( 'user_id' => 7, 'meta_key' => 'wp_googlesitekitpersistent_dismissed_items', 'meta_value' => 'live-state' ),
		array( 'user_id' => 9, 'meta_key' => 'wp_googlesitekit_refresh_token', 'meta_value' => 'removed-token' ),
		array( 'user_id' => 7, 'meta_key' => 'unrelated', 'meta_value' => 'keep' ),
	);

	$service = ( new \ReflectionClass( Database_Service::class ) )->newInstanceWithoutConstructor();
	$captured = $service->capture_google_site_kit_user_meta();
	expect_site_kit_user_meta( 3 === count( $captured ), 'All target Site Kit user metadata must be captured.' );
	expect_site_kit_user_meta( 'googlesitekit_access_token' === $captured[0]['meta_key_suffix'], 'Captured keys must be independent of the target table prefix.' );

	$wpdb->user_rows = array( 42 => 'owner', 55 => 'source-user' );
	$wpdb->meta_rows = array(
		array( 'user_id' => 42, 'meta_key' => 'wp_googlesitekit_access_token', 'meta_value' => 'source-token' ),
		array( 'user_id' => 55, 'meta_key' => 'wp_googlesitekit_refresh_token', 'meta_value' => 'source-user-token' ),
		array( 'user_id' => 42, 'meta_key' => 'unrelated', 'meta_value' => 'imported-unrelated' ),
	);
	$restored = $service->restore_google_site_kit_user_meta( $captured );
	expect_site_kit_user_meta( ! is_wp_error( $restored ) && 2 === $restored['restored'] && 1 === $restored['skipped'], 'Target Site Kit user metadata must follow matching logins.' );
	expect_site_kit_user_meta( 'live-token' === $wpdb->meta_rows[1]['meta_value'], 'The live access token must replace the imported source token.' );
	expect_site_kit_user_meta( 'imported-unrelated' === $wpdb->meta_rows[0]['meta_value'], 'Unrelated imported user metadata must remain untouched.' );

	$before_failure = $wpdb->meta_rows;
	$wpdb->fail_next_insert = true;
	$failed = $service->restore_google_site_kit_user_meta( $captured );
	expect_site_kit_user_meta( is_wp_error( $failed ) && 'ag_sync_bridge_google_site_kit_user_meta_restore_failed' === $failed->get_error_code(), 'Insert failure must fail closed.' );
	expect_site_kit_user_meta( $before_failure === $wpdb->meta_rows && 'ROLLBACK' === end( $wpdb->queries ), 'Failed restore must roll back all usermeta changes.' );

	$import_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	$database_import = strpos( $import_source, '$this->database->import_from_file(' );
	$restore = strpos( $import_source, '$this->database->restore_google_site_kit_user_meta(', $database_import );
	$checkpoint = strpos( $import_source, "check_cancellation( \$args, 'after_database_import'", $database_import );
	expect_site_kit_user_meta( false !== $database_import && false !== $restore && false !== $checkpoint && $database_import < $restore && $restore < $checkpoint, 'Site Kit user metadata must restore before the post-import checkpoint.' );

	echo "google site kit user meta preservation regression: ok\n";
}
