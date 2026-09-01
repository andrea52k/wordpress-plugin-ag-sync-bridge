<?php
namespace AGSyncBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page {
	const NOTICE_TRANSIENT_PREFIX = 'ag_sync_bridge_notice_';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var File_System_Service
	 */
	private $file_system;

	/**
	 * @var Sync_Service
	 */
	private $sync;

	public function __construct( Config $config, Logger $logger, File_System_Service $file_system, Sync_Service $sync ) {
		$this->config      = $config;
		$this->logger      = $logger;
		$this->file_system = $file_system;
		$this->sync        = $sync;
	}

	public function register_menu() {
		add_management_page(
			__( 'AG Sync Bridge', 'ag-sync-bridge' ),
			__( 'AG Sync Bridge', 'ag-sync-bridge' ),
			'manage_options',
			'ag-sync-bridge',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'ag_sync_bridge_settings_group',
			Config::OPTION_SETTINGS,
			array(
				'sanitize_callback' => array( $this->config, 'sanitize_settings' ),
			)
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_ag-sync-bridge' !== $hook ) {
			return;
		}

		$css_version = file_exists( AG_SYNC_BRIDGE_PLUGIN_DIR . 'assets/admin.css' ) ? (string) filemtime( AG_SYNC_BRIDGE_PLUGIN_DIR . 'assets/admin.css' ) : AG_SYNC_BRIDGE_VERSION;
		$js_version  = file_exists( AG_SYNC_BRIDGE_PLUGIN_DIR . 'assets/admin.js' ) ? (string) filemtime( AG_SYNC_BRIDGE_PLUGIN_DIR . 'assets/admin.js' ) : AG_SYNC_BRIDGE_VERSION;

		wp_enqueue_style(
			'ag-sync-bridge-admin',
			AG_SYNC_BRIDGE_PLUGIN_URL . 'assets/admin.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'ag-sync-bridge-admin',
			AG_SYNC_BRIDGE_PLUGIN_URL . 'assets/admin.js',
			array(),
			$js_version,
			true
		);

		wp_localize_script(
			'ag-sync-bridge-admin',
			'agSyncBridgeAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'statusNonce'   => wp_create_nonce( 'ag_sync_bridge_operation_status' ),
				'pushPhrase'    => 'INVIA LIVE',
				'restorePhrase' => 'RIPRISTINA',
				'labels'        => array(
					'working'          => __( 'Operazione in corso...', 'ag-sync-bridge' ),
					'completed'        => __( 'Operazione completata.', 'ag-sync-bridge' ),
					'failed'           => __( 'Operazione interrotta con errore.', 'ag-sync-bridge' ),
					'connectionError'  => __( 'Impossibile leggere lo stato dell operazione.', 'ag-sync-bridge' ),
					'invalidResponse'  => __( 'Risposta non valida ricevuta dal server.', 'ag-sync-bridge' ),
				),
			)
		);
	}

	public function handle_test_connection() {
		$this->authorize_action( 'ag_sync_bridge_test_connection' );

		$result = $this->sync->test_connection();
		$this->respond_after_action( $result, __( 'Connection test completed.', 'ag-sync-bridge' ) );
	}

	public function handle_create_snapshot() {
		$this->authorize_action( 'ag_sync_bridge_create_snapshot' );

		$result = $this->sync->create_snapshot(
			'manual-admin-snapshot',
			array(
				'trigger' => 'admin',
			)
		);

		$this->respond_after_action( $result, __( 'Snapshot created.', 'ag-sync-bridge' ) );
	}

	public function handle_pull() {
		$this->authorize_action( 'ag_sync_bridge_pull' );

		$result = $this->sync->pull_from_remote();
		$this->respond_after_action( $result, __( 'Local site updated from live.', 'ag-sync-bridge' ) );
	}

	public function handle_push() {
		$this->authorize_action( 'ag_sync_bridge_push' );

		$confirmation = isset( $_POST['push_confirmation'] ) ? trim( wp_unslash( $_POST['push_confirmation'] ) ) : '';
		if ( 'INVIA LIVE' !== $confirmation ) {
			$this->respond_error( __( 'Push aborted: confirmation phrase mismatch.', 'ag-sync-bridge' ) );
		}

		$partial_paths = isset( $_POST['partial_paths'] ) ? $this->parse_partial_paths( wp_unslash( $_POST['partial_paths'] ) ) : array();
		$result        = $this->sync->push_to_remote(
			array(
				'partial_paths' => $partial_paths,
			)
		);
		$this->respond_after_action( $result, __( 'Live site updated from local snapshot.', 'ag-sync-bridge' ) );
	}

	public function handle_restore_backup() {
		$this->authorize_action( 'ag_sync_bridge_restore_backup' );

		$confirmation = isset( $_POST['restore_confirmation'] ) ? trim( wp_unslash( $_POST['restore_confirmation'] ) ) : '';
		$backup       = isset( $_POST['backup_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_reference'] ) ) : '';
		$custom_path  = isset( $_POST['custom_backup_path'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_backup_path'] ) ) : '';

		if ( 'RIPRISTINA' !== $confirmation ) {
			$this->respond_error( __( 'Restore aborted: confirmation phrase mismatch.', 'ag-sync-bridge' ) );
		}

		$result = $this->sync->restore_local_backup( $backup, $custom_path );
		$this->respond_after_action( $result, __( 'Backup restored.', 'ag-sync-bridge' ) );
	}

	public function handle_operation_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to inspect the current operation.', 'ag-sync-bridge' ) ), 403 );
		}

		check_ajax_referer( 'ag_sync_bridge_operation_status', 'nonce' );
		$this->sync->refresh_remote_import_monitor();

		wp_send_json_success(
			array(
				'state' => $this->config->get_state(),
				'logs'  => $this->logger->get_recent_entries( 30 ),
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ag-sync-bridge' ) );
		}

		$settings        = $this->config->get_settings();
		$state           = $this->config->get_state();
		$latest_snapshot = $this->file_system->list_packages( 'snapshots', 1, true );
		$latest_snapshot = empty( $latest_snapshot ) ? array() : $latest_snapshot[0];
		$backups         = $this->file_system->list_restore_candidates( 25 );
		$logs            = $this->logger->get_recent_entries( 20 );
		$notice          = $this->consume_notice();
		$last_connection = array_get( $state, 'last_connection', array() );
		$remote_status   = array_get( $last_connection, 'remote', array() );
		$last_auth       = array_get( $state, 'last_authenticated_request', array() );
		$current_op      = array_get( $state, 'current_operation', array() );
		$next_schedule   = wp_next_scheduled( Scheduler::HOOK_WEEKLY_SNAPSHOT );
		$next_auto_pull  = wp_next_scheduled( Scheduler::HOOK_WEEKLY_PULL );
		$status_badges   = $this->get_status_badges( $settings, $last_connection, $remote_status, $last_auth, $next_schedule, $next_auto_pull );
		?>
		<div class="wrap ag-sync-bridge-admin">
			<h1>AG Sync Bridge</h1>
			<p><?php esc_html_e( 'Single plugin bridge for full snapshot sync between local and live WordPress sites.', 'ag-sync-bridge' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<div class="ag-sync-bridge-card ag-sync-bridge-operation-panel" id="ag-sync-bridge-operation-panel"<?php echo empty( $current_op ) ? ' hidden' : ''; ?>>
				<h2><?php esc_html_e( 'Operazione in corso', 'ag-sync-bridge' ); ?></h2>
				<div class="ag-sync-bridge-progress" aria-hidden="true">
					<div class="ag-sync-bridge-progress-bar" id="ag-sync-bridge-progress-bar" style="width: <?php echo esc_attr( absint( array_get( $current_op, 'progress', 0 ) ) ); ?>%;" aria-valuenow="<?php echo esc_attr( absint( array_get( $current_op, 'progress', 0 ) ) ); ?>"></div>
				</div>
				<p class="ag-sync-bridge-operation-status" id="ag-sync-bridge-operation-status"><?php echo esc_html( empty( $current_op ) ? __( 'In attesa di una nuova operazione.', 'ag-sync-bridge' ) : $this->format_current_operation( $current_op ) ); ?></p>
				<pre class="ag-sync-bridge-log ag-sync-bridge-log--live" id="ag-sync-bridge-live-log"><?php echo esc_html( implode( "\n", array_slice( $logs, 0, 10 ) ) ); ?></pre>
			</div>

			<div class="ag-sync-bridge-status-badges">
				<?php foreach ( $status_badges as $badge ) : ?>
					<div class="ag-sync-bridge-badge ag-sync-bridge-badge--<?php echo esc_attr( $badge['status'] ); ?>">
						<span class="ag-sync-bridge-badge-dot" aria-hidden="true"></span>
						<div>
							<strong><?php echo esc_html( $badge['label'] ); ?></strong>
							<div><?php echo esc_html( $badge['detail'] ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ag-sync-bridge-grid">
				<div class="ag-sync-bridge-card">
					<h2><?php esc_html_e( 'Configuration', 'ag-sync-bridge' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'ag_sync_bridge_settings_group' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="agsb-role"><?php esc_html_e( 'Site role', 'ag-sync-bridge' ); ?></label></th>
								<td>
									<select id="agsb-role" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[role]">
										<option value="local" <?php selected( 'local', $settings['role'] ); ?>>local</option>
										<option value="remote" <?php selected( 'remote', $settings['role'] ); ?>>remote</option>
									</select>
									<p class="description"><?php echo esc_html( sprintf( 'Resolved via %s.', $this->config->get_role_source() ) ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-remote-url"><?php esc_html_e( 'Remote site URL', 'ag-sync-bridge' ); ?></label></th>
								<td>
									<input id="agsb-remote-url" class="regular-text" type="url" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[remote_url]" value="<?php echo esc_attr( $settings['remote_url'] ); ?>" placeholder="https://example.com" />
									<p class="description"><?php esc_html_e( 'Sul ruolo local indica l URL del live da cui scaricare/importare.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-secret"><?php esc_html_e( 'Shared secret', 'ag-sync-bridge' ); ?></label></th>
								<td>
									<input id="agsb-secret" class="regular-text code" type="text" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[shared_secret]" value="<?php echo esc_attr( $settings['shared_secret'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Use the same secret on both local and live sites.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-storage-dir"><?php esc_html_e( 'Snapshot storage directory', 'ag-sync-bridge' ); ?></label></th>
								<td><input id="agsb-storage-dir" class="regular-text code" type="text" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[storage_dir]" value="<?php echo esc_attr( $settings['storage_dir'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto pull settimanale', 'ag-sync-bridge' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[auto_pull_enabled]" value="1" <?php checked( ! empty( $settings['auto_pull_enabled'] ) ); ?> /> <?php esc_html_e( 'Sul ruolo local scarica e importa automaticamente il live una volta a settimana.', 'ag-sync-bridge' ); ?></label>
									<p class="description"><?php esc_html_e( 'Se il PC o XAMPP erano spenti, WordPress recupera l evento al primo accesso utile successivo. Non può eseguire mentre il sito locale è offline.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Include .htaccess', 'ag-sync-bridge' ); ?></th>
								<td><label><input type="checkbox" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[include_htaccess]" value="1" <?php checked( ! empty( $settings['include_htaccess'] ) ); ?> /> <?php esc_html_e( 'Include and overwrite .htaccess when present in snapshots.', 'ag-sync-bridge' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Backup live prima dei push', 'ag-sync-bridge' ); ?></th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[remote_backups_enabled]" value="1" <?php checked( ! empty( $settings['remote_backups_enabled'] ) ); ?> /> <?php esc_html_e( 'Crea un backup sul live prima dei push.', 'ag-sync-bridge' ); ?></label>
									<p class="description"><?php esc_html_e( 'Disattivato di default: i backup ordinari restano sul locale per evitare di consumare quota hosting.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-retention"><?php esc_html_e( 'Retention', 'ag-sync-bridge' ); ?></label></th>
								<td><input id="agsb-retention" type="number" min="1" max="10" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[retention_count]" value="<?php echo esc_attr( $settings['retention_count'] ); ?>" /> <span class="description"><?php esc_html_e( 'Keep this many snapshots/backups.', 'ag-sync-bridge' ); ?></span></td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-timeout"><?php esc_html_e( 'Remote timeout (seconds)', 'ag-sync-bridge' ); ?></label></th>
								<td><input id="agsb-timeout" type="number" min="30" max="900" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[request_timeout]" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-excludes"><?php esc_html_e( 'Exclude patterns', 'ag-sync-bridge' ); ?></label></th>
								<td>
									<textarea id="agsb-excludes" class="large-text code" rows="8" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[exclude_patterns]"><?php echo esc_textarea( $settings['exclude_patterns'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'One glob pattern per line, relative to the WordPress root.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="agsb-external-backups"><?php esc_html_e( 'Cartelle backup esterne', 'ag-sync-bridge' ); ?></label></th>
								<td>
									<textarea id="agsb-external-backups" class="large-text code" rows="4" name="<?php echo esc_attr( Config::OPTION_SETTINGS ); ?>[external_backup_dirs]"><?php echo esc_textarea( array_get( $settings, 'external_backup_dirs', '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Una cartella per riga. Verranno elencati gli ZIP trovati e potrai anche indicare un percorso manuale nel restore.', 'ag-sync-bridge' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save settings', 'ag-sync-bridge' ) ); ?>
					</form>
				</div>

				<div class="ag-sync-bridge-card">
					<h2><?php esc_html_e( 'Status', 'ag-sync-bridge' ); ?></h2>
					<table class="widefat striped ag-sync-bridge-status-table">
						<tbody>
							<tr><th><?php esc_html_e( 'Resolved role', 'ag-sync-bridge' ); ?></th><td><code><?php echo esc_html( $settings['role'] ); ?></code></td></tr>
							<tr><th><?php esc_html_e( 'Plugin version', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( AG_SYNC_BRIDGE_VERSION ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Storage', 'ag-sync-bridge' ); ?></th><td><code><?php echo esc_html( $settings['storage_dir'] ); ?></code></td></tr>
							<tr><th><?php esc_html_e( 'Backup live pre-push', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( ! empty( $settings['remote_backups_enabled'] ) ? __( 'Attivi', 'ag-sync-bridge' ) : __( 'Disattivati', 'ag-sync-bridge' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Latest local snapshot', 'ag-sync-bridge' ); ?></th><td><?php echo wp_kses_post( $this->format_snapshot_meta( $latest_snapshot ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Latest remote status', 'ag-sync-bridge' ); ?></th><td><?php echo wp_kses_post( $this->format_remote_status( $last_connection, $remote_status ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Snapshot settimanale', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_schedule_status( $settings, $remote_status, $next_schedule ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Prossima esecuzione snapshot', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_next_schedule( $settings, $remote_status, $next_schedule ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Ultima richiesta autenticata ricevuta', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_last_authenticated_request( $last_auth ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Auto pull locale', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_auto_pull_status( $settings, $next_auto_pull ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Prossimo auto pull locale', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_auto_pull_next_schedule( $settings, $next_auto_pull ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Last pull', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_timestamp( array_get( array_get( $state, 'last_pull', array() ), 'completed_at', '' ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Last auto pull', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_timestamp( array_get( array_get( $state, 'last_auto_pull', array() ), 'completed_at', '' ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Last push', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_timestamp( array_get( array_get( $state, 'last_push', array() ), 'completed_at', '' ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Last restore', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_timestamp( array_get( array_get( $state, 'last_restore', array() ), 'completed_at', '' ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Current operation', 'ag-sync-bridge' ); ?></th><td><?php echo esc_html( $this->format_current_operation( array_get( $state, 'current_operation', array() ) ) ); ?></td></tr>
						</tbody>
					</table>

					<h3><?php esc_html_e( 'Recent log', 'ag-sync-bridge' ); ?></h3>
					<pre class="ag-sync-bridge-log"><?php echo esc_html( implode( "\n", $logs ) ); ?></pre>
				</div>
			</div>

			<div class="ag-sync-bridge-card">
				<h2><?php esc_html_e( 'Actions', 'ag-sync-bridge' ); ?></h2>
				<p class="ag-sync-bridge-note">
					<?php esc_html_e( 'Sul live lo snapshot settimanale viene creato e tenuto disponibile. Sul locale puoi lasciare il pull manuale oppure attivare l auto pull settimanale: se il PC/XAMPP erano spenti, l evento verrà recuperato al primo accesso utile.', 'ag-sync-bridge' ); ?>
				</p>
				<div class="ag-sync-bridge-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ag_sync_bridge_test_connection" />
						<?php wp_nonce_field( 'ag_sync_bridge_test_connection' ); ?>
						<?php submit_button( __( 'Test connection', 'ag-sync-bridge' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ag-sync-bridge-async-form" data-operation-label="<?php esc_attr_e( 'Creazione snapshot manuale', 'ag-sync-bridge' ); ?>">
						<input type="hidden" name="action" value="ag_sync_bridge_create_snapshot" />
						<?php wp_nonce_field( 'ag_sync_bridge_create_snapshot' ); ?>
						<?php submit_button( __( 'Create snapshot now', 'ag-sync-bridge' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( 'local' === $settings['role'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ag-sync-bridge-async-form" data-operation-label="<?php esc_attr_e( 'Pull live verso locale', 'ag-sync-bridge' ); ?>">
							<input type="hidden" name="action" value="ag_sync_bridge_pull" />
							<?php wp_nonce_field( 'ag_sync_bridge_pull' ); ?>
							<?php submit_button( __( 'Aggiorna il locale dal live', 'ag-sync-bridge' ), 'primary', 'submit', false ); ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ag-sync-bridge-confirm-form ag-sync-bridge-async-form" data-confirm="INVIA LIVE" data-confirm-field="push_confirmation" data-operation-label="<?php esc_attr_e( 'Push locale verso live', 'ag-sync-bridge' ); ?>">
							<input type="hidden" name="action" value="ag_sync_bridge_push" />
							<?php wp_nonce_field( 'ag_sync_bridge_push' ); ?>
							<label for="agsb-push-confirm"><?php esc_html_e( 'Digita INVIA LIVE per confermare il push distruttivo verso il live.', 'ag-sync-bridge' ); ?></label>
							<input id="agsb-push-confirm" type="text" name="push_confirmation" class="regular-text code" />
							<label for="agsb-partial-paths"><?php esc_html_e( 'Percorsi file/cartelle da spingere (opzionale)', 'ag-sync-bridge' ); ?></label>
							<textarea id="agsb-partial-paths" name="partial_paths" class="large-text code" rows="4" placeholder="robots.txt&#10;wp-content/mu-plugins/mio-file.php"></textarea>
							<p class="description"><?php esc_html_e( 'Lascia vuoto per push completo. Usa percorsi relativi alla root WordPress, uno per riga. Il push selettivo non include database e non puo aggiornare AG Sync Bridge stesso.', 'ag-sync-bridge' ); ?></p>
							<?php submit_button( __( 'Invia il locale al live', 'ag-sync-bridge' ), 'delete', 'submit', false ); ?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ag-sync-bridge-confirm-form ag-sync-bridge-async-form" data-confirm="RIPRISTINA" data-confirm-field="restore_confirmation" data-operation-label="<?php esc_attr_e( 'Ripristino backup o snapshot locale', 'ag-sync-bridge' ); ?>">
							<input type="hidden" name="action" value="ag_sync_bridge_restore_backup" />
							<?php wp_nonce_field( 'ag_sync_bridge_restore_backup' ); ?>
							<label for="agsb-backup-file"><?php esc_html_e( 'Ripristina da backup o snapshot locale / esterno', 'ag-sync-bridge' ); ?></label>
							<select id="agsb-backup-file" name="backup_reference">
								<option value=""><?php esc_html_e( 'Seleziona un backup già rilevato', 'ag-sync-bridge' ); ?></option>
								<?php foreach ( $backups as $backup ) : ?>
									<option value="<?php echo esc_attr( array_get( $backup, 'reference', '' ) ); ?>">
										<?php echo esc_html( array_get( $backup, 'basename', '' ) . ' | ' . array_get( $backup, 'source_label', '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<label for="agsb-custom-backup-path"><?php esc_html_e( 'Oppure indica un percorso ZIP/cartella sul server', 'ag-sync-bridge' ); ?></label>
							<input id="agsb-custom-backup-path" type="text" name="custom_backup_path" class="regular-text code" placeholder="C:/backups/mio-backup.zip oppure C:/backups/" />
							<input type="text" name="restore_confirmation" class="regular-text code" placeholder="RIPRISTINA" />
							<?php submit_button( __( 'Ripristina da backup/snapshot locale', 'ag-sync-bridge' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function authorize_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'ag-sync-bridge' ) );
		}

		check_admin_referer( $action );
	}

	private function redirect_after_action( $result, $success_message ) {
		$this->respond_after_action( $result, $success_message );
	}

	private function respond_after_action( $result, $success_message ) {
		if ( is_wp_error( $result ) ) {
			$this->respond_error( $result->get_error_message(), 400, $result );
		}

		$this->respond_success( $success_message, $result );
	}

	private function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function consume_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		return is_array( $notice ) ? $notice : array();
	}

	private function redirect_to_page() {
		wp_safe_redirect( admin_url( 'tools.php?page=ag-sync-bridge' ) );
		exit;
	}

	private function respond_success( $message, $result = array() ) {
		if ( $this->is_async_request() ) {
			wp_send_json_success( $this->build_async_payload( $message, $result ) );
		}

		$this->set_notice( 'success', $message );
		$this->redirect_to_page();
	}

	private function respond_error( $message, $status_code = 400, $result = null ) {
		if ( $this->is_async_request() ) {
			wp_send_json_error( $this->build_async_payload( $message, $result ), $status_code );
		}

		$this->set_notice( 'error', $message );
		$this->redirect_to_page();
	}

	private function is_async_request() {
		$header = isset( $_SERVER['HTTP_X_AGSB_ASYNC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AGSB_ASYNC'] ) ) : '';
		$posted = isset( $_POST['agsb_async'] ) ? sanitize_text_field( wp_unslash( $_POST['agsb_async'] ) ) : '';

		return '1' === $header || '1' === $posted;
	}

	private function parse_partial_paths( $value ) {
		$items = preg_split( '/[\r\n,]+/', (string) $value );
		$items = is_array( $items ) ? $items : array();

		return array_values(
			array_filter(
				array_map(
					static function ( $path ) {
						return trim( (string) $path );
					},
					$items
				)
			)
		);
	}

	private function build_async_payload( $message, $result = null ) {
		return array(
			'message' => (string) $message,
			'state'   => $this->config->get_state(),
			'logs'    => $this->logger->get_recent_entries( 30 ),
			'result'  => is_wp_error( $result ) ? $result->get_error_data() : $result,
		);
	}

	private function format_snapshot_meta( array $snapshot ) {
		if ( empty( $snapshot ) ) {
			return esc_html__( 'No snapshot available yet.', 'ag-sync-bridge' );
		}

		$parts = array(
			'File: ' . array_get( $snapshot, 'basename', '' ),
			'Scope: ' . array_get( $snapshot, 'snapshot_scope', 'unknown' ),
			'Created: ' . $this->format_timestamp( array_get( $snapshot, 'created_at', '' ) ),
			'Size: ' . format_bytes( (int) array_get( $snapshot, 'size_bytes', 0 ) ),
			'SHA256: ' . array_get( $snapshot, 'sha256', '' ),
		);

		return '<code>' . esc_html( implode( ' | ', $parts ) ) . '</code>';
	}

	private function format_remote_status( array $last_connection, array $remote_status ) {
		if ( 'error' === array_get( $last_connection, 'status', '' ) ) {
			return esc_html__( 'Errore: ', 'ag-sync-bridge' ) . esc_html( array_get( $last_connection, 'message', '' ) );
		}

		if ( empty( $remote_status ) ) {
			return esc_html__( 'No remote check performed yet.', 'ag-sync-bridge' );
		}

		$latest = array_get( $remote_status, 'latest_snapshot', array() );
		$parts  = array(
			'Remote role: ' . array_get( $remote_status, 'role', 'n/a' ),
			'Remote WP: ' . array_get( $remote_status, 'wordpress', 'n/a' ),
			'Remote snapshot: ' . ( empty( $latest ) ? 'none' : array_get( $latest, 'basename', '' ) ),
			'Remote next weekly snapshot: ' . $this->format_timestamp( array_get( array_get( $remote_status, 'schedule', array() ), 'next_run', '' ) ),
		);

		return '<code>' . esc_html( implode( ' | ', $parts ) ) . '</code>';
	}

	private function format_schedule_status( array $settings, array $remote_status, $next_schedule ) {
		if ( 'remote' === $settings['role'] ) {
			return $next_schedule ? __( 'Attivo su questo sito', 'ag-sync-bridge' ) : __( 'Non pianificato su questo sito', 'ag-sync-bridge' );
		}

		$remote_active = array_get( array_get( $remote_status, 'schedule', array() ), 'active', false );

		if ( $remote_active ) {
			return __( 'Attivo sul sito live', 'ag-sync-bridge' );
		}

		if ( ! empty( $remote_status ) ) {
			return __( 'Il live risponde ma non risulta schedulato come remote', 'ag-sync-bridge' );
		}

		return __( 'Non ancora verificato sul live', 'ag-sync-bridge' );
	}

	private function format_next_schedule( array $settings, array $remote_status, $next_schedule ) {
		if ( 'remote' === $settings['role'] ) {
			return $next_schedule ? $this->format_timestamp( gmdate( 'c', (int) $next_schedule ) ) : __( 'Non pianificato', 'ag-sync-bridge' );
		}

		$remote_next = array_get( array_get( $remote_status, 'schedule', array() ), 'next_run', '' );
		return $remote_next ? $this->format_timestamp( $remote_next ) : __( 'Non disponibile', 'ag-sync-bridge' );
	}

	private function format_auto_pull_status( array $settings, $next_auto_pull ) {
		if ( 'local' !== $settings['role'] ) {
			return __( 'Non applicabile sul ruolo remote', 'ag-sync-bridge' );
		}

		if ( ! empty( $settings['auto_pull_enabled'] ) ) {
			return $next_auto_pull ? __( 'Attivo sul locale', 'ag-sync-bridge' ) : __( 'Abilitato ma non ancora pianificato', 'ag-sync-bridge' );
		}

		return __( 'Disattivato: il pull resta manuale', 'ag-sync-bridge' );
	}

	private function format_auto_pull_next_schedule( array $settings, $next_auto_pull ) {
		if ( 'local' !== $settings['role'] ) {
			return __( 'Non applicabile', 'ag-sync-bridge' );
		}

		if ( empty( $settings['auto_pull_enabled'] ) ) {
			return __( 'Disattivato', 'ag-sync-bridge' );
		}

		return $next_auto_pull ? $this->format_timestamp( gmdate( 'c', (int) $next_auto_pull ) ) : __( 'In attesa di pianificazione/riavvio cron', 'ag-sync-bridge' );
	}

	private function format_last_authenticated_request( array $last_auth ) {
		if ( empty( $last_auth ) ) {
			return __( 'Nessuna richiesta autenticata registrata finora.', 'ag-sync-bridge' );
		}

		$parts = array(
			$this->format_timestamp( array_get( $last_auth, 'at', '' ) ),
			array_get( $last_auth, 'method', 'GET' ) . ' ' . array_get( $last_auth, 'route', '' ),
		);

		if ( array_get( $last_auth, 'origin', '' ) ) {
			$parts[] = array_get( $last_auth, 'origin', '' );
		}

		return implode( ' | ', $parts );
	}

	private function format_current_operation( array $operation ) {
		if ( empty( $operation ) ) {
			return __( 'Idle', 'ag-sync-bridge' );
		}

		$parts = array(
			array_get( $operation, 'operation', 'unknown' ),
			array_get( $operation, 'message', '' ),
		);

		if ( isset( $operation['progress'] ) ) {
			$parts[] = absint( $operation['progress'] ) . '%';
		}

		return implode( ' | ', array_filter( $parts ) );
	}

	private function get_status_badges( array $settings, array $last_connection, array $remote_status, array $last_auth, $next_schedule, $next_auto_pull ) {
		$badges = array();

		$badges[] = array(
			'label'  => __( 'Configurazione bridge', 'ag-sync-bridge' ),
			'status' => ( $settings['shared_secret'] && ( 'remote' === $settings['role'] || $settings['remote_url'] ) ) ? 'ok' : 'error',
			'detail' => ( $settings['shared_secret'] && ( 'remote' === $settings['role'] || $settings['remote_url'] ) )
				? __( 'Secret e parametri minimi presenti', 'ag-sync-bridge' )
				: __( 'Manca secret o URL live', 'ag-sync-bridge' ),
		);

		if ( 'local' === $settings['role'] ) {
			$connection_status = array_get( $last_connection, 'status', '' );
			$badges[]          = array(
				'label'  => __( 'Collegamento al live', 'ag-sync-bridge' ),
				'status' => 'ok' === $connection_status ? 'ok' : ( 'error' === $connection_status ? 'error' : 'warn' ),
				'detail' => 'ok' === $connection_status
					? __( 'Ultimo test connessione riuscito', 'ag-sync-bridge' )
					: ( 'error' === $connection_status ? array_get( $last_connection, 'message', __( 'Errore di connessione', 'ag-sync-bridge' ) ) : __( 'Nessun test connessione eseguito', 'ag-sync-bridge' ) ),
			);

			$remote_role = array_get( $remote_status, 'role', '' );
			$badges[]    = array(
				'label'  => __( 'Snapshot settimanale live', 'ag-sync-bridge' ),
				'status' => array_get( array_get( $remote_status, 'schedule', array() ), 'active', false ) && 'remote' === $remote_role ? 'ok' : ( empty( $remote_status ) ? 'warn' : 'error' ),
				'detail' => array_get( array_get( $remote_status, 'schedule', array() ), 'active', false ) && 'remote' === $remote_role
					? __( 'Schedulazione weekly attiva sul live', 'ag-sync-bridge' )
					: ( empty( $remote_status ) ? __( 'Verifica il live con "Test connection"', 'ag-sync-bridge' ) : __( 'Il live non risulta configurato come remote/schedulato', 'ag-sync-bridge' ) ),
			);

			$badges[] = array(
				'label'  => __( 'Auto pull locale', 'ag-sync-bridge' ),
				'status' => ! empty( $settings['auto_pull_enabled'] ) ? ( $next_auto_pull ? 'ok' : 'warn' ) : 'warn',
				'detail' => ! empty( $settings['auto_pull_enabled'] )
					? ( $next_auto_pull ? __( 'Il locale importera automaticamente il live quando WP-Cron gira', 'ag-sync-bridge' ) : __( 'Abilitato ma in attesa di pianificazione', 'ag-sync-bridge' ) )
					: __( 'Disattivato: il locale si aggiorna solo col pulsante manuale', 'ag-sync-bridge' ),
			);
		} else {
			$badges[] = array(
				'label'  => __( 'Snapshot settimanale locale/live', 'ag-sync-bridge' ),
				'status' => $next_schedule ? 'ok' : 'error',
				'detail' => $next_schedule ? __( 'Cron weekly attivo su questo sito remote', 'ag-sync-bridge' ) : __( 'Cron weekly non pianificato', 'ag-sync-bridge' ),
			);

			$badges[] = array(
				'label'  => __( 'Ultimo contatto dal locale', 'ag-sync-bridge' ),
				'status' => empty( $last_auth ) ? 'warn' : 'ok',
				'detail' => empty( $last_auth )
					? __( 'Nessuna richiesta autenticata ancora ricevuta', 'ag-sync-bridge' )
					: sprintf(
						/* translators: %s: timestamp */
						__( 'Ultima richiesta: %s', 'ag-sync-bridge' ),
						$this->format_timestamp( array_get( $last_auth, 'at', '' ) )
					),
			);
		}

		return $badges;
	}

	private function format_timestamp( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'Never', 'ag-sync-bridge' );
		}

		return wp_date( 'Y-m-d H:i:s', strtotime( $timestamp ) );
	}
}
