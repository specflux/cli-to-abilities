<?php
/**
 * PHPUnit bootstrap — provides minimal WordPress stubs so plugin classes
 * can be loaded and unit-tested without a full WordPress installation.
 */

// WordPress constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Minimal WP_Error stub.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// Stub store for transients and options.
global $wp_test_store;
$wp_test_store = array(
	'options'    => array(),
	'transients' => array(),
);

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		global $wp_test_store;
		return $wp_test_store['transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		global $wp_test_store;
		$wp_test_store['transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		global $wp_test_store;
		unset( $wp_test_store['transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ) {
		return get_transient( $key );
	}
}
if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, $value, int $expiration = 0 ): bool {
		return set_transient( $key, $value, $expiration );
	}
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( string $key ): bool {
		return delete_transient( $key );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		global $wp_test_store;
		return $wp_test_store['options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		global $wp_test_store;
		$wp_test_store['options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		global $wp_test_store;
		unset( $wp_test_store['options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook, $callback = false ) {
		return false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

/**
 * Resets the stub store between tests.
 */
function wp_test_reset_store(): void {
	global $wp_test_store;
	$wp_test_store = array(
		'options'    => array(),
		'transients' => array(),
	);
}

// Load plugin classes.
require_once dirname( __DIR__ ) . '/includes/class-wp-cli-detector.php';
require_once dirname( __DIR__ ) . '/includes/class-wp-cli-command-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-wp-cli-guardrails.php';
require_once dirname( __DIR__ ) . '/includes/class-wp-cli-ability-registrar.php';
