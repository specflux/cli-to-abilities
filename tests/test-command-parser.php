<?php
/**
 * Unit tests for WP_CLI_Command_Parser.
 *
 * Run with: phpunit --bootstrap tests/bootstrap.php tests/test-command-parser.php
 */

// Minimal stubs so the class can be loaded outside WordPress.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}

require_once __DIR__ . '/../includes/class-wp-cli-command-parser.php';

class Test_Command_Parser extends \PHPUnit\Framework\TestCase {

	private WP_CLI_Command_Parser $parser;

	protected function setUp(): void {
		$this->parser = new WP_CLI_Command_Parser();
	}

	public function test_build_ability_name_simple(): void {
		$this->assertSame( 'wp-cli/plugin-list', $this->parser->build_ability_name( 'plugin list' ) );
	}

	public function test_build_ability_name_nested(): void {
		$this->assertSame( 'wp-cli/plugin-install', $this->parser->build_ability_name( 'plugin install' ) );
	}

	public function test_build_ability_name_single(): void {
		$this->assertSame( 'wp-cli/cache-flush', $this->parser->build_ability_name( 'cache flush' ) );
	}

	public function test_parse_empty_synopsis(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '' );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertEmpty( (array) $schema['properties'] );
	}

	public function test_parse_positional_required(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '<plugin>' );
		$this->assertArrayHasKey( 'plugin', (array) $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['plugin']['type'] );
		$this->assertContains( 'plugin', $schema['required'] );
	}

	public function test_parse_positional_optional(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[<plugin>]' );
		$this->assertArrayHasKey( 'plugin', (array) $schema['properties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	public function test_parse_flag(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--all]' );
		$this->assertArrayHasKey( 'all', (array) $schema['properties'] );
		$this->assertSame( 'boolean', $schema['properties']['all']['type'] );
	}

	public function test_parse_assoc_required(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '--status=<status>' );
		$this->assertArrayHasKey( 'status', (array) $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['status']['type'] );
		$this->assertContains( 'status', $schema['required'] );
	}

	public function test_parse_assoc_optional(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--status=<status>]' );
		$this->assertArrayHasKey( 'status', (array) $schema['properties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	public function test_parse_format_enum(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--format=<format>]' );
		$this->assertArrayHasKey( 'format', (array) $schema['properties'] );
		$this->assertNotEmpty( $schema['properties']['format']['enum'] );
		$this->assertContains( 'json', $schema['properties']['format']['enum'] );
	}

	public function test_parse_complex_synopsis(): void {
		$synopsis = '<plugin> [--version=<version>] [--force] [--format=<format>]';
		$schema   = $this->parser->parse_synopsis_to_schema( $synopsis );

		$props = (array) $schema['properties'];
		$this->assertArrayHasKey( 'plugin', $props );
		$this->assertArrayHasKey( 'version', $props );
		$this->assertArrayHasKey( 'force', $props );
		$this->assertArrayHasKey( 'format', $props );
		$this->assertContains( 'plugin', $schema['required'] );
	}

	public function test_to_ability_args_structure(): void {
		$command = array(
			'name'        => 'plugin list',
			'synopsis'    => '[--status=<status>] [--format=<format>]',
			'description' => 'Lists plugins.',
			'long_desc'   => '',
		);

		$result = $this->parser->to_ability_args( $command );

		$this->assertSame( 'wp-cli/plugin-list', $result['ability_name'] );
		$this->assertSame( 'WP-CLI: Plugin List', $result['args']['label'] );
		$this->assertSame( 'Lists plugins.', $result['args']['description'] );
		$this->assertSame( 'wp-cli', $result['args']['category'] );
		$this->assertIsCallable( $result['args']['execute_callback'] );
		$this->assertIsCallable( $result['args']['permission_callback'] );
		$this->assertTrue( $result['args']['meta']['annotations']['readonly'] );
		$this->assertFalse( $result['args']['meta']['annotations']['destructive'] );
	}

	public function test_destructive_annotation(): void {
		$command = array(
			'name'        => 'plugin delete',
			'synopsis'    => '<plugin>',
			'description' => 'Deletes a plugin.',
			'long_desc'   => '',
		);

		$result = $this->parser->to_ability_args( $command );
		$this->assertTrue( $result['args']['meta']['annotations']['destructive'] );
		$this->assertFalse( $result['args']['meta']['annotations']['readonly'] );
	}

	public function test_permission_callback_uses_correct_capability(): void {
		$callback = $this->parser->build_permission_callback( 'activate_plugins' );
		// In our stub, current_user_can always returns true.
		$this->assertTrue( $callback() );
	}

	public function test_generic_field_parsed(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--<field>=<value>]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'additional_fields', $props );
		$this->assertSame( 'object', $props['additional_fields']['type'] );
	}
}
