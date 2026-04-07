<?php
/**
 * Detects WP-CLI availability and discovers available commands.
 */
class WP_CLI_Detector {

	/**
	 * Cached WP-CLI binary path.
	 *
	 * @var string|false|null
	 */
	private $wp_cli_path = null;

	/**
	 * Cached raw command data from WP-CLI.
	 *
	 * @var array|null
	 */
	private $commands_cache = null;

	/**
	 * Checks whether WP-CLI is available on this system.
	 */
	public function is_available(): bool {
		return false !== $this->get_wp_cli_path();
	}

	/**
	 * Returns the resolved path to the wp-cli binary, or false if not found.
	 */
	public function get_wp_cli_path(): string|false {
		if ( null !== $this->wp_cli_path ) {
			return $this->wp_cli_path;
		}

		// When running inside WP-CLI itself, the binary is always available.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->wp_cli_path = 'wp';
			return $this->wp_cli_path;
		}

		// If shell execution is disabled, we can't detect WP-CLI externally.
		if ( ! function_exists( 'shell_exec' ) || $this->is_shell_disabled() ) {
			$this->wp_cli_path = false;
			return false;
		}

		// Try to locate the wp binary on the system.
		$which = shell_exec( 'command -v wp 2>/dev/null' );
		if ( $which ) {
			$this->wp_cli_path = trim( $which );
			return $this->wp_cli_path;
		}

		// Check common installation paths.
		$common_paths = array(
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			getenv( 'HOME' ) . '/.local/bin/wp',
			ABSPATH . 'vendor/bin/wp',
		);

		foreach ( $common_paths as $path ) {
			if ( $path && is_executable( $path ) ) {
				$this->wp_cli_path = $path;
				return $this->wp_cli_path;
			}
		}

