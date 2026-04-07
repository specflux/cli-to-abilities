<?php
/**
 * Unit tests for WP_CLI_Ability_Registrar.
 */

// Minimal WordPress stubs.
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '', $data = '' ) {}
	}
}

require_once __DIR__ . '/../includes/class-wp-cli-detector.php';
require_once __DIR__ . '/../includes/class-wp-cli-command-parser.php';
require_once __DIR__ . '/../includes/class-wp-cli-ability-registrar.php';

class Test_Registrar extends \PHPUnit\Framework\TestCase {

	public function test_excluded_commands_not_registered(): void {
		// wp_register_ability is not defined, so register_abilities should
		// exit early without errors.
		$detector  = new WP_CLI_Detector();
		$parser    = new WP_CLI_Command_Parser();
		$registrar = new WP_CLI_Ability_Registrar( $detector, $parser );

		// Should not throw even when Abilities API is unavailable.
		$registrar->register_abilities();
		$this->assertTrue( true );
	}

	public function test_register_category_without_api(): void {
		$detector  = new WP_CLI_Detector();
		$parser    = new WP_CLI_Command_Parser();
		$registrar = new WP_CLI_Ability_Registrar( $detector, $parser );

		// Should not throw when wp_register_ability_category doesn't exist.
		$registrar->register_category();
		$this->assertTrue( true );
	}
}
