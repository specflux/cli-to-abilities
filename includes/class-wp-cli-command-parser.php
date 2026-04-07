<?php
/**
 * Parses WP-CLI command descriptors into Abilities API-compatible structures.
 */
class WP_CLI_Command_Parser {

	/**
	 * Default capability required to run WP-CLI commands.
	 */
	private const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Map of WP-CLI command namespaces to WordPress capabilities.
	 *
	 * @var array<string, string>
	 */
	private const CAPABILITY_MAP = array(
		'plugin'  => 'activate_plugins',
		'theme'   => 'switch_themes',
		'user'    => 'list_users',
		'post'    => 'edit_posts',
		'comment' => 'moderate_comments',
		'option'  => 'manage_options',
		'media'   => 'upload_files',
		'menu'    => 'edit_theme_options',
		'widget'  => 'edit_theme_options',
		'sidebar' => 'edit_theme_options',
		'site'    => 'manage_sites',
		'network' => 'manage_network',
		'cron'    => 'manage_options',
		'cache'   => 'manage_options',
		'db'      => 'manage_options',
		'config'  => 'manage_options',
		'core'    => 'update_core',
		'rewrite' => 'manage_options',
		'role'    => 'promote_users',
		'cap'     => 'promote_users',
		'term'    => 'manage_categories',
		'taxonomy' => 'manage_categories',
	);

	/**
	 * Commands that perform destructive operations.
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
	);

	/**
	 * Commands that are read-only.
	 *
	 * @var string[]
	 */
	private const READONLY_SUBCOMMANDS = array(
		'list',
		'get',
		'status',
		'check',
		'search',
		'verify',
		'path',
		'is-installed',
		'is-active',
		'count',
	);

