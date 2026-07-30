<?php
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );

	$GLOBALS['agsync_transients'] = array();

	function __( $message ) { return $message; }
	function home_url() { return 'https://local.example'; }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function get_transient( $key ) { return $GLOBALS['agsync_transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) {
		unset( $ttl );
		$GLOBALS['agsync_transients'][ $key ] = $value;
		return true;
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
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
			$this->method  = $method;
			$this->route   = $route;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
			$this->body    = $body;
		}

		public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? ''; }
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
		public function get_body() { return $this->body; }
	}
}

namespace AGSyncBridge {
	class Config {
		public $state = array();
		public function get_secret() { return 'test-shared-secret'; }
		public function set_state_value( $key, $value ) { $this->state[ $key ] = $value; }
	}

	class Logger {}

	require_once dirname( __DIR__ ) . '/includes/class-auth.php';
	require_once dirname( __DIR__ ) . '/includes/class-http-client.php';

	function expect_signed_backup( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$route   = '/ag-sync-bridge/v1/backup/create';
	$body    = '{"type":"pre-push-backup","scope":"partial","paths":[".htaccess"]}';
	$config  = new Config();
	$auth    = new Auth( $config, new Logger() );
	$headers = $auth->build_headers( 'POST', $route, $body );

	expect_signed_backup( hash( 'sha256', $body ) === $headers['X-AGSB-Body-SHA256'], 'Backup request headers must attest the exact JSON body SHA-256.' );
	expect_signed_backup(
		true === $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $headers, $body ) ),
		'A correctly signed backup body must verify.'
	);

	$client     = new Http_Client( $config, new Logger() );
	$reflection = new \ReflectionClass( Http_Client::class );
	$builder    = $reflection->getMethod( 'build_headers' );
	$builder->setAccessible( true );
	$client_headers = $builder->invoke( $client, 'POST', $route, $body );
	expect_signed_backup(
		true === $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $client_headers, $body ) ),
		'The real HTTP client signature must verify against the remote authenticator.'
	);

	$tampered_headers = $auth->build_headers( 'POST', $route, $body );
	$tampered_body    = '{"type":"pre-push-backup","scope":"partial","paths":["wp-content/plugins"]}';
	$tampered         = $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $tampered_headers, $tampered_body ) );
	expect_signed_backup( \is_wp_error( $tampered ), 'Changing signed backup paths in transit must fail authentication.' );
	expect_signed_backup( 'ag_sync_bridge_body_hash_mismatch' === $tampered->get_error_code(), 'Tampered backup paths must fail with a body hash mismatch.' );

	$unsigned_headers = $auth->build_headers( 'POST', $route, '' );
	unset( $unsigned_headers['X-AGSB-Body-SHA256'] );
	$unsigned = $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $unsigned_headers, $body ) );
	expect_signed_backup( \is_wp_error( $unsigned ), 'Backup route must reject a body without a signed hash.' );
	expect_signed_backup( 'ag_sync_bridge_body_signature_required' === $unsigned->get_error_code(), 'Unsigned backup body rejection must be explicit.' );

	$legacy_headers = $auth->build_headers( 'POST', $route, $body );
	unset( $legacy_headers['X-AGSB-Nonce'] );
	$legacy = $auth->verify_rest_request( new \WP_REST_Request( 'POST', $route, $legacy_headers, $body ) );
	expect_signed_backup( \is_wp_error( $legacy ), 'Backup route must reject a body hash that is not bound to a nonce signature.' );
	expect_signed_backup( 'ag_sync_bridge_body_signature_required' === $legacy->get_error_code(), 'Legacy backup signature downgrade rejection must be explicit.' );

	$client_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-http-client.php' );
	expect_signed_backup( false !== strpos( $client_source, "'X-AGSB-Body-SHA256'" ), 'HTTP client must transmit the body hash header.' );
	expect_signed_backup( false !== strpos( $client_source, "'/ag-sync-bridge/v1/backup/create' !== \$route" ), 'Backup creation must not silently downgrade to a legacy unsigned signature.' );

	echo "signed backup request: ok\n";
}
