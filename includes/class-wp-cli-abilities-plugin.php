<?php
/**
 * Main plugin orchestrator.
 */
class WP_CLI_Abilities_Plugin {

	private WP_CLI_Detector $detector;
	private WP_CLI_Command_Parser $parser;
	private WP_CLI_Ability_Registrar $registrar;

	public function __construct() {
		$this->detector  = new WP_CLI_Detector();
		$this->parser    = new WP_CLI_Command_Parser();
		$this->registrar = new WP_CLI_Ability_Registrar( $this->detector, $this->parser );
	}

	/**
	 * Wires up all hooks.
	 */
	public function init(): void {
		// Register the WP-CLI ability category.
		add_action( 'wp_abilities_api_categories_init', array( $this->registrar, 'register_category' ) );

		// Register abilities from detected WP-CLI commands.
		add_action( 'wp_abilities_api_init', array( $this->registrar, 'register_abilities' ) );

		// Admin settings page.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}

		// Cache-clear hooks for plugin/theme/WP-CLI package changes.
		add_action( 'activated_plugin', array( $this, 'on_abilities_changed' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_abilities_changed' ) );
		add_action( 'switch_theme', array( $this, 'on_abilities_changed' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_abilities_changed' ) );

		// When running inside WP-CLI, also hook package changes.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'after_wp_cli_packages_updated', array( $this, 'on_abilities_changed' ) );
		}

		// REST endpoint for MCP server cache invalidation.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Called when the set of available abilities may have changed.
	 *
	 * Uses a shutdown hook to bump the version AFTER the current request
	 * finishes, avoiding the race where MCP sees the new version but
	 * abilities haven't re-registered yet.
	 */
	public function on_abilities_changed(): void {
		$this->detector->clear_cache();

		// Defer version bump to shutdown so the next request will have
		// both the new version AND the re-registered abilities.
		if ( ! has_action( 'shutdown', array( $this, 'bump_version' ) ) ) {
			add_action( 'shutdown', array( $this, 'bump_version' ) );
		}
	}

	/**
	 * Bumps the abilities version. Called at shutdown.
	 */
	public function bump_version(): void {
		update_option( 'wp_cli_abilities_version', wp_generate_uuid4() );
	}

	/**
	 * Clears the WP-CLI command discovery cache.
	 */
	public function clear_command_cache(): void {
		$this->detector->clear_cache();
	}

	/**
	 * Registers the lightweight REST route for cache invalidation.
	 */
	public function register_rest_routes(): void {
		register_rest_route( 'wp-cli-abilities/v1', '/version', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( array(
					'version' => get_option( 'wp_cli_abilities_version', '' ),
				) );
			},
			// Requires authentication — prevents leaking that plugin is active.
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Adds the settings page under the Settings menu.
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'WP-CLI Abilities', 'wp-cli-abilities' ),
			__( 'WP-CLI Abilities', 'wp-cli-abilities' ),
			'manage_options',
			'wp-cli-abilities',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers plugin settings.
	 */
	public function register_settings(): void {
		register_setting( 'wp_cli_abilities', 'wp_cli_abilities_allowed', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'wp_cli_abilities', 'wp_cli_abilities_blocked', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( 'wp_cli_abilities', 'wp_cli_abilities_max', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 200,
		) );

		register_setting( 'wp_cli_abilities', 'wp_cli_abilities_allow_destructive', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );

		register_setting( 'wp_cli_abilities', 'wp_cli_abilities_audit_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );

		add_settings_section(
			'wp_cli_abilities_main',
			__( 'Command Filtering', 'wp-cli-abilities' ),
			function () {
				echo '<p>' . esc_html__(
					'Control which WP-CLI commands are exposed as abilities. Leave allow-list empty to include all commands (except blocked ones).',
					'wp-cli-abilities'
				) . '</p>';
			},
			'wp-cli-abilities'
		);

		add_settings_field(
			'wp_cli_abilities_allowed',
			__( 'Allow-list (command prefixes)', 'wp-cli-abilities' ),
			function () {
				$value = get_option( 'wp_cli_abilities_allowed', '' );
				printf(
					'<input type="text" name="wp_cli_abilities_allowed" value="%s" class="regular-text" placeholder="plugin, post, user" />
					<p class="description">%s</p>',
					esc_attr( $value ),
					esc_html__( 'Comma-separated command prefixes. Only matching commands will be registered.', 'wp-cli-abilities' )
				);
			},
			'wp-cli-abilities',
			'wp_cli_abilities_main'
		);

		add_settings_field(
			'wp_cli_abilities_blocked',
			__( 'Block-list (command prefixes)', 'wp-cli-abilities' ),
			function () {
				$value = get_option( 'wp_cli_abilities_blocked', '' );
				printf(
					'<input type="text" name="wp_cli_abilities_blocked" value="%s" class="regular-text" placeholder="db, config" />
					<p class="description">%s</p>',
					esc_attr( $value ),
					esc_html__( 'Comma-separated command prefixes to exclude from registration.', 'wp-cli-abilities' )
				);
			},
			'wp-cli-abilities',
			'wp_cli_abilities_main'
		);

		add_settings_field(
			'wp_cli_abilities_max',
			__( 'Maximum abilities', 'wp-cli-abilities' ),
			function () {
				$value = get_option( 'wp_cli_abilities_max', 200 );
				printf(
					'<input type="number" name="wp_cli_abilities_max" value="%d" min="1" max="1000" class="small-text" />
					<p class="description">%s</p>',
					(int) $value,
					esc_html__( 'Maximum number of WP-CLI commands to register as abilities.', 'wp-cli-abilities' )
				);
			},
			'wp-cli-abilities',
			'wp_cli_abilities_main'
		);

		add_settings_section(
			'wp_cli_abilities_guardrails',
			__( 'Guardrails', 'wp-cli-abilities' ),
			function () {
				echo '<p>' . esc_html__(
					'Safety controls for ability execution. Dangerous commands (eval, db, config, shell) are always blocked.',
					'wp-cli-abilities'
				) . '</p>';
			},
			'wp-cli-abilities'
		);

		add_settings_field(
			'wp_cli_abilities_allow_destructive',
			__( 'Allow destructive commands', 'wp-cli-abilities' ),
			function () {
				$checked = get_option( 'wp_cli_abilities_allow_destructive', false );
				printf(
					'<label><input type="checkbox" name="wp_cli_abilities_allow_destructive" value="1" %s />
					%s</label>
					<p class="description">%s</p>',
					checked( $checked, true, false ),
					esc_html__( 'Enable delete, uninstall, flush, reset commands', 'wp-cli-abilities' ),
					esc_html__( 'When disabled, commands like plugin delete, user delete, cache flush are not registered as abilities.', 'wp-cli-abilities' )
				);
			},
			'wp-cli-abilities',
			'wp_cli_abilities_guardrails'
		);

		add_settings_field(
			'wp_cli_abilities_audit_enabled',
			__( 'Audit logging', 'wp-cli-abilities' ),
			function () {
				$checked = get_option( 'wp_cli_abilities_audit_enabled', true );
				printf(
					'<label><input type="checkbox" name="wp_cli_abilities_audit_enabled" value="1" %s />
					%s</label>
					<p class="description">%s</p>',
					checked( $checked, true, false ),
					esc_html__( 'Log all ability executions', 'wp-cli-abilities' ),
					esc_html__( 'Records who ran what, when, with what input. Last 500 entries kept. Sensitive values are redacted.', 'wp-cli-abilities' )
				);
			},
			'wp-cli-abilities',
			'wp_cli_abilities_guardrails'
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_available  = $this->detector->is_available();
		$wp_cli_path   = $this->detector->get_wp_cli_path();
		$commands      = $is_available ? $this->detector->discover_commands() : array();
		$command_count = count( $commands );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP-CLI Abilities', 'wp-cli-abilities' ); ?></h1>

			<div class="card" style="max-width: 800px;">
				<h2><?php esc_html_e( 'Status', 'wp-cli-abilities' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'WP-CLI detected', 'wp-cli-abilities' ); ?></th>
						<td>
							<?php if ( $is_available ) : ?>
								<span style="color: green;">&#10003;</span>
								<?php echo esc_html( $wp_cli_path ); ?>
							<?php else : ?>
								<span style="color: red;">&#10007;</span>
								<?php esc_html_e( 'WP-CLI not found', 'wp-cli-abilities' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Commands discovered', 'wp-cli-abilities' ); ?></th>
						<td><?php echo (int) $command_count; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Abilities API', 'wp-cli-abilities' ); ?></th>
						<td>
							<?php if ( function_exists( 'wp_register_ability' ) ) : ?>
								<span style="color: green;">&#10003;</span>
								<?php esc_html_e( 'Available', 'wp-cli-abilities' ); ?>
							<?php else : ?>
								<span style="color: red;">&#10007;</span>
								<?php esc_html_e( 'Requires WordPress 6.9+', 'wp-cli-abilities' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'wp_cli_abilities' );
				do_settings_sections( 'wp-cli-abilities' );
				submit_button();
				?>
			</form>

			<?php if ( $is_available && ! empty( $commands ) ) : ?>
			<div class="card" style="max-width: 800px;">
				<h2><?php esc_html_e( 'Discovered Commands', 'wp-cli-abilities' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Command', 'wp-cli-abilities' ); ?></th>
							<th><?php esc_html_e( 'Ability Name', 'wp-cli-abilities' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wp-cli-abilities' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $commands, 0, 50 ) as $cmd ) : ?>
						<tr>
							<td><code>wp <?php echo esc_html( $cmd['name'] ); ?></code></td>
							<td><code><?php echo esc_html( $this->parser->build_ability_name( $cmd['name'] ) ); ?></code></td>
							<td><?php echo esc_html( $cmd['description'] ); ?></td>
						</tr>
						<?php endforeach; ?>
						<?php if ( $command_count > 50 ) : ?>
						<tr>
							<td colspan="3"><em><?php printf( esc_html__( '... and %d more commands', 'wp-cli-abilities' ), $command_count - 50 ); ?></em></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="card" style="max-width: 800px;">
				<h2><?php esc_html_e( 'Cache', 'wp-cli-abilities' ); ?></h2>
				<p><?php esc_html_e( 'Command discovery results are cached for 1 hour. The cache also clears automatically when plugins are activated/deactivated or the theme is switched.', 'wp-cli-abilities' ); ?></p>
				<?php if ( isset( $_GET['cache_cleared'] ) ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Cache cleared.', 'wp-cli-abilities' ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_cli_abilities_clear_cache' ); ?>
					<input type="hidden" name="action" value="wp_cli_abilities_clear_cache" />
					<?php submit_button( __( 'Clear Cache & Re-discover', 'wp-cli-abilities' ), 'secondary' ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}

// Handle the cache-clear admin-post action.
add_action( 'admin_post_wp_cli_abilities_clear_cache', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized', 'wp-cli-abilities' ) );
	}
	check_admin_referer( 'wp_cli_abilities_clear_cache' );
	wp_cli_abilities()->clear_command_cache();
	wp_safe_redirect( add_query_arg( 'cache_cleared', '1', admin_url( 'options-general.php?page=wp-cli-abilities' ) ) );
	exit;
} );
