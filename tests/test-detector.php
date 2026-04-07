<?php
/**
 * Unit tests for WP_CLI_Detector.
 */

// Minimal WordPress stubs.
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		return true;
	}
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

require_once __DIR__ . '/../includes/class-wp-cli-detector.php';

class Test_Detector extends \PHPUnit\Framework\TestCase {

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
		$result   = $detector->discover_commands();
		$this->assertIsArray( $result );
	}

	public function test_clear_cache(): void {
		$detector = new WP_CLI_Detector();
		$detector->discover_commands(); // Populate cache.
		$detector->clear_cache();
		// Should not throw.
		$this->assertIsArray( $detector->discover_commands() );
	}
}
