<?php
/**
 * Tests for WP_CLI_Guardrails.
 */
class GuardrailsTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		wp_test_reset_store();
	}

	// -- Rate limiting --------------------------------------------------------

	public function test_rate_limit_allows_first_request(): void {
		$result = WP_CLI_Guardrails::check_rate_limit( 1, 'plugin list' );
		$this->assertTrue( $result );
	}

	public function test_rate_limit_blocks_after_threshold(): void {
		// Set transient to simulate hitting the limit.
		set_transient( 'wp_cli_abilities_rate_1', 30 );

		$result = WP_CLI_Guardrails::check_rate_limit( 1, 'plugin list' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_cli_rate_limited', $result->get_error_code() );
	}

	public function test_rate_limit_increments_counter(): void {
		WP_CLI_Guardrails::check_rate_limit( 42, 'plugin list' );
		$count = get_transient( 'wp_cli_abilities_rate_42' );
		$this->assertSame( 1, $count );

		WP_CLI_Guardrails::check_rate_limit( 42, 'plugin list' );
		$count = get_transient( 'wp_cli_abilities_rate_42' );
		$this->assertSame( 2, $count );
	}

	public function test_rate_limit_per_user(): void {
		WP_CLI_Guardrails::check_rate_limit( 1, 'plugin list' );
		WP_CLI_Guardrails::check_rate_limit( 2, 'plugin list' );

		$this->assertSame( 1, get_transient( 'wp_cli_abilities_rate_1' ) );
		$this->assertSame( 1, get_transient( 'wp_cli_abilities_rate_2' ) );
	}

	// -- Audit logging --------------------------------------------------------

	public function test_log_records_entry(): void {
		WP_CLI_Guardrails::log_execution(
			'wp-cli/plugin-list',
			array( 'status' => 'active' ),
			array( 'success' => true ),
			1
		);

		$log = WP_CLI_Guardrails::get_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'wp-cli/plugin-list', $log[0]['ability'] );
		$this->assertSame( 1, $log[0]['user_id'] );
		$this->assertTrue( $log[0]['success'] );
	}

	public function test_log_records_error(): void {
		$error = new WP_Error( 'fail', 'Command failed' );
		WP_CLI_Guardrails::log_execution( 'wp-cli/plugin-delete', array(), $error, 1 );

		$log = WP_CLI_Guardrails::get_log();
		$this->assertFalse( $log[0]['success'] );
		$this->assertSame( 'Command failed', $log[0]['error'] );
	}

	public function test_log_redacts_sensitive_values(): void {
		WP_CLI_Guardrails::log_execution(
			'wp-cli/user-create',
			array( 'user' => 'admin', 'password' => 'secret123', 'token' => 'abc' ),
			array( 'success' => true ),
			1
		);

		$log = WP_CLI_Guardrails::get_log();
		$this->assertSame( 'admin', $log[0]['input']['user'] );
		$this->assertSame( '***', $log[0]['input']['password'] );
		$this->assertSame( '***', $log[0]['input']['token'] );
	}

	public function test_log_truncates_long_values(): void {
		$long_value = str_repeat( 'x', 300 );
		WP_CLI_Guardrails::log_execution(
			'wp-cli/eval',
			array( 'code' => $long_value ),
			array( 'success' => true ),
			1
		);

		$log = WP_CLI_Guardrails::get_log();
		$this->assertStringContainsString( '...(truncated)', $log[0]['input']['code'] );
		$this->assertLessThan( 300, strlen( $log[0]['input']['code'] ) );
	}

	public function test_log_caps_at_500_entries(): void {
		$log = array_fill( 0, 500, array( 'time' => '', 'ability' => 'old' ) );
		update_option( 'wp_cli_abilities_audit_log', $log );

		WP_CLI_Guardrails::log_execution( 'wp-cli/new-cmd', array(), array(), 1 );

		$full_log = get_option( 'wp_cli_abilities_audit_log' );
		$this->assertCount( 500, $full_log );
		$this->assertSame( 'wp-cli/new-cmd', end( $full_log )['ability'] );
	}

	public function test_log_disabled_skips_write(): void {
		update_option( 'wp_cli_abilities_audit_enabled', false );

		WP_CLI_Guardrails::log_execution( 'wp-cli/plugin-list', array(), array(), 1 );

		$log = WP_CLI_Guardrails::get_log();
		$this->assertEmpty( $log );
	}

	public function test_clear_log(): void {
		WP_CLI_Guardrails::log_execution( 'wp-cli/test', array(), array(), 1 );
		$this->assertNotEmpty( WP_CLI_Guardrails::get_log() );

		WP_CLI_Guardrails::clear_log();
		$this->assertEmpty( WP_CLI_Guardrails::get_log() );
	}

	public function test_get_log_returns_newest_first(): void {
		WP_CLI_Guardrails::log_execution( 'wp-cli/first', array(), array(), 1 );
		WP_CLI_Guardrails::log_execution( 'wp-cli/second', array(), array(), 1 );

		$log = WP_CLI_Guardrails::get_log();
		$this->assertSame( 'wp-cli/second', $log[0]['ability'] );
		$this->assertSame( 'wp-cli/first', $log[1]['ability'] );
	}

	public function test_get_log_respects_limit(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			WP_CLI_Guardrails::log_execution( "wp-cli/cmd-$i", array(), array(), 1 );
		}

		$log = WP_CLI_Guardrails::get_log( 3 );
		$this->assertCount( 3, $log );
	}
}
