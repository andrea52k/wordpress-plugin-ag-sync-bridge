<?php
namespace AGSyncBridge;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth {
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

	public function build_headers( $method, $route, $body = '' ) {
		$timestamp = time();
		$nonce     = wp_generate_uuid4();
		$body_hash = '' !== (string) $body ? hash( 'sha256', (string) $body ) : '';
		return array(
			'X-AGSB-Timestamp' => (string) $timestamp,
			'X-AGSB-Nonce'     => $nonce,
			'X-AGSB-Body-SHA256' => $body_hash,
			'X-AGSB-Signature' => $this->sign( $method, $route, $timestamp, $nonce, $body_hash ),
			'X-AGSB-Origin'    => home_url(),
		);
	}

	public function verify_rest_request( WP_REST_Request $request ) {
		$secret = $this->config->get_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'ag_sync_bridge_missing_secret', __( 'Shared secret is not configured.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		$timestamp = $request->get_header( 'x-agsb-timestamp' );
		$signature = $request->get_header( 'x-agsb-signature' );
		$nonce     = sanitize_text_field( (string) $request->get_header( 'x-agsb-nonce' ) );
		$body_hash = strtolower( sanitize_text_field( (string) $request->get_header( 'x-agsb-body-sha256' ) ) );
		$route     = $request->get_route();
		$method    = $request->get_method();

		if ( ! $timestamp || ! $signature ) {
			return new WP_Error( 'ag_sync_bridge_missing_auth', __( 'Missing AG Sync Bridge authentication headers.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_Error( 'ag_sync_bridge_expired_request', __( 'Request timestamp is outside the allowed window.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		$requires_body_signature = $this->requires_body_signature( $route );
		if ( $requires_body_signature && ( '' === $body_hash || '' === $nonce ) ) {
			return new WP_Error( 'ag_sync_bridge_body_signature_required', __( 'This operation requires a nonce signature bound to the exact JSON body.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		if ( '' !== $body_hash ) {
			$actual_body_hash = hash( 'sha256', (string) $request->get_body() );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $body_hash ) || ! hash_equals( $body_hash, $actual_body_hash ) ) {
				return new WP_Error( 'ag_sync_bridge_body_hash_mismatch', __( 'Signed request body verification failed.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
			}
		}

		$signature_mode = '';
		$expected       = $nonce ? $this->sign( $method, $route, (int) $timestamp, $nonce, $body_hash ) : '';
		$legacy         = $this->sign( $method, $route, (int) $timestamp );

		if ( $expected && hash_equals( $expected, $signature ) ) {
			$signature_mode = 'nonce';
		} elseif ( ! $requires_body_signature && hash_equals( $legacy, $signature ) ) {
			$signature_mode = 'legacy';
			$nonce          = '';
		} else {
			return new WP_Error( 'ag_sync_bridge_bad_signature', __( 'Invalid AG Sync Bridge signature.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		$replay_key = 'ag_sync_bridge_sig_' . md5( $signature_mode . '|' . $signature . '|' . $route . '|' . $timestamp . '|' . $nonce );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'ag_sync_bridge_replay_blocked', __( 'Replay blocked for this request.', 'ag-sync-bridge' ), array( 'status' => 403 ) );
		}

		set_transient( $replay_key, 1, 10 * MINUTE_IN_SECONDS );
		$this->config->set_state_value(
			'last_authenticated_request',
			array(
				'at'     => gmdate( 'c' ),
				'route'  => $route,
				'method' => strtoupper( $method ),
				'origin' => (string) $request->get_header( 'x-agsb-origin' ),
				'mode'   => $signature_mode,
				'status' => 'ok',
			)
		);
		return true;
	}

	private function requires_body_signature( $route ) {
		return in_array(
			(string) $route,
			array(
				'/ag-sync-bridge/v1/backup/create',
				'/ag-sync-bridge/v1/snapshot/create',
				'/ag-sync-bridge/v1/snapshot/import',
				'/ag-sync-bridge/v1/snapshot/delete-exact',
				'/ag-sync-bridge/v1/v4mpg-table/plan',
				'/ag-sync-bridge/v1/v4mpg-table/backup',
				'/ag-sync-bridge/v1/v4mpg-table/backup-page',
				'/ag-sync-bridge/v1/v4mpg-table/backup-seal',
				'/ag-sync-bridge/v1/v4mpg-table/backup-abort',
				'/ag-sync-bridge/v1/v4mpg-table/deploy',
				'/ag-sync-bridge/v1/v4mpg-table/verify',
				'/ag-sync-bridge/v1/v4mpg-table/rollback',
				'/ag-sync-bridge/v1/v4mpg-table/status',
				'/ag-sync-bridge/v1/v4mpg-table/recover',
			),
			true
		);
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
}
