<?php
/**
 * Tests for WP_CLI_Ability_Registrar.
 */
class RegistrarTest extends \PHPUnit\Framework\TestCase {

	private WP_CLI_Ability_Registrar $registrar;

	protected function setUp(): void {
		wp_test_reset_store();
		$detector        = new WP_CLI_Detector();
		$parser          = new WP_CLI_Command_Parser();
		$this->registrar = new WP_CLI_Ability_Registrar( $detector, $parser );
	}

	public function test_register_category_without_api(): void {
		// Should not throw when wp_register_ability_category is absent.
		$this->registrar->register_category();
		$this->assertTrue( true );
	}

	public function test_register_abilities_without_api(): void {
		// Should exit early without error when wp_register_ability is absent.
		$this->registrar->register_abilities();
		$this->assertTrue( true );
	}

	public function test_denied_commands_detected(): void {
		// Use reflection to test is_denied.
		$ref    = new ReflectionMethod( $this->registrar, 'is_denied' );
		$ref->setAccessible( true );

		// eval/eval-file are opt-in, NOT hard-denied.
		$this->assertFalse( $ref->invoke( $this->registrar, 'eval' ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'eval-file' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'db export' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'config get' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'shell' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'cli info' ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'plugin list' ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'post create' ) );
	}

	public function test_destructive_commands_detected(): void {
		$ref = new ReflectionMethod( $this->registrar, 'is_destructive' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( $this->registrar, 'plugin delete' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'user trash' ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'cache flush' ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'plugin list' ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'post get' ) );
	}

	public function test_eval_not_denied_when_in_eval_list(): void {
		// eval is in EVAL_COMMANDS, not DENIED_COMMANDS — is_denied should return false.
		$ref = new ReflectionMethod( $this->registrar, 'is_denied' );
		$ref->setAccessible( true );

		// eval should NOT be in the hard denylist (moved to opt-in).
		$this->assertFalse( $ref->invoke( $this->registrar, 'eval' ) );
	}

	public function test_allowed_filter(): void {
		$ref = new ReflectionMethod( $this->registrar, 'matches_filter' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( $this->registrar, 'plugin list', array( 'plugin' ) ) );
		$this->assertTrue( $ref->invoke( $this->registrar, 'plugin install', array( 'plugin', 'theme' ) ) );
		$this->assertFalse( $ref->invoke( $this->registrar, 'user list', array( 'plugin' ) ) );
	}
}
