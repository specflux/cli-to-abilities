<?php
/**
 * Registers WP-CLI commands as WordPress Abilities.
 *
 * @package WP_CLI_Abilities
 */

/**
 * WP-CLI ability registrar class.
 */
class WP_CLI_Ability_Registrar {

	/**
	 * WP-CLI detector instance.
	 *
	 * @var WP_CLI_Detector
	 */
	private WP_CLI_Detector $detector;

	/**
	 * Command parser instance.
	 *
	 * @var WP_CLI_Command_Parser
	 */
	private WP_CLI_Command_Parser $parser;

	/**
	 * Commands that are NEVER exposed — too dangerous for remote execution.
	 *
	 * @var string[]
	 */
	private const DENIED_COMMANDS = array(
		// Meta / internal.
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
		// Package management.
		'package',
		'package install',
		'package uninstall',
		'package list',
		'package path',
		'package browse',
		'package update',
		// Database — data exfil / destruction risk.
		'db export',
		'db import',
		'db drop',
		'db create',
		'db reset',
		'db query',
		'db cli',
		// Config — credential exposure.
		'config get',
		'config set',
		'config delete',
		'config edit',
		'config create',
		'config list',
		'config path',
		'config has',
		// Server / filesystem.
		'server',
		'scaffold',
	);

	/**
	 * Commands that require explicit opt-in to be registered.
	 *
	 * @var string[]
	 */
	private const EVAL_COMMANDS = array(
		'eval',
		'eval-file',
	);

	/**
	 * Subcommands that are considered destructive and require opt-in.
	 *
	 * @var string[]
	 */
	private const DESTRUCTIVE_SUBCOMMANDS = array(
		'delete',
		'deactivate',
		'uninstall',
		'drop',
		'reset',
		'clean',
		'flush',
		'remove',
		'spam',
		'trash',
	);

	/**
	 * Constructor.
	 *
	 * @param WP_CLI_Detector       $detector WP-CLI detector instance.
	 * @param WP_CLI_Command_Parser $parser   Command parser instance.
	 */
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

		wp_register_ability_category(
			'wp-cli',
			array(
				'label'       => __( 'WP-CLI Commands', 'wp-cli-abilities' ),
				'description' => __( 'WordPress CLI commands exposed as abilities for automation and AI agents.', 'wp-cli-abilities' ),
			)
		);
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

		$commands            = $this->detector->discover_commands();
		$allowed             = $this->get_allowed_commands();
		$blocked             = $this->get_blocked_commands();
		$destructive_enabled = (bool) get_option( 'wp_cli_abilities_allow_destructive', false );
		$eval_enabled        = (bool) get_option( 'wp_cli_abilities_allow_eval', false );
		$registered          = 0;
		$max_abilities       = (int) get_option( 'wp_cli_abilities_max', 200 );

		foreach ( $commands as $command ) {
			if ( $registered >= $max_abilities ) {
				break;
			}

			$name = $command['name'] ?? '';

			// Hard denylist — never exposed.
			if ( $this->is_denied( $name ) ) {
				continue;
			}

			// Skip eval/eval-file unless explicitly opted in.
			if ( ! $eval_enabled && in_array( $name, self::EVAL_COMMANDS, true ) ) {
				continue;
			}

			// Skip destructive commands unless explicitly opted in.
			if ( ! $destructive_enabled && $this->is_destructive( $name ) ) {
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
	 * Checks whether a command is in the hard denylist.
	 *
	 * @param string $name The command name.
	 * @return bool True if the command is denied.
	 */
	private function is_denied( string $name ): bool {
		// Exact match.
		if ( in_array( $name, self::DENIED_COMMANDS, true ) ) {
			return true;
		}

		// Prefix match — e.g. "cli" denies "cli info", "cli version", etc.
		foreach ( self::DENIED_COMMANDS as $denied ) {
			if ( str_starts_with( $name, $denied . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a command is destructive based on its subcommand.
	 *
	 * @param string $name The command name.
	 * @return bool True if the command is destructive.
	 */
	private function is_destructive( string $name ): bool {
		$parts      = explode( ' ', $name );
		$subcommand = end( $parts );
		return in_array( $subcommand, self::DESTRUCTIVE_SUBCOMMANDS, true );
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
	 *
	 * @param string   $name     The command name.
	 * @param string[] $prefixes The prefixes to match against.
	 * @return bool True if the name matches any prefix.
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
