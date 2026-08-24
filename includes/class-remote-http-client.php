<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fail-closed outbound transport used by live/remote peers.
 *
 * Remote peers create backups and accept authenticated imports through their
 * REST controller. They must not initiate pull/push/download operations, so
 * loading the local downloader implementation on every public request is both
 * unnecessary and an avoidable availability dependency.
 */
class Remote_Http_Client {
	/** @var Logger */
	private $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function __call( $name, $arguments ) {
		unset( $arguments );

		$this->logger->warning(
			'Outbound AG Sync call blocked on a remote peer.',
			array( 'method' => sanitize_key( (string) $name ) )
		);

		return new WP_Error(
			'ag_sync_bridge_remote_outbound_forbidden',
			__( 'Outbound AG Sync operations are disabled on a live peer.', 'ag-sync-bridge' )
		);
	}
}
