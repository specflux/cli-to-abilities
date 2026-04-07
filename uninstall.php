<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all options and transients created by the plugin.
 *
 * @package WP_CLI_Abilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
delete_option( 'wp_cli_abilities_allowed' );
delete_option( 'wp_cli_abilities_blocked' );
delete_option( 'wp_cli_abilities_max' );
delete_option( 'wp_cli_abilities_allow_destructive' );
delete_option( 'wp_cli_abilities_allow_eval' );
delete_option( 'wp_cli_abilities_audit_enabled' );
delete_option( 'wp_cli_abilities_version' );
delete_option( 'wp_cli_abilities_audit_log' );

// Site transients (multisite-safe).
delete_site_transient( 'wp_cli_abilities_commands' );

// Clean up per-user rate limit transients.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_cli_abilities_rate_%' OR option_name LIKE '_transient_timeout_wp_cli_abilities_rate_%'"
);
