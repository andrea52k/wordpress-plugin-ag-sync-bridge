<?php
namespace AGSyncBridge;

/**
 * WordPress-independent, read-only status channel for remote imports.
 *
 * The state is deliberately mirrored below wp-content instead of reading the
 * configured runtime path. This gives the direct endpoint a fixed, non-user-
 * controlled location even when WordPress cannot bootstrap in maintenance
 * mode. Only a SHA-256 token digest is persisted.
 */
class Direct_Operation_Monitor {
	const STATE_BASENAME = 'direct-operation-monitor.json';

	private $directory;

	public function __construct( $wp_content_dir = '' ) {
		if ( '' === (string) $wp_content_dir ) {
			// includes -> plugin -> plugins -> wp-content.
			$wp_content_dir = dirname( __DIR__, 3 );
		}
		$this->directory = rtrim( str_replace( '\\', '/', (string) $wp_content_dir ), '/' ) . '/ag-sync-bridge-data/operations';
	}

	public function arm( $operation_id, $token_sha256, $expires_at, array $operation ) {
		$operation_id = (string) $operation_id;
		$token_sha256 = strtolower( (string) $token_sha256 );
		if ( ! preg_match( '/^[A-Za-z0-9-]{16,128}$/', $operation_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $token_sha256 ) ) {
			return false;
		}

		return $this->write(
			array(
				'operation_id'        => $operation_id,
				'token_sha256'        => $token_sha256,
				'expires_at'          => max( time() + 60, (int) $expires_at ),
				'operation'           => $this->filter_operation( $operation ),
			)
		);
	}

	public function publish( array $operation ) {
		$current = $this->read_file();
		if ( ! is_array( $current ) || empty( $current['operation_id'] ) ) {
			return false;
		}
		if ( ! isset( $operation['id'] ) || ! hash_equals( (string) $current['operation_id'], (string) $operation['id'] ) ) {
			return false;
		}
		$current['operation'] = $this->filter_operation( $operation );
		return $this->write( $current );
	}

	public function read_authenticated( $operation_id, $token ) {
		$operation_id = (string) $operation_id;
		$token        = (string) $token;
		if ( ! preg_match( '/^[A-Za-z0-9-]{16,128}$/', $operation_id ) || ! preg_match( '/^[A-Za-z0-9_-]{40,128}$/', $token ) ) {
			return false;
		}

		$current = $this->read_file();
		if ( ! is_array( $current ) || empty( $current['operation_id'] ) || empty( $current['token_sha256'] ) || empty( $current['expires_at'] ) ) {
			return false;
		}
		if ( time() > (int) $current['expires_at'] || ! hash_equals( (string) $current['operation_id'], $operation_id ) ) {
			return false;
		}
		if ( ! hash_equals( (string) $current['token_sha256'], hash( 'sha256', $token ) ) ) {
			return false;
		}

		return isset( $current['operation'] ) && is_array( $current['operation'] ) ? $current['operation'] : false;
	}

	public function get_state_path() {
		return $this->directory . '/' . self::STATE_BASENAME;
	}

	private function filter_operation( array $operation ) {
		$allowed = array(
			'id', 'kind', 'status', 'stage', 'progress', 'message', 'scope',
			'started_at', 'updated_at', 'heartbeat_at', 'heartbeat_sequence',
			'finished_at', 'target_mutated', 'rollback_required', 'result', 'data',
			'cleanup_verified', 'recovery_artifacts',
		);
		return array_intersect_key( $operation, array_flip( $allowed ) );
	}

	private function read_file() {
		$path = $this->get_state_path();
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 2 || $size > 1048576 ) {
			return false;
		}

		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		try {
			if ( ! flock( $handle, LOCK_SH ) ) {
				return false;
			}
			$data = json_decode( (string) stream_get_contents( $handle ), true );
			return is_array( $data ) ? $data : false;
		} finally {
			@flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	private function write( array $state ) {
		if ( ! $this->prepare_directory() ) {
			return false;
		}
		$path = $this->get_state_path();
		if ( is_link( $path ) ) {
			return false;
		}
		$encoded = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded || strlen( $encoded ) > 1048576 ) {
			return false;
		}

		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return false;
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return false;
			}
			ftruncate( $handle, 0 );
			rewind( $handle );
			$written = fwrite( $handle, $encoded );
			fflush( $handle );
			@chmod( $path, 0600 );
			return strlen( $encoded ) === $written;
		} finally {
			@flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	private function prepare_directory() {
		if ( is_link( $this->directory ) ) {
			return false;
		}
		if ( ! is_dir( $this->directory ) && ! @mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
			return false;
		}
		@chmod( $this->directory, 0700 );

		$index = $this->directory . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
		}
		$htaccess = $this->directory . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", LOCK_EX );
		}
		return true;
	}
}
