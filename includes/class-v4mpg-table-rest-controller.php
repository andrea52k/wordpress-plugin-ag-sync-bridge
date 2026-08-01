<?php
namespace AGSyncBridge;

use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class V4MPG_Table_REST_Controller {
	/** @var Auth */ private $auth;
	/** @var V4MPG_Table_Deploy_Service */ private $service;

	public function __construct( Auth $auth, V4MPG_Table_Deploy_Service $service ) {
		$this->auth = $auth;
		$this->service = $service;
	}

	public function register_routes() {
		$routes = array( 'plan' => 'plan', 'backup' => 'backup', 'backup-page' => 'backup_page', 'backup-seal' => 'backup_seal', 'backup-abort' => 'backup_abort', 'deploy' => 'deploy', 'verify' => 'verify', 'rollback' => 'rollback', 'status' => 'status', 'recover' => 'recover' );
		foreach ( $routes as $route => $action ) {
			register_rest_route(
				'ag-sync-bridge/v1',
				'/v4mpg-table/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		}
	}

	public function check_permission( WP_REST_Request $request ) {
		return $this->auth->verify_rest_request( $request );
	}

	public function plan( WP_REST_Request $request ) { return $this->dispatch( 'plan', $request ); }
	public function backup( WP_REST_Request $request ) { return $this->dispatch( 'backup', $request ); }
	public function backup_page( WP_REST_Request $request ) { return $this->dispatch( 'backup_page', $request ); }
	public function backup_seal( WP_REST_Request $request ) { return $this->dispatch( 'backup_seal', $request ); }
	public function backup_abort( WP_REST_Request $request ) { return $this->dispatch( 'backup_abort', $request ); }
	public function deploy( WP_REST_Request $request ) { return $this->dispatch( 'deploy', $request ); }
	public function verify( WP_REST_Request $request ) { return $this->dispatch( 'verify', $request ); }
	public function rollback( WP_REST_Request $request ) { return $this->dispatch( 'rollback', $request ); }
	public function status( WP_REST_Request $request ) { return $this->dispatch( 'status', $request ); }
	public function recover( WP_REST_Request $request ) { return $this->dispatch( 'recover', $request ); }

	private function dispatch( $action, WP_REST_Request $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'ag_sync_v4mpg_json_invalid', 'A JSON object body is required.', array( 'status' => 400 ) );
		}
		try {
			return new WP_REST_Response( $this->service->{$action}( $body ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'ag_sync_v4mpg_' . $action . '_blocked', $error->getMessage(), array( 'status' => 409 ) );
		}
	}
}
