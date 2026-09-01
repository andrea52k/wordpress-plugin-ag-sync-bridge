<?php
namespace AGSyncBridge;

use CurlFile;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Http_Client {
	const CHUNK_THRESHOLD_BYTES = 10485760;
	const DOWNLOAD_CHUNK_SIZE_BYTES = 8388608;
	const UPLOAD_CHUNK_SIZE_BYTES   = 1048576;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var bool
	 */
	private $use_legacy_signatures = false;

	/**
	 * @var array<string,int>
	 */
	private static $legacy_route_timestamps = array();

	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function test_connection() {
		return $this->request_json( 'GET', '/ag-sync-bridge/v1/status' );
	}

	public function remote_doctor( $required_bytes = 0, $deep = false ) {
		return $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/doctor',
			array(
				'required_bytes' => max( 0, (int) $required_bytes ),
				'deep'           => $deep ? 1 : 0,
			)
		);
	}

	public function create_remote_snapshot( $type = 'manual-remote-snapshot', $cancellation_check = null, $operation_callback = null, array $paths = array() ) {
		$scope = empty( $paths ) ? 'full' : 'partial';
		$result = $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/snapshot/create',
			array(
				'type'  => $type,
				'async' => true,
				'scope' => $scope,
				'paths' => array_values( $paths ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->normalize_remote_snapshot_create_error( $result );
		}

		if ( ! empty( $result['accepted'] ) && ! empty( $result['operation_id'] ) ) {
			$operation_id = (string) $result['operation_id'];
			if ( is_callable( $operation_callback ) ) {
				call_user_func( $operation_callback, $operation_id, 'snapshot' );
			}
			return $this->wait_for_remote_snapshot( $operation_id, $cancellation_check );
		}

		return $result;
	}

	public function get_latest_snapshot() {
		return $this->request_json( 'GET', '/ag-sync-bridge/v1/snapshot/latest' );
	}

	public function delete_remote_snapshot_exact( $operation_id, $basename, $sha256, $manifest_id, $manifest_sha256 ) {
		return $this->request_json( 'POST', '/ag-sync-bridge/v1/snapshot/delete-exact', array( 'operation_id'=>(string)$operation_id, 'basename'=>(string)$basename, 'sha256'=>strtolower((string)$sha256), 'manifest_id'=>(string)$manifest_id, 'manifest_sha256'=>strtolower((string)$manifest_sha256) ) );
	}

	public function create_remote_backup( $scope = 'full', array $paths = array() ) {
		$result = $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/backup/create',
			array(
				'type'  => 'pre-push-backup',
				'scope' => (string) $scope,
				'paths' => array_values( $paths ),
			)
		);

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$error_data = $result->get_error_data();
		$body       = is_array( $error_data ) ? (string) array_get( $error_data, 'body', '' ) : '';
		$decoded    = json_decode( $body, true );

		if ( is_array( $decoded ) && Remote_Backup_Result::STATUS_FAILED === array_get( $decoded, 'status', '' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_backup_failed',
				(string) array_get( $decoded, 'message', __( 'Remote pre-push backup failed.', 'ag-sync-bridge' ) ),
				array( 'remote_backup' => $decoded )
			);
		}

		return $result;
	}

	public function cleanup_remote_storage( array $args = array() ) {
		return $this->request_json( 'POST', '/ag-sync-bridge/v1/maintenance/cleanup', $args );
	}

	public function audit_remote_storage() {
		return $this->request_json( 'GET', '/ag-sync-bridge/v1/maintenance/storage-audit' );
	}

	public function cancel_remote_operation( $operation_id, $kind ) {
		return $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/operation/cancel',
			array(
				'operation_id' => (string) $operation_id,
				'kind'         => sanitize_key( (string) $kind ),
			)
		);
	}

	public function reconcile_remote_operation( array $args ) {
		return $this->request_json( 'POST', '/ag-sync-bridge/v1/operation/reconcile', $args );
	}

	public function update_remote_bridge( $version, $sha256, $expected_current_version, $confirmation, $recovery_hotfix = false ) {
		return $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/maintenance/update-bridge',
			array(
				'version'                  => (string) $version,
				'sha256'                   => strtolower( (string) $sha256 ),
				'expected_current_version' => (string) $expected_current_version,
				'confirmation'             => (string) $confirmation,
				'recovery_hotfix'          => $recovery_hotfix ? 1 : 0,
			)
		);
	}

	public function enable_remote_backups( $confirmation ) {
		return $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/maintenance/enable-remote-backups',
			array(
				'confirmation' => (string) $confirmation,
			)
		);
	}

	public function download_snapshot( $basename, $destination_file, $cancellation_check = null ) {
		if ( $this->is_cancel_requested( $cancellation_check, 'download-prepare' ) ) {
			return $this->cancellation_error( 'download-prepare' );
		}
		$this->logger->info( 'Using raw chunked snapshot download.', array( 'snapshot' => $basename ) );
		$raw_chunked = $this->download_snapshot_in_raw_chunks( $basename, $destination_file, $cancellation_check );

		if ( ! is_wp_error( $raw_chunked ) ) {
			return $raw_chunked;
		}

		$this->logger->warning( 'Raw chunked snapshot download failed. Falling back to JSON chunked download.', array( 'error' => $raw_chunked->get_error_message(), 'snapshot' => $basename ) );

		$this->logger->info( 'Using JSON chunked snapshot download.', array( 'snapshot' => $basename ) );
		$chunked = $this->download_snapshot_in_chunks( $basename, $destination_file, $cancellation_check );

		if ( ! is_wp_error( $chunked ) ) {
			return $chunked;
		}

		$this->logger->warning( 'Chunked snapshot download failed. Falling back to streamed download.', array( 'error' => $chunked->get_error_message(), 'snapshot' => $basename ) );

		$route   = '/ag-sync-bridge/v1/snapshot/download';
		$url     = $this->build_rest_url( $route ) . '?snapshot=' . rawurlencode( $basename );
		$headers = $this->build_headers( 'GET', $route );

		$response = wp_remote_get(
			$url,
			array(
				'headers'  => $headers,
				'timeout'  => $this->config->get_request_timeout(),
				'stream'   => true,
				'filename' => $destination_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $this->should_fallback_to_chunked_download( $response ) ) {
				$this->logger->warning( 'Streamed snapshot download failed. Falling back to chunked download.', array( 'error' => $response->get_error_message(), 'snapshot' => $basename ) );
				return $this->download_snapshot_in_chunks( $basename, $destination_file, $cancellation_check );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$error = new WP_Error(
				'ag_sync_bridge_remote_download',
				sprintf( 'Remote snapshot download failed with status %d.', $code ),
				array( 'body' => wp_remote_retrieve_body( $response ) )
			);
			if ( $this->should_fallback_to_chunked_download( $error, $code ) ) {
				$this->logger->warning( 'Streamed snapshot download returned HTTP error. Falling back to chunked download.', array( 'status' => $code, 'snapshot' => $basename ) );
				return $this->download_snapshot_in_chunks( $basename, $destination_file, $cancellation_check );
			}
			return $error;
		}

		return array(
			'path' => normalize_path( $destination_file ),
		);
	}

	private function download_snapshot_in_raw_chunks( $basename, $destination_file, $cancellation_check = null ) {
		$route  = '/ag-sync-bridge/v1/snapshot/download-raw-chunk';
		$offset = 0;
		$sha256 = '';
		$handle = fopen( $destination_file, 'wb' );

		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_raw_chunk_download_open_failed', __( 'Unable to open local destination file for snapshot download.', 'ag-sync-bridge' ) );
		}

		try {
			while ( true ) {
				if ( $this->is_cancel_requested( $cancellation_check, 'download-chunk' ) ) {
					return $this->cancellation_error( 'download-chunk' );
				}
				$url = $this->build_rest_url( $route ) . '?snapshot=' . rawurlencode( $basename ) . '&offset=' . rawurlencode( (string) $offset ) . '&length=' . rawurlencode( (string) self::DOWNLOAD_CHUNK_SIZE_BYTES );
				$this->logger->info( 'Downloading raw snapshot chunk.', array( 'snapshot' => $basename, 'offset' => $offset, 'length' => self::DOWNLOAD_CHUNK_SIZE_BYTES ) );
				$result = function_exists( 'curl_init' )
					? $this->download_raw_chunk_via_curl( $url, $route )
					: $this->download_raw_chunk_via_wp_http( $url, $route );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$chunk = (string) array_get( $result, 'data', '' );
				if ( '' !== $chunk && false === fwrite( $handle, $chunk ) ) {
					return new WP_Error( 'ag_sync_bridge_raw_chunk_download_write_failed', __( 'Unable to write downloaded snapshot chunk.', 'ag-sync-bridge' ) );
				}

				$offset += strlen( $chunk );
				$sha256 = (string) array_get( $result, 'sha256', $sha256 );
				$this->logger->info( 'Raw snapshot chunk downloaded.', array( 'snapshot' => $basename, 'downloaded_bytes' => $offset, 'complete' => ! empty( $result['complete'] ) ) );

				if ( ! empty( $result['complete'] ) ) {
					break;
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( $sha256 && hash_file( 'sha256', $destination_file ) !== $sha256 ) {
			return new WP_Error( 'ag_sync_bridge_raw_chunk_download_checksum_failed', __( 'Downloaded snapshot checksum failed.', 'ag-sync-bridge' ) );
		}

		return array(
			'path' => normalize_path( $destination_file ),
		);
	}

	private function download_snapshot_in_chunks( $basename, $destination_file, $cancellation_check = null ) {
		$route  = '/ag-sync-bridge/v1/snapshot/download-chunk';
		$offset = 0;
		$sha256 = '';
		$handle = fopen( $destination_file, 'wb' );

		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_chunk_download_open_failed', __( 'Unable to open local destination file for snapshot download.', 'ag-sync-bridge' ) );
		}

		try {
			while ( true ) {
				if ( $this->is_cancel_requested( $cancellation_check, 'download-chunk' ) ) {
					return $this->cancellation_error( 'download-chunk' );
				}
				$url      = $this->build_rest_url( $route ) . '?snapshot=' . rawurlencode( $basename ) . '&offset=' . rawurlencode( (string) $offset ) . '&length=' . rawurlencode( (string) self::DOWNLOAD_CHUNK_SIZE_BYTES );
				$this->logger->info( 'Downloading snapshot chunk.', array( 'snapshot' => $basename, 'offset' => $offset, 'length' => self::DOWNLOAD_CHUNK_SIZE_BYTES ) );
				$result   = function_exists( 'curl_init' )
					? $this->download_chunk_via_curl( $url, $route )
					: $this->download_chunk_via_wp_http( $url, $route );

				if ( is_wp_error( $result ) ) {
					return new WP_Error( 'ag_sync_bridge_chunk_download_failed', $this->normalize_download_error( $result ), $result->get_error_data() );
				}

				$chunk = base64_decode( (string) array_get( $result, 'data', '' ), true );
				if ( false === $chunk ) {
					return new WP_Error( 'ag_sync_bridge_chunk_download_decode_failed', __( 'Remote snapshot chunk was not valid base64.', 'ag-sync-bridge' ) );
				}

				if ( '' !== $chunk && false === fwrite( $handle, $chunk ) ) {
					return new WP_Error( 'ag_sync_bridge_chunk_download_write_failed', __( 'Unable to write downloaded snapshot chunk.', 'ag-sync-bridge' ) );
				}

				$offset += strlen( $chunk );
				$sha256 = (string) array_get( $result, 'sha256', $sha256 );
				$this->logger->info( 'Snapshot chunk downloaded.', array( 'snapshot' => $basename, 'downloaded_bytes' => $offset, 'complete' => ! empty( $result['complete'] ) ) );

				if ( ! empty( $result['complete'] ) ) {
					break;
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( $sha256 && hash_file( 'sha256', $destination_file ) !== $sha256 ) {
			return new WP_Error( 'ag_sync_bridge_chunk_download_checksum_failed', __( 'Downloaded snapshot checksum failed.', 'ag-sync-bridge' ) );
		}

		return array(
			'path' => normalize_path( $destination_file ),
		);
	}

	private function download_chunk_via_wp_http( $url, $route ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array_merge(
					$this->build_headers( 'GET', $route ),
					array(
						'Accept'     => 'application/json,*/*;q=0.8',
						'User-Agent' => $this->get_user_agent(),
					)
				),
				'timeout' => $this->config->get_request_timeout(),
			)
		);

		return $this->decode_json_response( $response );
	}

	private function download_chunk_via_curl( $url, $route ) {
		$headers = array_merge(
			$this->build_headers( 'GET', $route ),
			array(
				'Accept' => 'application/json,*/*;q=0.8',
			)
		);
		$curl    = curl_init();

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $this->config->get_request_timeout(),
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER     => $this->flatten_headers( $headers ),
				CURLOPT_USERAGENT      => $this->get_user_agent(),
			)
		);

		$body      = curl_exec( $curl );
		$error     = curl_error( $curl );
		$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		if ( $error ) {
			return new WP_Error( 'ag_sync_bridge_chunk_download_curl', $error );
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error( 'ag_sync_bridge_chunk_download_http', sprintf( 'Remote chunk download failed with status %d.', $http_code ), array( 'body' => (string) $body ) );
		}

		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ag_sync_bridge_chunk_download_json', __( 'Remote chunk endpoint returned invalid JSON.', 'ag-sync-bridge' ) );
		}

		return $data;
	}

	private function download_raw_chunk_via_wp_http( $url, $route ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array_merge(
					$this->build_headers( 'GET', $route ),
					array(
						'Accept'     => 'application/octet-stream,*/*;q=0.8',
						'User-Agent' => $this->get_user_agent(),
					)
				),
				'timeout' => $this->config->get_request_timeout(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ag_sync_bridge_raw_chunk_download_http', sprintf( 'Remote raw chunk download failed with status %d.', $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
		}

		$headers = wp_remote_retrieve_headers( $response );
		return array(
			'data'     => wp_remote_retrieve_body( $response ),
			'complete' => $this->truthy_header( $headers, 'x-agsb-complete' ),
			'sha256'   => (string) $this->header_value( $headers, 'x-agsb-sha256' ),
		);
	}

	private function download_raw_chunk_via_curl( $url, $route ) {
		$headers = array_merge(
			$this->build_headers( 'GET', $route ),
			array(
				'Accept' => 'application/octet-stream,*/*;q=0.8',
			)
		);
		$curl    = curl_init();

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_TIMEOUT        => $this->config->get_request_timeout(),
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER     => $this->flatten_headers( $headers ),
				CURLOPT_USERAGENT      => $this->get_user_agent(),
			)
		);

		$response    = curl_exec( $curl );
		$error       = curl_error( $curl );
		$http_code   = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
		curl_close( $curl );

		if ( $error ) {
			return new WP_Error( 'ag_sync_bridge_raw_chunk_download_curl', $error );
		}

		$response    = (string) $response;
		$raw_headers = substr( $response, 0, $header_size );
		$body        = substr( $response, $header_size );
		$headers     = $this->parse_raw_headers( $raw_headers );

		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error( 'ag_sync_bridge_raw_chunk_download_http', sprintf( 'Remote raw chunk download failed with status %d.', $http_code ), array( 'body' => $body ) );
		}

		return array(
			'data'     => $body,
			'complete' => $this->truthy_header( $headers, 'x-agsb-complete' ),
			'sha256'   => (string) $this->header_value( $headers, 'x-agsb-sha256' ),
		);
	}

	private function parse_raw_headers( $raw_headers ) {
		$blocks = preg_split( "/\r\n\r\n|\n\n|\r\r/", trim( (string) $raw_headers ) );
		$block  = $blocks ? end( $blocks ) : '';
		$parsed = array();

		foreach ( preg_split( "/\r\n|\n|\r/", (string) $block ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $line, 2 );
			$parsed[ strtolower( trim( $name ) ) ] = trim( $value );
		}

		return $parsed;
	}

	private function truthy_header( $headers, $name ) {
		$value = strtolower( (string) $this->header_value( $headers, $name ) );
		return in_array( $value, array( '1', 'true', 'yes' ), true );
	}

	private function header_value( $headers, $name ) {
		$name = strtolower( $name );
		if ( is_array( $headers ) ) {
			return array_get( $headers, $name, '' );
		}
		if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
			return $headers->offsetGet( $name );
		}
		return '';
	}

	private function get_user_agent() {
		return 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
	}

	private function should_fallback_to_chunked_download( WP_Error $error, $status_code = 0 ) {
		if ( in_array( (int) $status_code, array( 404, 408, 409, 429, 500, 502, 503, 504 ), true ) ) {
			return true;
		}

		$message = $error->get_error_message();
		return false !== stripos( $message, 'cURL error 18' )
			|| false !== stripos( $message, 'transfer closed' )
			|| false !== stripos( $message, 'Operation timed out' )
			|| false !== stripos( $message, 'cURL error 28' )
			|| false !== stripos( $message, 'cURL error 56' );
	}

	public function upload_snapshot( $file_path, array $meta, callable $progress_callback = null, $cancellation_check = null ) {
		$route = '/ag-sync-bridge/v1/snapshot/upload';
		$url   = $this->build_rest_url( $route );
		$size  = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;

		if ( $this->is_cancel_requested( $cancellation_check, 'upload-prepare' ) ) {
			return $this->cancellation_error( 'upload-prepare' );
		}

		if ( $progress_callback ) {
			call_user_func( $progress_callback, 0, 1, __( 'Preparazione upload snapshot...', 'ag-sync-bridge' ) );
		}

		if ( $size > self::CHUNK_THRESHOLD_BYTES ) {
			$this->logger->info(
				'Using chunked snapshot upload.',
				array(
					'basename'   => basename( $file_path ),
					'size_bytes' => $size,
				)
			);

			return $this->upload_in_chunks( $file_path, $meta, $progress_callback, $cancellation_check );
		}

		if ( function_exists( 'curl_init' ) ) {
			if ( $this->is_cancel_requested( $cancellation_check, 'upload-request' ) ) {
				return $this->cancellation_error( 'upload-request' );
			}
			$result = $this->upload_via_curl( $url, $route, $file_path, $meta );
			if ( ! is_wp_error( $result ) ) {
				if ( $progress_callback ) {
					call_user_func( $progress_callback, 1, 1, __( 'Upload snapshot completato.', 'ag-sync-bridge' ) );
				}
				return $result;
			}

			$this->logger->warning( 'cURL upload failed. Falling back to raw upload.', array( 'error' => $result->get_error_message() ) );
		}

		$headers = $this->build_headers( 'POST', $route );
		$headers['Content-Type']  = 'application/octet-stream';
		$headers['X-AGSB-Filename'] = basename( $file_path );
		$headers['X-AGSB-Sha256']   = array_get( $meta, 'sha256', '' );
		$headers['X-AGSB-Meta']     = rawurlencode( wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => file_get_contents( $file_path ),
				'timeout' => $this->config->get_request_timeout(),
			)
		);

		$result = $this->decode_json_response( $response );

		if ( is_wp_error( $result ) ) {
			$this->logger->warning( 'Raw snapshot upload failed. Falling back to chunked upload.', array( 'error' => $result->get_error_message() ) );
			return $this->upload_in_chunks( $file_path, $meta, $progress_callback, $cancellation_check );
		}

		if ( $progress_callback ) {
			call_user_func( $progress_callback, 1, 1, __( 'Upload snapshot completato.', 'ag-sync-bridge' ) );
		}

		return $result;
	}

	public function trigger_remote_import( $snapshot_basename, $expected_sha256, $allow_partial_snapshot = false, $cancellation_check = null, $operation_callback = null, array $expected_partial_paths = array(), $recovery_import = false, $status_callback = null ) {
		$result = $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/snapshot/import',
			array(
				'snapshot'               => basename( $snapshot_basename ),
				'expected_sha256'        => $expected_sha256,
				'async'                  => true,
				'allow_partial_snapshot' => $allow_partial_snapshot ? 1 : 0,
				'expected_partial_paths' => array_values( $expected_partial_paths ),
				'recovery_import'        => $recovery_import ? 1 : 0,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['accepted'] ) || empty( $result['operation_id'] ) ) {
			return $result;
		}

		$operation_id = (string) $result['operation_id'];
		$monitor_token = isset( $result['monitor_token'] ) ? (string) $result['monitor_token'] : '';
		$monitor_path  = isset( $result['monitor_path'] ) ? (string) $result['monitor_path'] : '';
		$this->persist_remote_import_monitor( $operation_id, $monitor_token, $monitor_path );
		if ( is_callable( $operation_callback ) ) {
			call_user_func( $operation_callback, $operation_id, 'import' );
		}
		return $this->wait_for_remote_import( $operation_id, $cancellation_check, $monitor_token, $monitor_path, $status_callback );
	}

	/**
	 * Refresh the durable maintenance-safe monitor used by the admin screen.
	 * The monitor secret is stored in its own non-autoloaded option and is never
	 * included in the normal state payload returned to the browser.
	 *
	 * @return array|WP_Error Empty when no import monitor is active.
	 */
	public function refresh_remote_import_monitor() {
		$monitor = get_option( Config::OPTION_REMOTE_MONITOR, array() );
		$monitor = is_array( $monitor ) ? $monitor : array();
		$operation_id = (string) array_get( $monitor, 'operation_id', '' );
		$monitor_token = (string) array_get( $monitor, 'monitor_token', '' );
		$monitor_path = (string) array_get( $monitor, 'monitor_path', '' );

		if ( '' === $operation_id || '' === $monitor_token || '' === $monitor_path ) {
			return array();
		}

		$status = $this->request_direct_import_status( $operation_id, $monitor_token, $monitor_path );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$operation = array_get( $status, 'remote_import_operation', array() );
		if ( ! is_array( $operation ) || $operation_id !== (string) array_get( $operation, 'id', '' ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_monitor_mismatch', __( 'Remote import monitor returned a different operation.', 'ag-sync-bridge' ) );
		}

		$monitor['last_checked_at'] = gmdate( 'c' );
		$monitor['last_status'] = (string) array_get( $operation, 'status', '' );
		update_option( Config::OPTION_REMOTE_MONITOR, $monitor, false );

		if ( in_array( $monitor['last_status'], array( 'complete', 'error', 'cancelled', 'rollback_required', 'reconciled' ), true ) ) {
			$this->config->set_state_value(
				'last_remote_import_monitor',
				array(
					'operation_id' => $operation_id,
					'status'       => $monitor['last_status'],
					'stage'        => (string) array_get( $operation, 'stage', '' ),
					'progress'     => (int) array_get( $operation, 'progress', 0 ),
					'checked_at'   => $monitor['last_checked_at'],
				)
			);
			delete_option( Config::OPTION_REMOTE_MONITOR );
		}

		return $operation;
	}

	private function persist_remote_import_monitor( $operation_id, $monitor_token, $monitor_path ) {
		if ( '' === (string) $operation_id || '' === (string) $monitor_token || '' === (string) $monitor_path ) {
			return;
		}

		update_option(
			Config::OPTION_REMOTE_MONITOR,
			array(
				'operation_id' => (string) $operation_id,
				'monitor_token' => (string) $monitor_token,
				'monitor_path' => (string) $monitor_path,
				'created_at'   => gmdate( 'c' ),
				'last_checked_at' => '',
				'last_status'  => 'queued',
			),
			false
		);
	}

	private function wait_for_remote_snapshot( $operation_id, $cancellation_check = null ) {
		$started_at       = time();
		$timeout          = max( 60, $this->config->get_request_timeout() );
		$transient_errors = 0;
		$cancel_sent      = false;

		while ( ( time() - $started_at ) < $timeout ) {
			if ( ! $cancel_sent && $this->is_cancel_requested( $cancellation_check, 'remote-snapshot' ) ) {
				$cancel_sent = true;
				$cancel      = $this->cancel_remote_operation( $operation_id, 'snapshot' );
				if ( is_wp_error( $cancel ) ) {
					return $cancel;
				}
			}
			sleep( 5 );

			$status = $this->request_json( 'GET', '/ag-sync-bridge/v1/operation/status' );
			if ( is_wp_error( $status ) ) {
				if ( $this->is_retryable_remote_import_poll_error( $status ) ) {
					if ( ( time() - $last_activity_at ) > $stale_timeout ) {
						return new WP_Error(
							'ag_sync_bridge_remote_import_monitor_unreachable',
							__( 'Remote import monitor stayed unreachable beyond the heartbeat timeout.', 'ag-sync-bridge' ),
							array( 'operation_id' => $operation_id, 'stale_seconds' => time() - $last_activity_at )
						);
					}
					$transient_errors++;
					$this->logger->warning(
						'Remote snapshot status polling hit a transient error. Retrying.',
						array(
							'operation_id' => $operation_id,
							'attempt'      => $transient_errors,
							'status'       => $this->get_remote_http_error_status( $status ),
							'error'        => $status->get_error_message(),
						)
					);
					continue;
				}

				return $status;
			}

			$operation = array_get( $status, 'remote_snapshot_operation', array() );
			if ( empty( $operation ) || (string) array_get( $operation, 'id', '' ) !== $operation_id ) {
				continue;
			}

			$state = (string) array_get( $operation, 'status', '' );
			if ( 'complete' === $state ) {
				return array_get( $operation, 'result', array() );
			}

			if ( 'error' === $state ) {
				return new WP_Error(
					'ag_sync_bridge_remote_snapshot_async_failed',
					(string) array_get( $operation, 'message', __( 'Remote snapshot failed.', 'ag-sync-bridge' ) ),
					array_get( $operation, 'data', null )
				);
			}

			if ( 'cancelled' === $state || 'rollback_required' === $state ) {
				return new WP_Error(
					'ag_sync_bridge_remote_snapshot_cancelled',
					(string) array_get( $operation, 'message', __( 'Remote snapshot was cancelled.', 'ag-sync-bridge' ) ),
					$operation
				);
			}
		}

		return new WP_Error(
			'ag_sync_bridge_remote_snapshot_timeout',
			__( 'Remote snapshot did not finish before the request timeout.', 'ag-sync-bridge' ),
			array(
				'operation_id' => $operation_id,
				'timeout'      => $timeout,
			)
		);
	}

	private function normalize_remote_snapshot_create_error( WP_Error $error ) {
		$data = $error->get_error_data();
		$body = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';

		if ( false !== stripos( $error->get_error_message(), 'status 404' ) || false !== stripos( $body, 'rest_no_route' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_snapshot_async_missing',
				__( 'Il live non espone ancora la creazione snapshot asincrona. Aggiorna AG Sync Bridge sul live alla versione 0.1.28 o successiva e riprova il pull.', 'ag-sync-bridge' ),
				$data
			);
		}

		if ( false !== stripos( $error->get_error_message(), 'timeout' ) || false !== stripos( $body, 'Request Timeout' ) || false !== stripos( $body, 'takes too long to process' ) ) {
			return new WP_Error(
				'ag_sync_bridge_remote_snapshot_sync_timeout',
				__( 'Il live ha interrotto la creazione snapshot prima della fine. Aggiorna AG Sync Bridge sul live alla versione 0.1.28 o successiva, cosi la snapshot verra creata in asincrono.', 'ag-sync-bridge' ),
				$data
			);
		}

		return $error;
	}

	private function wait_for_remote_import( $operation_id, $cancellation_check = null, $monitor_token = '', $monitor_path = '', $status_callback = null ) {
		$started_at       = time();
		$timeout          = max( 21600, $this->config->get_request_timeout() );
		$stale_timeout    = max( 300, $this->config->get_request_timeout() );
		$last_activity_at = time();
		$last_heartbeat_sequence = -1;
		$transient_errors = 0;
		$queued_at        = null;
		$recovery_sent    = false;
		$cancel_sent      = false;

		while ( ( time() - $started_at ) < $timeout ) {
			if ( ! $cancel_sent && $this->is_cancel_requested( $cancellation_check, 'remote-import' ) ) {
				$cancel_sent = true;
				$cancel      = $this->cancel_remote_operation( $operation_id, 'import' );
				if ( is_wp_error( $cancel ) ) {
					return $cancel;
				}
			}
			sleep( 5 );

			$status = $this->request_json( 'GET', '/ag-sync-bridge/v1/operation/status' );
			if ( is_wp_error( $status ) ) {
				if ( 503 === $this->get_remote_http_error_status( $status ) && '' !== $monitor_token && '' !== $monitor_path ) {
					$direct_status = $this->request_direct_import_status( $operation_id, $monitor_token, $monitor_path );
					if ( ! is_wp_error( $direct_status ) ) {
						$status = $direct_status;
					}
				}
			}
			if ( is_wp_error( $status ) ) {
				if ( $this->is_retryable_remote_import_poll_error( $status ) ) {
					$transient_errors++;
					$this->logger->warning(
						'Remote import status polling hit a transient error. Retrying.',
						array(
							'operation_id' => $operation_id,
							'attempt'      => $transient_errors,
							'status'       => $this->get_remote_http_error_status( $status ),
							'error'        => $status->get_error_message(),
						)
					);
					continue;
				}

				return $status;
			}

			$operation = array_get( $status, 'remote_import_operation', array() );
			if ( empty( $operation ) || (string) array_get( $operation, 'id', '' ) !== $operation_id ) {
				continue;
			}
			if ( is_callable( $status_callback ) ) {
				call_user_func( $status_callback, $operation );
			}

			$state = (string) array_get( $operation, 'status', '' );
			$heartbeat_sequence = (int) array_get( $operation, 'heartbeat_sequence', 0 );
			if ( $heartbeat_sequence !== $last_heartbeat_sequence ) {
				$last_heartbeat_sequence = $heartbeat_sequence;
				$last_activity_at = time();
			}
			if ( 'queued' === $state ) {
				$queued_at = $queued_at ?: time();
				if ( ! $recovery_sent && ( time() - $queued_at ) >= 20 ) {
					$recovery_sent = true;
					$this->logger->warning( 'Remote import stayed queued; requesting authenticated recovery.', array( 'operation_id' => $operation_id ) );
					$recovery = $this->request_json(
						'POST',
						'/ag-sync-bridge/v1/operation/run-pending-import',
						array( 'operation_id' => $operation_id )
					);
					if ( is_wp_error( $recovery ) ) {
						$direct_status = ( '' !== $monitor_token && '' !== $monitor_path )
							? $this->request_direct_import_status( $operation_id, $monitor_token, $monitor_path )
							: $recovery;
						$direct_operation = is_wp_error( $direct_status ) ? array() : array_get( $direct_status, 'remote_import_operation', array() );
						if ( is_array( $direct_operation ) && $operation_id === (string) array_get( $direct_operation, 'id', '' ) && in_array( (string) array_get( $direct_operation, 'status', '' ), array( 'queued', 'running', 'cancel_requested' ), true ) ) {
							$this->logger->warning( 'Authenticated recovery request timed out, but the remote heartbeat is active; monitoring continues.', array( 'operation_id' => $operation_id, 'error' => $recovery->get_error_message() ) );
							$operation = $direct_operation;
							$state = (string) array_get( $operation, 'status', '' );
							if ( is_callable( $status_callback ) ) {
								call_user_func( $status_callback, $operation );
							}
						} else {
							return $recovery;
						}
					} else {
						$operation = array_get( $recovery, 'remote_import_operation', array() );
						$state     = (string) array_get( $operation, 'status', '' );
					}
				}
			}
			if ( 'complete' === $state ) {
				return array_get( $operation, 'result', array() );
			}

			if ( 'error' === $state ) {
				return new WP_Error(
					'ag_sync_bridge_remote_import_async_failed',
					(string) array_get( $operation, 'message', __( 'Remote import failed.', 'ag-sync-bridge' ) ),
					array_get( $operation, 'data', null )
				);
			}

			if ( 'cancelled' === $state || 'rollback_required' === $state ) {
				return new WP_Error(
					'ag_sync_bridge_remote_import_cancelled',
					(string) array_get( $operation, 'message', __( 'Remote import was cancelled.', 'ag-sync-bridge' ) ),
					$operation
				);
			}

			if ( in_array( $state, array( 'queued', 'running', 'cancel_requested' ), true ) && ( time() - $last_activity_at ) > $stale_timeout ) {
				return new WP_Error(
					'ag_sync_bridge_remote_import_stalled',
					__( 'Remote import heartbeat stopped before completion.', 'ag-sync-bridge' ),
					array( 'operation_id' => $operation_id, 'stale_seconds' => time() - $last_activity_at )
				);
			}
		}

		return new WP_Error(
			'ag_sync_bridge_remote_import_timeout',
			__( 'Remote import did not finish before the request timeout.', 'ag-sync-bridge' ),
			array(
				'operation_id' => $operation_id,
				'timeout'      => $timeout,
			)
		);
	}

	private function request_direct_import_status( $operation_id, $monitor_token, $monitor_path ) {
		$monitor_path = (string) $monitor_path;
		$required_suffix = '/plugins/ag-sync-bridge/direct-operation-status.php';
		if ( '/' !== substr( $monitor_path, 0, 1 ) || false !== strpos( $monitor_path, '..' ) || false !== strpos( $monitor_path, '?' ) || false !== strpos( $monitor_path, '#' ) || $required_suffix !== substr( $monitor_path, -strlen( $required_suffix ) ) ) {
			return new WP_Error( 'ag_sync_bridge_direct_monitor_path_invalid', __( 'Remote import monitor path is invalid.', 'ag-sync-bridge' ) );
		}

		$remote = wp_parse_url( $this->config->get_remote_url() );
		if ( ! is_array( $remote ) || empty( $remote['scheme'] ) || empty( $remote['host'] ) || ! in_array( strtolower( (string) $remote['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'ag_sync_bridge_direct_monitor_url_invalid', __( 'Remote import monitor URL is invalid.', 'ag-sync-bridge' ) );
		}
		$url = strtolower( (string) $remote['scheme'] ) . '://' . (string) $remote['host'];
		if ( ! empty( $remote['port'] ) ) {
			$url .= ':' . absint( $remote['port'] );
		}
		$url .= $monitor_path;

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'X-AGSB-Operation-ID'    => (string) $operation_id,
					'X-AGSB-Operation-Token' => (string) $monitor_token,
					'Accept'                  => 'application/json',
					'User-Agent'              => $this->get_user_agent(),
				),
				'timeout'     => min( 15, $this->config->get_request_timeout() ),
				'redirection' => 0,
			)
		);

		return $this->decode_json_response( $response );
	}

	private function upload_via_curl( $url, $route, $file_path, array $meta ) {
		$headers = $this->build_headers( 'POST', $route );
		$curl    = curl_init();

		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $this->config->get_request_timeout(),
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER     => $this->flatten_headers( $headers ),
				CURLOPT_POSTFIELDS     => array(
					'snapshot_file' => new CurlFile( $file_path, 'application/zip', basename( $file_path ) ),
					'snapshot_meta' => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			)
		);

		$body      = curl_exec( $curl );
		$error     = curl_error( $curl );
		$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		if ( $error ) {
			return new WP_Error( 'ag_sync_bridge_curl_upload', $this->normalize_upload_error( $error ) );
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error( 'ag_sync_bridge_upload_http', sprintf( 'Snapshot upload failed with status %d.', $http_code ) );
		}

		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ag_sync_bridge_upload_json', __( 'Remote upload returned invalid JSON.', 'ag-sync-bridge' ) );
		}

		return $data;
	}

	private function request_json( $method, $route, array $body = array() ) {
		$response = $this->dispatch_json_request( $method, $route, $body );
		$result   = $this->decode_json_response( $response );

		if ( in_array( $route, array( '/ag-sync-bridge/v1/backup/create', '/ag-sync-bridge/v1/snapshot/upload-finish' ), true ) && $this->is_transient_transport_error( $result ) ) {
			$this->logger->warning(
				'Remote JSON request failed with a transient transport error. Retrying once.',
				array(
					'route' => $route,
					'error' => $result->get_error_message(),
				)
			);
			sleep( 3 );
			$response = $this->dispatch_json_request( $method, $route, $body );
			$result   = $this->decode_json_response( $response );
		}

		if ( ! $this->requires_signed_body_route( $route ) && $this->should_retry_with_legacy_signature( $result ) ) {
			$this->use_legacy_signatures = true;
			$this->logger->info(
				'Remote uses legacy request signatures. Falling back for compatibility.',
				array(
					'route' => $route,
				)
			);

			$response = $this->dispatch_json_request( $method, $route, $body );
			$result   = $this->decode_json_response( $response );
		}

		return $result;
	}

	private function requires_signed_body_route( $route ) {
		return in_array(
			(string) $route,
			array(
				'/ag-sync-bridge/v1/backup/create',
				'/ag-sync-bridge/v1/snapshot/create',
				'/ag-sync-bridge/v1/snapshot/import',
			),
			true
		);
	}

	private function is_transient_transport_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$message = strtolower( $result->get_error_message() );
		foreach ( array( 'curl error 18', 'curl error 56', 'unexpected eof', 'ssl_read', 'connection reset', 'timed out', 'timeout', 'transfer closed' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_retryable_remote_import_poll_error( WP_Error $result ) {
		if ( $this->is_transient_transport_error( $result ) ) {
			return true;
		}

		$status = $this->get_remote_http_error_status( $result );
		if ( ! $status ) {
			return false;
		}

		return in_array( $status, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true );
	}

	private function get_remote_http_error_status( WP_Error $result ) {
		if ( 'ag_sync_bridge_remote_http' !== $result->get_error_code() ) {
			return 0;
		}

		$data = $result->get_error_data();
		if ( is_array( $data ) && ! empty( $data['status'] ) ) {
			return absint( $data['status'] );
		}

		if ( preg_match( '/status\s+(\d+)/i', $result->get_error_message(), $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	private function decode_json_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ag_sync_bridge_remote_http', sprintf( 'Remote request failed with status %d.', $code ), array( 'status' => $code, 'body' => $body ) );
		}

		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ag_sync_bridge_remote_json', __( 'Remote endpoint returned invalid JSON.', 'ag-sync-bridge' ) );
		}

		return $data;
	}

	private function build_rest_url( $route ) {
		$remote_url = $this->config->get_remote_url();
		return untrailingslashit( $remote_url ) . '/wp-json' . $route;
	}

	private function dispatch_json_request( $method, $route, array $body = array() ) {
		$encoded_body = empty( $body ) ? '' : wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return wp_remote_request(
			$this->build_rest_url( $route ),
			array(
				'method'  => strtoupper( $method ),
				'timeout' => $this->config->get_request_timeout(),
				'headers' => array_merge(
					$this->build_headers( $method, $route, $encoded_body ),
					array(
						'Content-Type' => 'application/json',
					)
				),
				'body'    => '' === $encoded_body ? null : $encoded_body,
			)
		);
	}

	private function build_headers( $method, $route, $body = '' ) {
		$timestamp = $this->get_request_timestamp( $method, $route );
		$nonce     = $this->use_legacy_signatures ? '' : wp_generate_uuid4();
		$body_hash = ! $this->use_legacy_signatures && '' !== (string) $body ? hash( 'sha256', (string) $body ) : '';
		$headers   = array(
			'X-AGSB-Timestamp' => (string) $timestamp,
			'X-AGSB-Signature' => $this->sign( $method, $route, $timestamp, $nonce, $body_hash ),
			'X-AGSB-Origin'    => home_url(),
			'Expect'           => '',
		);

		if ( '' !== $nonce ) {
			$headers['X-AGSB-Nonce'] = $nonce;
		}

		if ( '' !== $body_hash ) {
			$headers['X-AGSB-Body-SHA256'] = $body_hash;
		}

		return $headers;
	}

	private function get_request_timestamp( $method, $route ) {
		$timestamp = time();

		if ( ! $this->use_legacy_signatures ) {
			return $timestamp;
		}

		$key  = strtoupper( $method ) . ' ' . $route;
		$last = isset( self::$legacy_route_timestamps[ $key ] ) ? (int) self::$legacy_route_timestamps[ $key ] : 0;

		while ( $timestamp <= $last ) {
			$remaining = (int) ceil( ( ( $last + 1 ) - microtime( true ) ) * 1000000 );
			usleep( $remaining > 0 ? min( $remaining, 1000000 ) : 50000 );
			$timestamp = time();
		}

		self::$legacy_route_timestamps[ $key ] = $timestamp;

		return $timestamp;
	}

	private function sign( $method, $route, $timestamp, $nonce = '', $body_hash = '' ) {
		$payload = strtoupper( $method ) . "\n" . $route . "\n" . (int) $timestamp;
		if ( '' !== $nonce ) {
			$payload .= "\n" . $nonce;
		}
		if ( '' !== $body_hash ) {
			$payload .= "\n" . strtolower( (string) $body_hash );
		}

		return hash_hmac( 'sha256', $payload, $this->config->get_secret() );
	}

	private function should_retry_with_legacy_signature( $result ) {
		if ( $this->use_legacy_signatures || ! is_wp_error( $result ) ) {
			return false;
		}

		$data = $result->get_error_data();
		$body = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';

		if ( '' === $body ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		return is_array( $decoded ) && 'ag_sync_bridge_bad_signature' === array_get( $decoded, 'code', '' );
	}

	private function upload_in_chunks( $file_path, array $meta, callable $progress_callback = null, $cancellation_check = null ) {
		$route_chunk  = '/ag-sync-bridge/v1/snapshot/upload-chunk';
		$route_finish = '/ag-sync-bridge/v1/snapshot/upload-finish';
		$url_chunk    = $this->build_rest_url( $route_chunk );
		$upload_id    = wp_generate_uuid4();
		$filename     = basename( $file_path );
		$size         = (int) filesize( $file_path );
		$total_chunks = max( 1, (int) ceil( $size / self::UPLOAD_CHUNK_SIZE_BYTES ) );
		$handle       = fopen( $file_path, 'rb' );

		if ( false === $handle ) {
			return new WP_Error( 'ag_sync_bridge_upload_open_failed', __( 'Unable to open local snapshot for upload.', 'ag-sync-bridge' ) );
		}

		try {
			for ( $index = 0; $index < $total_chunks; $index++ ) {
				if ( $this->is_cancel_requested( $cancellation_check, 'upload-chunk' ) ) {
					$this->abort_chunked_upload( $upload_id );
					return $this->cancellation_error( 'upload-chunk' );
				}
				$chunk = fread( $handle, self::UPLOAD_CHUNK_SIZE_BYTES );

				if ( false === $chunk ) {
					return new WP_Error( 'ag_sync_bridge_chunk_read_failed', __( 'Unable to read local snapshot chunk.', 'ag-sync-bridge' ) );
				}

				$headers = array_merge(
					$this->build_headers( 'POST', $route_chunk ),
					array(
						'Content-Type'       => 'application/x-www-form-urlencoded; charset=UTF-8',
						'X-AGSB-Upload-Id'   => $upload_id,
						'X-AGSB-Filename'    => $filename,
						'X-AGSB-Chunk-Index' => (string) $index,
						'X-AGSB-Total-Chunks'=> (string) $total_chunks,
						'X-AGSB-Chunk-Sha256'=> hash( 'sha256', $chunk ),
					)
				);

				$result = $this->upload_chunk_with_retry( $url_chunk, $headers, $chunk, $index, $total_chunks );

				if ( is_wp_error( $result ) ) {
					$this->abort_chunked_upload( $upload_id );
					return new WP_Error( 'ag_sync_bridge_chunk_upload_failed', $this->normalize_chunk_error( $result ), $result->get_error_data() );
				}

				if ( $progress_callback ) {
					call_user_func(
						$progress_callback,
						$index + 1,
						$total_chunks,
						sprintf(
							/* translators: 1: current chunk, 2: total chunks */
							__( 'Upload chunk %1$d di %2$d...', 'ag-sync-bridge' ),
							$index + 1,
							$total_chunks
						)
					);
				}
			}
		} finally {
			fclose( $handle );
		}

		if ( $this->is_cancel_requested( $cancellation_check, 'upload-finish' ) ) {
			$this->abort_chunked_upload( $upload_id );
			return $this->cancellation_error( 'upload-finish' );
		}

		$finish = $this->request_json(
			'POST',
			$route_finish,
			array(
				'upload_id'       => $upload_id,
				'filename'        => $filename,
				'expected_sha256' => array_get( $meta, 'sha256', '' ),
				'total_chunks'    => $total_chunks,
				'meta'            => $meta,
			)
		);

		if ( is_wp_error( $finish ) ) {
			$this->abort_chunked_upload( $upload_id );
			return new WP_Error( 'ag_sync_bridge_chunk_finish_failed', $this->normalize_chunk_error( $finish ), $finish->get_error_data() );
		}

		if ( $progress_callback ) {
			call_user_func( $progress_callback, 1, 1, __( 'Upload snapshot completato.', 'ag-sync-bridge' ) );
		}

		return $finish;
	}

	private function abort_chunked_upload( $upload_id ) {
		if ( '' === (string) $upload_id ) {
			return;
		}

		$result = $this->request_json(
			'POST',
			'/ag-sync-bridge/v1/snapshot/upload-abort',
			array(
				'upload_id' => sanitize_key( (string) $upload_id ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logger->warning(
				'Unable to abort remote chunked upload after failure.',
				array(
					'upload_id' => $upload_id,
					'error'     => $result->get_error_message(),
				)
			);
		}
	}

	private function is_cancel_requested( $callback, $stage ) {
		return is_callable( $callback ) && (bool) call_user_func( $callback, $stage, false );
	}

	private function cancellation_error( $stage ) {
		return new WP_Error(
			'ag_sync_bridge_operation_cancelled',
			__( 'Transfer was cancelled before the target import started.', 'ag-sync-bridge' ),
			array(
				'cancelled'         => true,
				'rollback_required' => false,
				'stage'             => sanitize_key( $stage ),
			)
		);
	}

	private function upload_chunk_with_retry( $url, array $headers, $chunk, $index, $total_chunks ) {
		$attempts = 3;
		$result   = null;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => $headers,
					'body'    => array(
						'chunk_b64' => base64_encode( $chunk ),
					),
					'timeout' => $this->config->get_request_timeout(),
				)
			);

			$result = $this->decode_json_response( $response );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! $this->is_retryable_chunk_upload_error( $result ) || $attempt >= $attempts ) {
				return $result;
			}

			$this->logger->warning(
				'Snapshot chunk upload failed with a transient transport error. Retrying chunk.',
				array(
					'chunk'        => $index + 1,
					'total_chunks' => $total_chunks,
					'attempt'      => $attempt,
					'error'        => $result->get_error_message(),
				)
			);
			sleep( min( 10, $attempt * 3 ) );
		}

		return $result;
	}

	private function is_retryable_chunk_upload_error( WP_Error $result ) {
		if ( $this->is_transient_transport_error( $result ) ) {
			return true;
		}

		$data = $result->get_error_data();
		$body = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';
		$decoded = json_decode( $body, true );
		$code    = is_array( $decoded ) ? (string) array_get( $decoded, 'code', '' ) : '';

		if ( in_array( $code, array( 'ag_sync_bridge_chunk_write_failed', 'ag_sync_bridge_chunk_write_incomplete', 'ag_sync_bridge_chunk_dir_failed' ), true ) ) {
			return false;
		}

		$status = $this->get_remote_http_error_status( $result );
		return in_array( $status, array( 408, 425, 429, 500, 502, 503, 504 ), true );
	}

	private function normalize_upload_error( $message ) {
		$message = trim( (string) $message );

		if ( false !== stripos( $message, 'unexpected eof while reading' ) || false !== stripos( $message, 'SSL_read' ) || false !== stripos( $message, 'cURL error 56' ) ) {
			return __( 'Connessione interrotta durante l\'upload grande. Il plugin ha provato un fallback chunked; se l\'errore persiste controlla proxy/WAF, limiti PHP del live e timeout del server.', 'ag-sync-bridge' );
		}

		return $message;
	}

	private function normalize_chunk_error( WP_Error $error ) {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$body    = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';
		$decoded = json_decode( $body, true );
		$code    = is_array( $decoded ) ? (string) array_get( $decoded, 'code', '' ) : '';
		$remote_message = is_array( $decoded ) ? (string) array_get( $decoded, 'message', '' ) : '';

		if ( false !== stripos( $message, 'status 404' ) || false !== stripos( $body, 'rest_no_route' ) ) {
			return __( 'Il sito live non espone ancora gli endpoint di upload chunked. Aggiorna AG Sync Bridge anche sul live e riprova il push.', 'ag-sync-bridge' );
		}

		if ( in_array( $code, array( 'ag_sync_bridge_chunk_write_failed', 'ag_sync_bridge_chunk_write_incomplete', 'ag_sync_bridge_chunk_dir_failed' ), true ) ) {
			return trim(
				sprintf(
					/* translators: 1: remote error code, 2: remote error message */
					__( 'Il live non riesce a salvare i chunk dello snapshot (%1$s: %2$s). Controlla spazio disco, quota hosting e permessi di wp-content/ag-sync-bridge-data/temp/upload-chunks sul server live.', 'ag-sync-bridge' ),
					$code,
					$remote_message ? $remote_message : $message
				)
			);
		}

		if ( '' !== $code && '' !== $remote_message ) {
			return sprintf( '%s: %s', $code, $remote_message );
		}

		return $this->normalize_upload_error( $message );
	}

	private function normalize_download_error( WP_Error $error ) {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$body    = is_array( $data ) ? (string) array_get( $data, 'body', '' ) : '';

		if ( false !== stripos( $message, 'status 404' ) || false !== stripos( $body, 'rest_no_route' ) ) {
			return __( 'Il sito live non espone ancora gli endpoint di download chunked o non trova lo snapshot richiesto. Aggiorna AG Sync Bridge anche sul live e crea un nuovo snapshot prima del pull.', 'ag-sync-bridge' );
		}

		return $message;
	}

	private function flatten_headers( array $headers ) {
		$flattened = array();
		foreach ( $headers as $name => $value ) {
			$flattened[] = $name . ': ' . $value;
		}
		return $flattened;
	}
}
