<?php
namespace AGSyncBridge;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class V4MPG_Table_Client {
	/** @var Config */ private $config;
	/** @var Auth */ private $auth;

	public function __construct( Config $config, Auth $auth ) {
		$this->config = $config;
		$this->auth   = $auth;
	}

	public function request( $action, array $body ) {
		if ( ! in_array( $action, array( 'plan','backup','backup-page','backup-seal','backup-abort','deploy','verify','rollback','status','recover' ), true ) ) {
			throw new RuntimeException( 'Unsupported V4MPG table action.' );
		}
		$route   = '/ag-sync-bridge/v1/v4mpg-table/' . $action;
		$encoded = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > V4MPG_Table_Deploy_Service::MAX_BODY_BYTES ) {
			throw new RuntimeException( 'V4MPG table request is invalid or exceeds the size limit.' );
		}
		$response = wp_remote_post(
			untrailingslashit( $this->config->get_remote_url() ) . '/wp-json' . $route,
			array(
				'timeout' => max( 60, $this->config->get_request_timeout() ),
				'headers' => array_merge( $this->auth->build_headers( 'POST', $route, $encoded ), array( 'Content-Type' => 'application/json', 'Expect' => '' ) ),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Invalid remote response.';
			throw new RuntimeException( 'Remote V4MPG table request failed (' . $code . '): ' . $message );
		}
		return $data;
	}
}
