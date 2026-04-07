<?php
/**
 * Tests for WP_CLI_Command_Parser.
 */
class CommandParserTest extends \PHPUnit\Framework\TestCase {

	private WP_CLI_Command_Parser $parser;

	protected function setUp(): void {
		$this->parser = new WP_CLI_Command_Parser();
		wp_test_reset_store();
	}

	// -- Ability naming -------------------------------------------------------

	public function test_ability_name_from_simple_command(): void {
		$this->assertSame( 'wp-cli/plugin-list', $this->parser->build_ability_name( 'plugin list' ) );
	}

	public function test_ability_name_from_nested_command(): void {
		$this->assertSame( 'wp-cli/plugin-install', $this->parser->build_ability_name( 'plugin install' ) );
	}

	public function test_ability_name_lowercased(): void {
		$this->assertSame( 'wp-cli/cache-flush', $this->parser->build_ability_name( 'cache flush' ) );
	}

	public function test_ability_name_strips_invalid_chars(): void {
		$this->assertSame( 'wp-cli/some-cmd', $this->parser->build_ability_name( 'some cmd!' ) );
	}

	// -- Synopsis parsing -----------------------------------------------------

	public function test_empty_synopsis(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '' );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertEmpty( (array) $schema['properties'] );
	}

	public function test_required_positional(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '<plugin>' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'plugin', $props );
		$this->assertSame( 'string', $props['plugin']['type'] );
		$this->assertContains( 'plugin', $schema['required'] );
	}

	public function test_optional_positional(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[<plugin>]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'plugin', $props );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	public function test_boolean_flag(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--all]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'all', $props );
		$this->assertSame( 'boolean', $props['all']['type'] );
	}

	public function test_required_assoc(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '--status=<status>' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'status', $props );
		$this->assertContains( 'status', $schema['required'] );
	}

	public function test_optional_assoc(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--status=<status>]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'status', $props );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	public function test_format_has_enum(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--format=<format>]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'format', $props );
		$this->assertContains( 'json', $props['format']['enum'] );
	}

	public function test_generic_field(): void {
		$schema = $this->parser->parse_synopsis_to_schema( '[--<field>=<value>]' );
		$props  = (array) $schema['properties'];
		$this->assertArrayHasKey( 'additional_fields', $props );
		$this->assertSame( 'object', $props['additional_fields']['type'] );
	}

	public function test_complex_synopsis(): void {
		$synopsis = '<plugin> [--version=<version>] [--force] [--format=<format>]';
		$schema   = $this->parser->parse_synopsis_to_schema( $synopsis );
		$props    = (array) $schema['properties'];

		$this->assertArrayHasKey( 'plugin', $props );
		$this->assertArrayHasKey( 'version', $props );
		$this->assertArrayHasKey( 'force', $props );
		$this->assertArrayHasKey( 'format', $props );
		$this->assertContains( 'plugin', $schema['required'] );
	}

	// -- to_ability_args ------------------------------------------------------

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
		$this->assertTrue( $result['args']['meta']['show_in_rest'] );
	}

	public function test_readonly_annotation_for_list(): void {
		$command = array(
			'name'        => 'plugin list',
			'synopsis'    => '',
			'description' => 'Lists plugins.',
			'long_desc'   => '',
		);

		$result = $this->parser->to_ability_args( $command );
		$this->assertTrue( $result['args']['meta']['annotations']['readonly'] );
		$this->assertFalse( $result['args']['meta']['annotations']['destructive'] );
	}

	public function test_destructive_annotation_for_delete(): void {
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

	public function test_permission_callback_returns_bool(): void {
		$callback = $this->parser->build_permission_callback( 'activate_plugins' );
		$this->assertTrue( $callback() );
	}

	// -- Dry run --------------------------------------------------------------

	public function test_dry_run_returns_command_string(): void {
		$result = $this->parser->execute_wp_cli_command( 'plugin list', array( '_dry_run' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertStringContainsString( 'plugin list', $result['command'] );
	}
}
