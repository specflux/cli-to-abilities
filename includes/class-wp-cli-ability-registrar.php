<?php
/**
 * Registers WP-CLI commands as WordPress Abilities.
 */
class WP_CLI_Ability_Registrar {

	private WP_CLI_Detector $detector;
	private WP_CLI_Command_Parser $parser;

	/**
	 * Commands to exclude from ability registration.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_COMMANDS = array(
		'cli',
		'help',
		'shell',
		'cli info',
		'cli version',
		'cli update',
		'cli cmd-dump',
		'cli completions',
		'cli alias',
		'cli has-command',
		'package',
		'package install',
		'package uninstall',
		'package list',
		'package path',
		'package browse',
		'package update',
	);

	public function __construct( WP_CLI_Detector $detector, WP_CLI_Command_Parser $parser ) {
		$this->detector = $detector;
		$this->parser   = $parser;
	}

	/**
	 * Registers the wp-cli ability category.
	 *
	 * Hooked to `wp_abilities_api_categories_init`.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category( 'wp-cli', array(
			'label'       => __( 'WP-CLI Commands', 'wp-cli-abilities' ),
			'description' => __( 'WordPress CLI commands exposed as abilities for automation and AI agents.', 'wp-cli-abilities' ),
		) );
	}

	/**
	 * Discovers WP-CLI commands and registers each as an ability.
	 *
	 * Hooked to `wp_abilities_api_init`.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( ! $this->detector->is_available() ) {
			return;
		}

		$commands      = $this->detector->discover_commands();
		$allowed       = $this->get_allowed_commands();
		$blocked       = $this->get_blocked_commands();
		$registered    = 0;
		$max_abilities = (int) get_option( 'wp_cli_abilities_max', 200 );

		foreach ( $commands as $command ) {
			if ( $registered >= $max_abilities ) {
				break;
			}

			$name = $command['name'] ?? '';

			if ( $this->is_excluded( $name ) ) {
				continue;
			}

			if ( ! empty( $allowed ) && ! $this->matches_filter( $name, $allowed ) ) {
				continue;
			}

			if ( ! empty( $blocked ) && $this->matches_filter( $name, $blocked ) ) {
				continue;
			}

			$ability_data = $this->parser->to_ability_args( $command );

			$result = wp_register_ability(
				$ability_data['ability_name'],
				$ability_data['args']
			);

			if ( $result ) {
				++$registered;
			}
		}

		/**
		 * Fires after all WP-CLI abilities have been registered.
		 *
		 * @param int $registered Number of abilities registered.
		 */
		do_action( 'wp_cli_abilities_registered', $registered );
	}

	/**
	 * Checks whether a command is in the exclusion list.
	 */
	private function is_excluded( string $name ): bool {
		return in_array( $name, self::EXCLUDED_COMMANDS, true );
	}

	/**
	 * Returns the user-configured allow-list of command prefixes.
	 *
	 * @return string[]
	 */
	private function get_allowed_commands(): array {
		$option = get_option( 'wp_cli_abilities_allowed', '' );
		return array_filter( array_map( 'trim', explode( ',', $option ) ) );
	}

	/**
	 * Returns the user-configured block-list of command prefixes.
	 *
	 * @return string[]
	 */
	private function get_blocked_commands(): array {
		$option = get_option( 'wp_cli_abilities_blocked', '' );
		return array_filter( array_map( 'trim', explode( ',', $option ) ) );
	}

	/**
	 * Checks whether a command name matches any of the given prefixes.
	 */
	private function matches_filter( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
