<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Internal restores update plugin options as part of import finalization.
	 * Skip forced schedule resets for those writes.
	 *
	 * @var int
	 */
	private static $suspend_settings_update_handlers = 0;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var Lock_Manager
	 */
	private $lock_manager;

	/**
	 * @var File_System_Service
	 */
	private $file_system;

	/**
	 * @var Database_Service
	 */
	private $database;

	/**
	 * @var Archive_Service
	 */
	private $archive;

	/**
	 * @var Export_Service
	 */
	private $exporter;

	/**
	 * @var Import_Service
	 */
	private $importer;

	/**
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * @var Local_Maintenance_Service
	 */
	private $maintenance;

	/**
	 * @var Sync_Service
	 */
	private $sync;

	/**
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * @var Auth
	 */
	private $auth;

	/**
	 * @var Rest_Controller
	 */
	private $rest_controller;

	/** @var Remote_Operation_Runtime */
	private $remote_operation_runtime;

	/** @var Remote_Update_Service */
	private $remote_update_service;

	/** @var V4MPG_Table_REST_Controller */
	private $v4mpg_table_rest;

	/**
	 * @var Admin_Page
	 */
	private $admin_page;

	/**
	 * @var GitHub_Updater
	 */
	private $github_updater;

	/**
	 * @var bool
	 */
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->bootstrap();
	}

	private function bootstrap() {
		$this->config          = new Config();
		$this->logger          = new Logger( $this->config );
		$this->lock_manager    = new Lock_Manager( $this->config, $this->logger );
		$this->file_system     = new File_System_Service( $this->config, $this->logger );
		$this->database        = new Database_Service( $this->config, $this->logger );
		$this->archive         = new Archive_Service( $this->config, $this->logger );
		$this->exporter        = new Export_Service( $this->config, $this->logger, $this->file_system, $this->database, $this->archive );
		$this->importer        = new Import_Service( $this->config, $this->logger, $this->file_system, $this->database, $this->archive );
		$this->http_client     = new Http_Client( $this->config, $this->logger );
		$this->maintenance     = new Local_Maintenance_Service( $this->logger );
		$this->sync            = new Sync_Service( $this->config, $this->logger, $this->lock_manager, $this->file_system, $this->exporter, $this->importer, $this->http_client, $this->maintenance );
		$this->scheduler       = new Scheduler( $this->config, $this->logger, $this->sync );
		$this->auth            = new Auth( $this->config, $this->logger );
		$this->remote_operation_runtime = new Remote_Operation_Runtime( $this->config, $this->logger );
		$this->remote_update_service = new Remote_Update_Service( $this->config, $this->logger, $this->remote_operation_runtime );
		$this->rest_controller = new Rest_Controller( $this->config, $this->logger, $this->file_system, $this->exporter, $this->importer, $this->sync, $this->auth, $this->remote_operation_runtime, $this->remote_update_service );
		$v4mpg_table_service = new V4MPG_Table_Deploy_Service( $this->config, $this->logger, null, $this->remote_operation_runtime );
		$this->v4mpg_table_rest = new V4MPG_Table_REST_Controller( $this->auth, $v4mpg_table_service );
		$this->admin_page      = new Admin_Page( $this->config, $this->logger, $this->file_system, $this->sync );
		$this->github_updater  = new GitHub_Updater();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->config->ensure_defaults();
		$this->file_system->prepare_runtime_dirs();
		$this->github_updater->register();

		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->v4mpg_table_rest, 'register_routes' ) );
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_init', array( $this->admin_page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );

		add_action( 'admin_post_ag_sync_bridge_test_connection', array( $this->admin_page, 'handle_test_connection' ) );
		add_action( 'admin_post_ag_sync_bridge_create_snapshot', array( $this->admin_page, 'handle_create_snapshot' ) );
		add_action( 'admin_post_ag_sync_bridge_pull', array( $this->admin_page, 'handle_pull' ) );
		add_action( 'admin_post_ag_sync_bridge_push', array( $this->admin_page, 'handle_push' ) );
		add_action( 'admin_post_ag_sync_bridge_restore_backup', array( $this->admin_page, 'handle_restore_backup' ) );
		add_action( 'wp_ajax_ag_sync_bridge_test_connection', array( $this->admin_page, 'handle_test_connection' ) );
		add_action( 'wp_ajax_ag_sync_bridge_create_snapshot', array( $this->admin_page, 'handle_create_snapshot' ) );
		add_action( 'wp_ajax_ag_sync_bridge_pull', array( $this->admin_page, 'handle_pull' ) );
		add_action( 'wp_ajax_ag_sync_bridge_push', array( $this->admin_page, 'handle_push' ) );
		add_action( 'wp_ajax_ag_sync_bridge_restore_backup', array( $this->admin_page, 'handle_restore_backup' ) );
		add_action( 'wp_ajax_ag_sync_bridge_operation_status', array( $this->admin_page, 'handle_operation_status' ) );
		add_action( 'update_option_' . Config::OPTION_SETTINGS, array( $this, 'handle_settings_updated' ), 10, 3 );
		add_action( Scheduler::HOOK_ASYNC_IMPORT, array( $this->rest_controller, 'run_async_import_snapshot' ), 10, 4 );
		add_action( Scheduler::HOOK_ASYNC_SNAPSHOT, array( $this->rest_controller, 'run_async_create_snapshot' ), 10, 4 );

		$this->scheduler->register();
		$this->maybe_register_cli();
	}

	public function handle_settings_updated( $old_value, $value, $option ) {
		unset( $old_value, $value, $option );

		if ( self::are_settings_update_handlers_suspended() ) {
			return;
		}

		$this->file_system->prepare_runtime_dirs();
		$this->scheduler->schedule_if_needed( true );
	}

	public static function suspend_settings_update_handlers() {
		self::$suspend_settings_update_handlers++;
	}

	public static function resume_settings_update_handlers() {
		if ( self::$suspend_settings_update_handlers > 0 ) {
			self::$suspend_settings_update_handlers--;
		}
	}

	public static function are_settings_update_handlers_suspended() {
		return self::$suspend_settings_update_handlers > 0;
	}

	private function maybe_register_cli() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register( $this->config, $this->logger, $this->lock_manager, $this->file_system, $this->sync );
			V4MPG_Table_CLI::register( $this->config, $this->logger, $this->auth, $this->http_client, $this->importer, $this->file_system );
		}
	}

	public static function activate() {
		$plugin = self::instance();
		$plugin->config->ensure_defaults();
		$plugin->file_system->prepare_runtime_dirs();
		$plugin->scheduler->schedule_if_needed( true );
		$plugin->logger->info(
			'Plugin activated.',
			array(
				'role' => $plugin->config->get_role(),
			)
		);
	}

	public static function deactivate() {
		$plugin = self::instance();
		$plugin->scheduler->clear();
		$plugin->lock_manager->release();
		$plugin->logger->info( 'Plugin deactivated.' );
	}
}
