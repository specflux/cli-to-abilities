<?php
/**
 * Plugin Name: WP-CLI to Abilities
 * Plugin URI:  https://github.com/specflux/cli-to-abilities
 * Description: Auto-detects WP-CLI commands and registers them as WordPress Abilities, making CLI functionality discoverable by AI agents and automation tools.
 * Version:     1.0.0
 * Author:      Specflux
 * Author URI:  https://github.com/specflux
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Text Domain: wp-cli-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_CLI_ABILITIES_VERSION', '1.0.0' );
define( 'WP_CLI_ABILITIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CLI_ABILITIES_PLUGIN_FILE', __FILE__ );

require_once WP_CLI_ABILITIES_PLUGIN_DIR . 'includes/class-wp-cli-detector.php';
require_once WP_CLI_ABILITIES_PLUGIN_DIR . 'includes/class-wp-cli-command-parser.php';
require_once WP_CLI_ABILITIES_PLUGIN_DIR . 'includes/class-wp-cli-ability-registrar.php';
require_once WP_CLI_ABILITIES_PLUGIN_DIR . 'includes/class-wp-cli-abilities-plugin.php';

/**
 * Returns the singleton plugin instance.
 */
function wp_cli_abilities(): WP_CLI_Abilities_Plugin {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new WP_CLI_Abilities_Plugin();
	}
	return $instance;
}

add_action( 'plugins_loaded', array( wp_cli_abilities(), 'init' ) );
