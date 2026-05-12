<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHub_Updater {
	const OWNER          = 'andrea52k';
	const REPOSITORY     = 'wordpress-plugin-ag-sync-bridge';
	const SLUG           = 'ag-sync-bridge';
	const ASSET_NAME     = 'ag-sync-bridge.zip';
	const CACHE_KEY      = 'ag_sync_bridge_github_release';
	const CACHE_TTL      = 21600;
	const ASSET_QUERY_ARG = 'ag-sync-bridge-private-asset';

	/**
	 * @var string
	 */
	private $plugin_basename;

	public function __construct() {
		$this->plugin_basename = plugin_basename( AG_SYNC_BRIDGE_PLUGIN_FILE );
	}

	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_private_asset' ), 10, 4 );
	}

	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( empty( $transient->checked ) || empty( $transient->checked[ $this->plugin_basename ] ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) || empty( $release ) ) {
			return $transient;
		}

		$version = $this->get_release_version( $release );
		$asset   = $this->find_release_asset( $release );

		if ( ! $version || ! $asset || ! version_compare( $version, AG_SYNC_BRIDGE_VERSION, '>' ) ) {
			$transient->no_update[ $this->plugin_basename ] = $this->build_update_object( $release, $version, $asset, false );
			return $transient;
		}

		$transient->response[ $this->plugin_basename ] = $this->build_update_object( $release, $version, $asset, true );
		return $transient;
	}

	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) || empty( $release ) ) {
			return $result;
		}

		$version = $this->get_release_version( $release );
		$asset   = $this->find_release_asset( $release );

		if ( ! $version || ! $asset ) {
			return $result;
		}

		$body = trim( (string) array_get( $release, 'body', '' ) );
		if ( '' === $body ) {
			$body = __( 'Release GitHub di AG Sync Bridge.', 'ag-sync-bridge' );
		}

		return (object) array(
			'name'          => 'AG Sync Bridge',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => 'Codex',
			'homepage'      => $this->get_repository_url(),
			'download_link' => $this->get_package_url( $asset ),
			'sections'      => array(
				'description' => __( 'Full snapshot bridge per sincronizzare un sito WordPress locale e un sito live.', 'ag-sync-bridge' ),
				'changelog'   => wp_kses_post( nl2br( esc_html( $body ) ) ),
			),
		);
	}

	public function download_private_asset( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );

		if ( false !== $reply || ! $this->is_private_asset_url( $package ) ) {
			return $reply;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'ag_sync_bridge_github_token_missing', __( 'AG Sync Bridge needs AG_SYNC_BRIDGE_GITHUB_TOKEN to download a private GitHub release asset.', 'ag-sync-bridge' ) );
		}

		$tmp_file = wp_tempnam( self::ASSET_NAME );
		if ( ! $tmp_file ) {
			return new WP_Error( 'ag_sync_bridge_github_temp_file', __( 'Unable to create a temporary file for the plugin update.', 'ag-sync-bridge' ) );
		}

		$response = wp_remote_get(
			remove_query_arg( self::ASSET_QUERY_ARG, $package ),
			array(
				'timeout'     => 300,
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $tmp_file,
				'headers'     => $this->build_headers( 'application/octet-stream' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || ! file_exists( $tmp_file ) || 0 === filesize( $tmp_file ) ) {
			@unlink( $tmp_file );
			return new WP_Error( 'ag_sync_bridge_github_download_failed', sprintf( 'GitHub asset download failed with status %d.', $code ) );
		}

		return $tmp_file;
	}

	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPOSITORY );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->build_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'ag_sync_bridge_github_release_failed', sprintf( 'GitHub release lookup failed with status %d.', $code ) );
		}

		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return new WP_Error( 'ag_sync_bridge_github_release_invalid', __( 'GitHub release response was invalid.', 'ag-sync-bridge' ) );
		}

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	private function get_release_version( array $release ) {
		$tag = trim( (string) array_get( $release, 'tag_name', '' ) );
		$tag = preg_replace( '/^[^0-9]*/', '', $tag );

		return $tag ? $tag : '';
	}

	private function find_release_asset( array $release ) {
		$assets = array_get( $release, 'assets', array() );
		if ( ! is_array( $assets ) ) {
			return array();
		}

		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && self::ASSET_NAME === array_get( $asset, 'name', '' ) ) {
				return $asset;
			}
		}

		return array();
	}

	private function build_update_object( array $release, $version, array $asset, $include_package ) {
		$object = (object) array(
			'id'          => $this->get_repository_url(),
			'slug'        => self::SLUG,
			'plugin'      => $this->plugin_basename,
			'new_version' => $version ? $version : AG_SYNC_BRIDGE_VERSION,
			'url'         => $this->get_repository_url(),
			'tested'      => '',
			'requires'    => '',
			'requires_php' => '',
		);

		if ( $include_package ) {
			$object->package = $this->get_package_url( $asset );
		}

		return $object;
	}

	private function get_package_url( array $asset ) {
		if ( '' === $this->get_token() && ! empty( $asset['browser_download_url'] ) ) {
			return (string) $asset['browser_download_url'];
		}

		$id = absint( array_get( $asset, 'id', 0 ) );
		return add_query_arg(
			self::ASSET_QUERY_ARG,
			'1',
			sprintf( 'https://api.github.com/repos/%s/%s/releases/assets/%d', self::OWNER, self::REPOSITORY, $id )
		);
	}

	private function is_private_asset_url( $url ) {
		return false !== strpos( (string) $url, self::ASSET_QUERY_ARG . '=1' );
	}

	private function get_repository_url() {
		return sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPOSITORY );
	}

	private function get_token() {
		$token = '';

		if ( defined( 'AG_SYNC_BRIDGE_GITHUB_TOKEN' ) ) {
			$token = (string) AG_SYNC_BRIDGE_GITHUB_TOKEN;
		}

		$token = (string) apply_filters( 'ag_sync_bridge_github_token', $token );
		return trim( $token );
	}

	private function build_headers( $accept = 'application/vnd.github+json' ) {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'AG-Sync-Bridge/' . AG_SYNC_BRIDGE_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = $this->get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}
}