		$this->wp_cli_path = false;
		return false;
	}

	/**
	 * Checks if shell_exec is in the disabled functions list.
	 */
	private function is_shell_disabled(): bool {
		$disabled = explode( ',', ini_get( 'disable_functions' ) ?: '' );
		$disabled = array_map( 'trim', $disabled );
		return in_array( 'shell_exec', $disabled, true );
	}

	/**
	 * Discovers all top-level WP-CLI commands and their subcommands.
	 *
	 * Returns a structured array of commands with their arguments and options.
	 *
	 * @return array[] Array of command descriptors.
	 */
	public function discover_commands(): array {
		if ( null !== $this->commands_cache ) {
			return $this->commands_cache;
		}

		$transient_key = 'wp_cli_abilities_commands';
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			$this->commands_cache = $cached;
			return $cached;
		}

		$commands = array();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$commands = $this->discover_commands_internal();
		} else {
			$commands = $this->discover_commands_external();
		}

		// Cache for 1 hour.
		set_transient( $transient_key, $commands, HOUR_IN_SECONDS );
		$this->commands_cache = $commands;

		return $commands;
	}

	/**
	 * Clears the cached command data so the next call re-discovers.
	 */
	public function clear_cache(): void {
		$this->commands_cache = null;
		delete_transient( 'wp_cli_abilities_commands' );
	}

	/**
	 * Discovers commands when running inside a WP-CLI process.
	 */
	private function discover_commands_internal(): array {
		if ( ! class_exists( 'WP_CLI' ) || ! method_exists( 'WP_CLI', 'get_root_command' ) ) {
			return array();
		}

		$root     = \WP_CLI::get_root_command();
		$commands = array();

		foreach ( $root->get_subcommands() as $name => $command ) {
			$commands = array_merge( $commands, $this->parse_command_object( $name, $command ) );
		}

		return $commands;
	}

	/**
	 * Recursively parses a WP-CLI command object into a flat list.
	 */
	private function parse_command_object( string $prefix, $command ): array {
		$commands = array();

		if ( method_exists( $command, 'get_subcommands' ) ) {
			$subs = $command->get_subcommands();
			if ( ! empty( $subs ) ) {
				foreach ( $subs as $sub_name => $sub_command ) {
					$commands = array_merge(
						$commands,
						$this->parse_command_object( "$prefix $sub_name", $sub_command )
					);
				}
				return $commands;
			}
		}

		$synopsis   = method_exists( $command, 'get_synopsis' ) ? $command->get_synopsis() : '';
		$short_desc = method_exists( $command, 'get_shortdesc' ) ? $command->get_shortdesc() : '';
		$long_desc  = method_exists( $command, 'get_longdesc' ) ? $command->get_longdesc() : '';

		$commands[] = array(
			'name'        => $prefix,
			'synopsis'    => $synopsis,
			'description' => $short_desc,
			'long_desc'   => $long_desc,
		);

		return $commands;
	}

	/**
	 * Discovers commands by shelling out to the wp binary.
	 */
	private function discover_commands_external(): array {
		$wp = $this->get_wp_cli_path();
		if ( ! $wp ) {
			return array();
		}

		$wp_path = escapeshellarg( ABSPATH );
		$wp_bin  = escapeshellarg( $wp );

		// Get the list of top-level commands.
		$top_level_json = shell_exec(
			sprintf( '%s --path=%s cli cmd-dump --format=json 2>/dev/null', $wp_bin, $wp_path )
		);

		if ( $top_level_json ) {
			$tree = json_decode( $top_level_json, true );
			if ( is_array( $tree ) ) {
				return $this->flatten_command_tree( $tree );
			}
		}

		// Fallback: parse plain-text help output.
		return $this->discover_commands_fallback( $wp_bin, $wp_path );
	}

	/**
	 * Recursively flattens the JSON command tree from `wp cli cmd-dump`.
	 */
	private function flatten_command_tree( array $node, string $prefix = '' ): array {
		$commands = array();
		$name     = ltrim( $prefix . ' ' . ( $node['name'] ?? '' ) );

		if ( ! empty( $node['subcommands'] ) ) {
			foreach ( $node['subcommands'] as $sub ) {
				$commands = array_merge( $commands, $this->flatten_command_tree( $sub, $name ) );
			}
		} else {
			$synopsis = '';
			if ( ! empty( $node['synopsis'] ) ) {
				if ( is_array( $node['synopsis'] ) ) {
					$synopsis = $this->build_synopsis_string( $node['synopsis'] );
				} else {
					$synopsis = $node['synopsis'];
				}
			}

			$commands[] = array(
				'name'        => trim( $name ),
				'synopsis'    => $synopsis,
				'description' => $node['description'] ?? '',
				'long_desc'   => $node['longdesc'] ?? '',
			);
		}

		return $commands;
	}

	/**
	 * Builds a synopsis string from the structured synopsis array.
	 */
	private function build_synopsis_string( array $parts ): string {
		$pieces = array();
		foreach ( $parts as $part ) {
			$type = $part['type'] ?? '';
			$pname = $part['name'] ?? '';
			$optional = ! empty( $part['optional'] );

			switch ( $type ) {
				case 'positional':
					$pieces[] = $optional ? "[<$pname>]" : "<$pname>";
					break;
				case 'assoc':
					$token = "--$pname=<value>";
					$pieces[] = $optional ? "[$token]" : $token;
					break;
				case 'flag':
					$pieces[] = "[--$pname]";
					break;
				case 'generic':
					$pieces[] = '[--<field>=<value>]';
					break;
			}
		}
		return implode( ' ', $pieces );
	}

	/**
	 * Fallback command discovery using `wp help` text parsing.
	 */
	private function discover_commands_fallback( string $wp_bin, string $wp_path ): array {
		$output = shell_exec(
			sprintf( '%s --path=%s help 2>/dev/null', $wp_bin, $wp_path )
		);

		if ( ! $output ) {
			return array();
		}

		$commands   = array();
		$in_section = false;

		foreach ( explode( "\n", $output ) as $line ) {
			if ( preg_match( '/^AVAILABLE COMMANDS$/i', trim( $line ) ) ) {
				$in_section = true;
				continue;
			}

			if ( $in_section && preg_match( '/^\s{2,}(\S+)\s+(.+)$/', $line, $m ) ) {
				$cmd_name = trim( $m[1] );
				$cmd_desc = trim( $m[2] );

				// Skip meta commands.
				if ( in_array( $cmd_name, array( 'help', 'cli' ), true ) ) {
					continue;
				}

				$sub_commands = $this->discover_subcommands_fallback( $wp_bin, $wp_path, $cmd_name );

				if ( ! empty( $sub_commands ) ) {
					$commands = array_merge( $commands, $sub_commands );
				} else {
					$commands[] = array(
						'name'        => $cmd_name,
						'synopsis'    => '',
						'description' => $cmd_desc,
						'long_desc'   => '',
					);
				}
			}
		}

		return $commands;
	}

	/**
	 * Discovers subcommands for a given top-level command via text parsing.
	 */
	private function discover_subcommands_fallback( string $wp_bin, string $wp_path, string $parent ): array {
		$output = shell_exec(
			sprintf(
				'%s --path=%s help %s 2>/dev/null',
				$wp_bin,
				$wp_path,
				escapeshellarg( $parent )
			)
		);

		if ( ! $output ) {
			return array();
		}

		$commands   = array();
		$in_section = false;

		foreach ( explode( "\n", $output ) as $line ) {
			if ( preg_match( '/^SUBCOMMANDS$/i', trim( $line ) ) ) {
				$in_section = true;
				continue;
			}

			if ( $in_section && preg_match( '/^\s{2,}(\S+)\s+(.+)$/', $line, $m ) ) {
				$commands[] = array(
					'name'        => "$parent " . trim( $m[1] ),
					'synopsis'    => '',
					'description' => trim( $m[2] ),
					'long_desc'   => '',
				);
			}
		}

		return $commands;
	}
}
