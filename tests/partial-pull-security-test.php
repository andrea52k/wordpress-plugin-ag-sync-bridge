<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['agsync_partial_pull_transients'] = array();

	function __( $message ) { return $message; }
	function home_url() { return 'https://local.example'; }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function get_transient( $key ) { return $GLOBALS['agsync_partial_pull_transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) {
		unset( $ttl );
		$GLOBALS['agsync_partial_pull_transients'][ $key ] = $value;
		return true;
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}

	class WP_REST_Request {
		private $method;
		private $route;
		private $headers;
		private $body;
		public function __construct( $method, $route, array $headers, $body ) {
			$this->method = $method;
			$this->route = $route;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
			$this->body = $body;
		}
		public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? ''; }
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
		public function get_body() { return $this->body; }
	}
}

namespace AGSyncBridge {
	class Config {
		public function get_secret() { return 'partial-pull-secret'; }
		public function set_state_value( $key, $value ) { unset( $key, $value ); }
	}
	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-auth.php';

	function expect_partial_pull( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$route = '/ag-sync-bridge/v1/snapshot/create';
	$body = '{"type":"manual-partial-pull-snapshot","async":true,"scope":"partial","paths":[".htaccess"]}';
	$auth = new Auth( new Config(), new Logger() );
	$headers = $auth->build_headers( 'POST', $route, $body );
	expect_partial_pull(
		true === $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $headers, $body ) ),
		'Exact partial snapshot request body must verify.'
	);

	$tampered_headers = $auth->build_headers( 'POST', $route, $body );
	$tampered = $auth->verify_rest_request(
		new \WP_REST_Request(
			'POST',
			$route,
			$tampered_headers,
			'{"type":"manual-partial-pull-snapshot","async":true,"scope":"partial","paths":["wp-content/plugins"]}'
		)
	);
	expect_partial_pull( \is_wp_error( $tampered ) && 'ag_sync_bridge_body_hash_mismatch' === $tampered->get_error_code(), 'Tampered partial pull paths must fail HMAC body verification.' );

	$sync = file_get_contents( dirname( __DIR__ ) . '/includes/class-sync-service.php' );
	$http = file_get_contents( dirname( __DIR__ ) . '/includes/class-http-client.php' );
	$import = file_get_contents( dirname( __DIR__ ) . '/includes/class-import-service.php' );
	$doctor = file_get_contents( dirname( __DIR__ ) . '/includes/class-cli.php' );
	$file_system = file_get_contents( dirname( __DIR__ ) . '/includes/class-file-system-service.php' );

	expect_partial_pull( false !== strpos( $sync, 'function plan_pull' ) && false !== strpos( $sync, 'partial-pre-pull-backup' ), 'Partial pull must expose a dry-run plan and create a scoped local backup.' );
	expect_partial_pull( false !== strpos( $sync, 'validate_remote_partial_snapshot' ) && false !== strpos( $sync, 'expected_partial_paths' ), 'Partial pull must verify remote scope and exact paths.' );
	expect_partial_pull( false !== strpos( $http, "'/ag-sync-bridge/v1/snapshot/create'" ) && false !== strpos( $http, 'requires_signed_body_route' ), 'Partial snapshot creation must use a protected signed-body route.' );
	expect_partial_pull( false !== strpos( $import, 'ag_sync_bridge_partial_import_not_allowed' ) && false !== strpos( $import, 'ag_sync_bridge_partial_import_database_forbidden' ), 'Partial imports must require explicit allow and reject database.sql.' );
	expect_partial_pull( false !== strpos( $file_system, 'validate_partial_snapshot_manifest' ) && false !== strpos( $file_system, 'partial_paths_mismatch' ), 'Manifest validation must fail on path mismatch.' );
	expect_partial_pull( false !== strpos( $doctor, 'Latest snapshot full validation: not applicable' ) && false !== strpos( $doctor, 'Latest snapshot partial validation:' ), 'Doctor must report partial validation without a false full failure.' );

	echo "partial pull security: ok\n";
}
