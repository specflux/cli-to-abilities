<?php
/**
 * Guardrails for WP-CLI ability execution: audit logging, rate limiting,
 * and dry-run support.
 *
 * @package WP_CLI_Abilities
 */
class WP_CLI_Guardrails {

	/**
	 * Custom log table name.
	 */
	private const LOG_OPTION = 'wp_cli_abilities_audit_log';

	/**
	 * Rate limit: max executions per user per minute.
	 */
	private const DEFAULT_RATE_LIMIT = 30;

	/**
	 * Checks rate limit before execution.
	 *
	 * @param int    $user_id      Current user ID.
	 * @param string $ability_name Ability being executed (passed for filter extensibility).
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public static function check_rate_limit( int $user_id, string $ability_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Passed for filter extensibility.
		$limit   = (int) apply_filters( 'wp_cli_abilities_rate_limit', self::DEFAULT_RATE_LIMIT, $user_id );
		$key     = 'wp_cli_abilities_rate_' . $user_id;
		$current = (int) get_transient( $key );

		if ( $current >= $limit ) {
			return new WP_Error(
				'wp_cli_rate_limited',
				sprintf(
					// translators: %d is the maximum number of executions allowed per minute.
					__( 'Rate limit exceeded: %d executions per minute. Try again shortly.', 'wp-cli-abilities' ),
					$limit
				),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Logs an ability execution to the audit log.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $input        Input parameters (sanitized).
	 * @param mixed  $result       Execution result or WP_Error.
	 * @param int    $user_id      User who executed.
	 */
	public static function log_execution( string $ability_name, array $input, $result, int $user_id ): void {
		if ( ! get_option( 'wp_cli_abilities_audit_enabled', true ) ) {
			return;
		}

		$entry = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'user_id' => $user_id,
			'ability' => $ability_name,
			'input'   => self::sanitize_log_input( $input ),
			'success' => ! is_wp_error( $result ),
			'error'   => is_wp_error( $result ) ? $result->get_error_message() : null,
		);

		$log = get_option( self::LOG_OPTION, array() );

		// Keep last 500 entries to prevent unbounded growth.
		if ( count( $log ) >= 500 ) {
			$log = array_slice( $log, -499 );
		}

		$log[] = $entry;
		update_option( self::LOG_OPTION, $log, false ); // No autoload.

		/**
		 * Fires after an ability execution is logged.
		 *
		 * @param array $entry Log entry.
		 */
		do_action( 'wp_cli_abilities_logged', $entry );
	}

	/**
	 * Returns the audit log entries.
	 *
	 * @param int $limit Max entries to return (newest first).
	 * @return array
	 */
	public static function get_log( int $limit = 50 ): array {
		$log = get_option( self::LOG_OPTION, array() );
		$log = array_reverse( $log ); // Newest first.
		return array_slice( $log, 0, $limit );
	}

	/**
	 * Clears the audit log.
	 */
	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Strips sensitive values from input before logging.
	 *
	 * @param array $input Input parameters to sanitize.
	 * @return array Sanitized input with sensitive values redacted.
	 */
	private static function sanitize_log_input( array $input ): array {
		$sensitive_keys = array( 'password', 'pass', 'secret', 'token', 'key', 'user_pass' );
		$sanitized      = array();

		foreach ( $input as $k => $v ) {
			if ( in_array( strtolower( $k ), $sensitive_keys, true ) ) {
				$sanitized[ $k ] = '***';
			} elseif ( is_string( $v ) && strlen( $v ) > 200 ) {
				$sanitized[ $k ] = substr( $v, 0, 200 ) . '...(truncated)';
			} else {
				$sanitized[ $k ] = $v;
			}
		}

		return $sanitized;
	}
}
