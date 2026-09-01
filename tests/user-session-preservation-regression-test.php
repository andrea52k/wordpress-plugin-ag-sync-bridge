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

	class AGSB_Session_Wpdb {
		public $users = 'wp_users';
		public $usermeta = 'wp_usermeta';
		public $last_error = '';
		public $user_rows = array();
		public $user_passwords = array();
		public $session_rows = array();
		public $fail_capture = false;
		public $fail_next_insert = false;
		public $queries = array();
		private $transaction_snapshot = null;

		public function prepare( $query, ...$values ) {
			foreach ( $values as $value ) {
				$string_position = strpos( $query, '%s' );
				$integer_position = strpos( $query, '%d' );
				if ( false !== $integer_position && ( false === $string_position || $integer_position < $string_position ) ) {
					$query = preg_replace( '/%d/', (string) (int) $value, $query, 1 );
					continue;
				}
				$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $value ) . "'", $query, 1 );
			}
			return $query;
		}

		public function get_var( $query ) {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $match ) ) {
				return in_array( $match[1], array( $this->users, $this->usermeta ), true ) ? $match[1] : null;
			}
			if ( preg_match( "/SELECT ID FROM `wp_users` WHERE user_login = '([^']+)'/", $query, $match ) ) {
				foreach ( $this->user_rows as $user_id => $user_login ) {
					if ( $user_login === str_replace( "''", "'", $match[1] ) ) {
						return $user_id;
					}
				}
				return null;
			}
			return null;
		}

		public function get_results( $query, $output = null ) {
			if ( $this->fail_capture ) {
				$this->last_error = 'capture query failed';
				return null;
			}
			$rows = array();
			foreach ( $this->session_rows as $row ) {
				if ( isset( $this->user_rows[ $row['user_id'] ] ) ) {
					$rows[] = array( 'user_login' => $this->user_rows[ $row['user_id'] ], 'user_pass' => $this->user_passwords[ $row['user_id'] ], 'meta_value' => $row['meta_value'] );
				}
			}
			return $rows;
		}

		public function query( $query ) {
			$this->queries[] = $query;
			if ( 'START TRANSACTION' === $query ) {
				$this->transaction_snapshot = $this->session_rows;
				return true;
			}
			if ( 'ROLLBACK' === $query ) {
				$this->session_rows = $this->transaction_snapshot;
				return true;
			}
			if ( 'COMMIT' === $query ) {
				$this->transaction_snapshot = null;
				return true;
			}
			if ( 0 === strpos( $query, 'DELETE FROM `wp_usermeta`' ) ) {
				$this->session_rows = array();
				return 1;
			}
			if ( preg_match( "/UPDATE `wp_users` SET user_pass = '([^']*)' WHERE ID = ([0-9]+)/", $query, $match ) ) {
				$this->user_passwords[ (int) $match[2] ] = str_replace( "''", "'", $match[1] );
				return 1;
			}
			if ( preg_match( "/INSERT INTO `wp_usermeta` .* VALUES \(([0-9]+), 'session_tokens', '([^']*)'\)/", $query, $match ) ) {
				if ( $this->fail_next_insert ) {
					$this->fail_next_insert = false;
					$this->last_error = 'insert failed';
					return false;
				}
				$this->session_rows[] = array( 'user_id' => (int) $match[1], 'meta_value' => str_replace( "''", "'", $match[2] ) );
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

	function expect_session_preservation( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	global $wpdb;
	$wpdb = new \AGSB_Session_Wpdb();
	$wpdb->user_rows = array( 7 => 'admin', 9 => 'removed-live-user' );
	$wpdb->user_passwords = array( 7 => 'live-admin-password-hash', 9 => 'live-removed-password-hash' );
	$wpdb->session_rows = array(
		array( 'user_id' => 7, 'meta_value' => 'live-admin-token-map' ),
		array( 'user_id' => 9, 'meta_value' => 'live-removed-token-map' ),
	);

	$reflection = new \ReflectionClass( Database_Service::class );
	$service = $reflection->newInstanceWithoutConstructor();
	$captured = $service->capture_user_sessions();
	expect_session_preservation( is_array( $captured ) && 2 === count( $captured ), 'All target session rows must be captured before import.' );
	expect_session_preservation( 'admin' === $captured[0]['user_login'] && 'live-admin-password-hash' === $captured[0]['user_pass'] && 'live-admin-token-map' === $captured[0]['meta_value'], 'Capture must bind the password hash and raw token map to the stable user login.' );

	// Simulate a full source database import: admin receives another numeric ID,
	// the live-only account disappears, and a source-side session arrives.
	$wpdb->user_rows = array( 42 => 'admin', 55 => 'source-user' );
	$wpdb->user_passwords = array( 42 => 'source-admin-password-hash', 55 => 'source-user-password-hash' );
	$wpdb->session_rows = array( array( 'user_id' => 55, 'meta_value' => 'source-token-map' ) );
	$restored = $service->restore_user_sessions( $captured );

	expect_session_preservation( ! is_wp_error( $restored ), 'Target session restoration must succeed.' );
	expect_session_preservation( 2 === $restored['captured'] && 1 === $restored['restored'] && 1 === $restored['skipped'], 'Restore metrics must expose preserved and removed-account sessions.' );
	expect_session_preservation( array( array( 'user_id' => 42, 'meta_value' => 'live-admin-token-map' ) ) === $wpdb->session_rows, 'Imported source tokens must be removed and the live token must follow the matching login to its new user ID.' );
	expect_session_preservation( 'live-admin-password-hash' === $wpdb->user_passwords[42], 'The live password hash must follow the session because WordPress auth cookies include a password-hash fragment.' );
	expect_session_preservation( false !== array_search( 'START TRANSACTION', $wpdb->queries, true ) && 'COMMIT' === end( $wpdb->queries ), 'Session replacement must be transactional.' );

	$wpdb->session_rows = array( array( 'user_id' => 55, 'meta_value' => 'source-token-map' ) );
	$wpdb->fail_next_insert = true;
	$failed_restore = $service->restore_user_sessions( $captured );
	expect_session_preservation( is_wp_error( $failed_restore ) && 'ag_sync_bridge_session_restore_failed' === $failed_restore->get_error_code(), 'A failed target-token insert must return a dedicated restore error.' );
	expect_session_preservation( array( array( 'user_id' => 55, 'meta_value' => 'source-token-map' ) ) === $wpdb->session_rows && 'ROLLBACK' === end( $wpdb->queries ), 'A failed session replacement must roll back instead of leaving a partially deleted token set.' );

	$wpdb->fail_capture = true;
	$failed_capture = $service->capture_user_sessions();
	expect_session_preservation( is_wp_error( $failed_capture ) && 'ag_sync_bridge_session_capture_failed' === $failed_capture->get_error_code(), 'A capture query failure must fail closed before database mutation.' );

	$import_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	$session_capture = strpos( $import_source, '$this->database->capture_environment_state( ! $is_partial );' );
	$database_import = strpos( $import_source, '$this->database->import_from_file(' );
	$session_restore = strpos( $import_source, '$this->database->restore_user_sessions(', $database_import );
	$after_import_checkpoint = strpos( $import_source, "check_cancellation( \$args, 'after_database_import'", $database_import );
	expect_session_preservation( false !== $session_capture && false !== $database_import && $session_capture < $database_import, 'Target sessions must be captured before a full database import can mutate user tables.' );
	expect_session_preservation( false !== $database_import && false !== $session_restore && false !== $after_import_checkpoint && $database_import < $session_restore && $session_restore < $after_import_checkpoint, 'Live sessions must be restored immediately after database import, before any post-import checkpoint.' );

	echo "user session preservation regression: ok\n";
}