	/**
	 * Converts a raw command descriptor into an ability registration array.
	 *
	 * @param array $command Raw command data from WP_CLI_Detector.
	 * @return array Ability args compatible with wp_register_ability().
	 */
	public function to_ability_args( array $command ): array {
		$name        = $command['name'];
		$parts       = explode( ' ', $name );
		$namespace   = $parts[0] ?? 'wp';
		$subcommand  = $parts[ count( $parts ) - 1 ] ?? '';

		$ability_name = $this->build_ability_name( $name );
		$label        = $this->build_label( $name );
		$description  = $command['description'] ?: "Executes the WP-CLI command: wp $name";

		$input_schema  = $this->parse_synopsis_to_schema( $command['synopsis'] ?? '' );
		$output_schema = $this->build_output_schema( $subcommand );

		$is_readonly    = in_array( $subcommand, self::READONLY_SUBCOMMANDS, true );
		$is_destructive = in_array( $subcommand, self::DESTRUCTIVE_SUBCOMMANDS, true );
		$capability     = self::CAPABILITY_MAP[ $namespace ] ?? self::DEFAULT_CAPABILITY;

		return array(
			'ability_name' => $ability_name,
			'args'         => array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'wp-cli',
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $this->build_execute_callback( $name ),
				'permission_callback' => $this->build_permission_callback( $capability ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => $is_readonly,
						'destructive' => $is_destructive,
						'idempotent'  => $is_readonly,
					),
					'show_in_rest' => true,
					'wp_cli_command' => "wp $name",
				),
			),
		);
	}

	/**
	 * Builds a namespaced ability name from a WP-CLI command string.
	 *
	 * e.g. "plugin list" -> "wp-cli/plugin-list"
	 */
	public function build_ability_name( string $command_name ): string {
		$slug = str_replace( ' ', '-', trim( $command_name ) );
		$slug = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $slug ) );
		return 'wp-cli/' . $slug;
	}

	/**
	 * Builds a human-readable label.
	 */
	private function build_label( string $command_name ): string {
		$parts = explode( ' ', $command_name );
		$label = array_map( 'ucfirst', $parts );
		return 'WP-CLI: ' . implode( ' ', $label );
	}

	/**
	 * Parses a WP-CLI synopsis string into a JSON Schema for input_schema.
	 *
	 * Synopsis format examples:
	 *   <plugin>                     -> required positional arg
	 *   [<plugin>]                   -> optional positional arg
	 *   --field=<value>              -> required associative arg
	 *   [--field=<value>]            -> optional associative arg
	 *   [--flag]                     -> optional boolean flag
	 *   [--format=<format>]          -> optional with named value
	 *   [--<field>=<value>]          -> generic key-value pairs
	 */
	public function parse_synopsis_to_schema( string $synopsis ): array {
		if ( empty( trim( $synopsis ) ) ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		$properties = array();
		$required   = array();

		// Match all tokens in the synopsis.
		preg_match_all( '/\[?-{0,2}<?[\w\-]+=?<?[\w\-]*>?\]?/', $synopsis, $matches );

		foreach ( $matches[0] as $token ) {
			$token = trim( $token );
			if ( empty( $token ) ) {
				continue;
			}

			$optional = ( str_starts_with( $token, '[' ) );
			$clean    = trim( $token, '[]' );

			// Flag: --flag
			if ( preg_match( '/^--(\w[\w\-]*)$/', $clean, $m ) ) {
				$properties[ $m[1] ] = array(
					'type'        => 'boolean',
					'description' => "Enable the --{$m[1]} flag.",
				);
				continue;
			}

			// Associative: --name=<value>
			if ( preg_match( '/^--(\w[\w\-]*)=<([\w\-]+)>$/', $clean, $m ) ) {
				$prop = array(
					'type'        => 'string',
					'description' => "Value for --{$m[1]}.",
				);

				if ( 'format' === $m[1] ) {
					$prop['enum'] = array( 'table', 'csv', 'json', 'yaml', 'count', 'ids' );
					$prop['description'] = 'Output format.';
				}

				$properties[ $m[1] ] = $prop;

				if ( ! $optional ) {
					$required[] = $m[1];
				}
				continue;
			}

			// Positional: <name>
			if ( preg_match( '/^<([\w\-]+)>$/', $clean, $m ) ) {
				$properties[ $m[1] ] = array(
					'type'        => 'string',
					'description' => "Positional argument: {$m[1]}.",
				);
				if ( ! $optional ) {
					$required[] = $m[1];
				}
				continue;
			}

			// Generic: --<field>=<value>
			if ( preg_match( '/^--<([\w\-]+)>=<([\w\-]+)>$/', $clean ) ) {
				$properties['additional_fields'] = array(
					'type'        => 'object',
					'description' => 'Additional field=value pairs.',
					'additionalProperties' => array( 'type' => 'string' ),
				);
				continue;
			}
		}

		$schema = array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? new \stdClass() : $properties,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Builds a generic output schema based on the subcommand type.
	 */
	private function build_output_schema( string $subcommand ): array {
		if ( in_array( $subcommand, array( 'list', 'search' ), true ) ) {
			return array(
				'type'  => 'object',
				'properties' => array(
					'items' => array(
						'type'        => 'array',
						'description' => 'List of results.',
						'items'       => array( 'type' => 'object' ),
					),
					'count' => array(
						'type'        => 'integer',
						'description' => 'Total number of results.',
					),
				),
			);
		}

		if ( in_array( $subcommand, array( 'get', 'status', 'path', 'check' ), true ) ) {
			return array(
				'type'  => 'object',
				'properties' => array(
					'data' => array(
						'type'        => 'object',
						'description' => 'Retrieved data.',
					),
				),
			);
		}

		return array(
			'type'  => 'object',
			'properties' => array(
				'success' => array(
					'type'        => 'boolean',
					'description' => 'Whether the command completed successfully.',
				),
				'message' => array(
					'type'        => 'string',
					'description' => 'Result message from the command.',
				),
			),
		);
	}

	/**
	 * Builds the execute callback for a WP-CLI command.
	 */
	private function build_execute_callback( string $command_name ): callable {
		return function ( $input = array() ) use ( $command_name ) {
			return $this->execute_wp_cli_command( $command_name, $input );
		};
	}

	/**
	 * Executes a WP-CLI command with the given input.
	 *
	 * @param string $command_name The WP-CLI command (e.g. "plugin list").
	 * @param array  $input        Input parameters mapped from the ability schema.
	 * @return array|WP_Error
	 */
	public function execute_wp_cli_command( string $command_name, $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		// If running inside WP-CLI, use its internal runner.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			return $this->execute_internal( $command_name, $input );
		}

		return $this->execute_external( $command_name, $input );
	}

	/**
	 * Executes the command internally via WP_CLI::runcommand().
	 */
	private function execute_internal( string $command_name, array $input ): array|WP_Error {
		$cmd_string = $this->build_command_string( $command_name, $input );

		try {
			$result = \WP_CLI::runcommand( $cmd_string, array(
				'return'     => true,
				'parse'      => 'json',
				'launch'     => false,
				'exit_error' => false,
			) );

			return array(
				'success' => true,
				'data'    => $result,
				'message' => is_string( $result ) ? $result : 'Command executed successfully.',
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_cli_execution_failed',
				$e->getMessage(),
				array( 'status' => 500, 'command' => "wp $command_name" )
			);
		}
	}

	/**
	 * Checks whether shell execution functions are available.
	 */
	private function can_exec(): bool {
		$disabled = explode( ',', ini_get( 'disable_functions' ) ?: '' );
		$disabled = array_map( 'trim', $disabled );
		return ! in_array( 'exec', $disabled, true )
			&& ! in_array( 'shell_exec', $disabled, true )
			&& function_exists( 'exec' );
	}

	/**
	 * Executes the command externally by shelling out to the wp binary.
	 */
	private function execute_external( string $command_name, array $input ): array|WP_Error {
		if ( ! $this->can_exec() ) {
			return new WP_Error(
				'wp_cli_exec_disabled',
				__( 'Shell execution is disabled on this server (exec/shell_exec in disable_functions).', 'wp-cli-abilities' ),
				array( 'status' => 500 )
			);
		}

		$detector = new WP_CLI_Detector();
		$wp_bin   = $detector->get_wp_cli_path();

		if ( ! $wp_bin ) {
			return new WP_Error(
				'wp_cli_not_found',
				__( 'WP-CLI binary not found on this system.', 'wp-cli-abilities' ),
				array( 'status' => 500 )
			);
		}

		$cmd_string = $this->build_command_string( $command_name, $input );

		// Not all commands support --format=json. Use it only for known list/get
		// commands that produce structured output.
		$subcommand  = $this->get_subcommand( $command_name );
		$format_flag = in_array( $subcommand, array( 'list', 'get', 'search', 'check', 'status' ), true )
			? ' --format=json'
			: '';

		$timeout = (int) apply_filters( 'wp_cli_abilities_exec_timeout', 30 );

		$full_cmd = sprintf(
			'timeout %d %s --path=%s %s --no-interaction%s 2>&1',
			$timeout,
			escapeshellarg( $wp_bin ),
			escapeshellarg( ABSPATH ),
			$cmd_string,
			$format_flag
		);

		$output      = array();
		$return_code = 0;

		exec( $full_cmd, $output, $return_code );

		$raw_output = implode( "\n", $output );

		// Exit code 124 = timeout killed the process.
		if ( 124 === $return_code ) {
			return new WP_Error(
				'wp_cli_timeout',
				sprintf(
					__( 'WP-CLI command timed out after %d seconds.', 'wp-cli-abilities' ),
					$timeout
				),
				array( 'status' => 504, 'command' => "wp $command_name" )
			);
		}

		if ( 0 !== $return_code ) {
			return new WP_Error(
				'wp_cli_command_failed',
				$raw_output ?: __( 'WP-CLI command failed.', 'wp-cli-abilities' ),
				array( 'status' => 500, 'command' => "wp $command_name", 'exit_code' => $return_code )
			);
		}

		$decoded = json_decode( $raw_output, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			$is_list = is_array( $decoded ) && isset( $decoded[0] );
			return array(
				'success' => true,
				'data'    => $decoded,
				'items'   => $is_list ? $decoded : null,
				'count'   => $is_list ? count( $decoded ) : null,
				'message' => 'Command executed successfully.',
			);
		}

		return array(
			'success' => true,
			'message' => $raw_output,
		);
	}

	/**
	 * Extracts the subcommand (last word) from a command name.
	 */
	private function get_subcommand( string $command_name ): string {
		$parts = explode( ' ', $command_name );
		return end( $parts );
	}

	/**
	 * Assembles the WP-CLI command string from input parameters.
	 *
	 * Keys are validated against a strict allowlist pattern to prevent injection.
	 */
	private function build_command_string( string $command_name, array $input ): string {
		$parts = array( $command_name );

		foreach ( $input as $key => $value ) {
			// Validate key: only allow alphanumeric, hyphens, underscores.
			if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $key ) ) {
				continue;
			}

			if ( 'additional_fields' === $key && is_array( $value ) ) {
				foreach ( $value as $field => $field_value ) {
					if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $field ) ) {
						continue;
					}
					$parts[] = sprintf( '--%s=%s', $field, escapeshellarg( (string) $field_value ) );
				}
				continue;
			}

			if ( is_bool( $value ) ) {
				if ( $value ) {
					$parts[] = '--' . $key;
				}
				continue;
			}

			// Positional args have numeric-ish keys or are marked positional by schema.
			$positionals = array( 'plugin', 'theme', 'slug', 'post_id', 'id', 'user', 'key', 'value', 'term', 'role', 'file' );
			if ( in_array( $key, $positionals, true ) ) {
				$parts[] = escapeshellarg( (string) $value );
				continue;
			}

			$parts[] = sprintf( '--%s=%s', $key, escapeshellarg( (string) $value ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Builds a permission callback for a given WordPress capability.
	 */
	public function build_permission_callback( string $capability ): callable {
		return function () use ( $capability ): bool {
			return current_user_can( $capability );
		};
	}
}
