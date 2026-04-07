<?php
/**
 * Tests for WP_CLI_Detector.
 */
class DetectorTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		wp_test_reset_store();
	}

	public function test_is_available_returns_bool(): void {
		$detector = new WP_CLI_Detector();
		$this->assertIsBool( $detector->is_available() );
	}

	public function test_get_wp_cli_path_returns_string_or_false(): void {
		$detector = new WP_CLI_Detector();
		$result   = $detector->get_wp_cli_path();
		$this->assertTrue( is_string( $result ) || false === $result );
	}

	public function test_discover_commands_returns_array(): void {
		$detector = new WP_CLI_Detector();
		$this->assertIsArray( $detector->discover_commands() );
	}

	public function test_clear_cache_resets_internal_cache(): void {
		$detector = new WP_CLI_Detector();
		$detector->discover_commands();
		$detector->clear_cache();
		// Should not throw — re-discovers after clear.
		$this->assertIsArray( $detector->discover_commands() );
	}

	public function test_clear_cache_deletes_site_transient(): void {
		$detector = new WP_CLI_Detector();
		set_site_transient( 'wp_cli_abilities_commands', array( 'fake' ) );
		$detector->clear_cache();
		$this->assertFalse( get_site_transient( 'wp_cli_abilities_commands' ) );
	}

	public function test_discover_commands_uses_cache(): void {
		$detector = new WP_CLI_Detector();
		$fake     = array( array( 'name' => 'fake cmd', 'synopsis' => '', 'description' => 'Fake', 'long_desc' => '' ) );
		set_site_transient( 'wp_cli_abilities_commands', $fake );

		$result = $detector->discover_commands();
		$this->assertSame( $fake, $result );
	}
}
