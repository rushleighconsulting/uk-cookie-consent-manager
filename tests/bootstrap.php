<?php
/**
 * Lightweight WordPress test doubles.
 *
 * @package UCCM
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/fixtures/' );
define( 'UCCM_VERSION', '0.1.0-dev' );
define( 'UCCM_PLUGIN_FILE', dirname( __DIR__ ) . '/uk-cookie-consent-manager.php' );
define( 'UCCM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'UCCM_PLUGIN_URL', 'https://example.test/wp-content/plugins/uk-cookie-consent-manager/' );

$GLOBALS['uccm_test_options']          = array();
$GLOBALS['uccm_test_dbdelta_calls']    = array();
$GLOBALS['uccm_test_cleared_hooks']    = array();
$GLOBALS['uccm_test_actions']          = array();
$GLOBALS['uccm_test_enqueued_styles']  = array();
$GLOBALS['uccm_test_enqueued_scripts'] = array();
$GLOBALS['uccm_test_localized']        = array();
$GLOBALS['uccm_test_is_admin']         = false;

/**
 * Minimal wpdb test double.
 */
class wpdb {
	public string $prefix = 'wp_';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}

$GLOBALS['wpdb'] = new wpdb();

/**
 * Minimal administrator role test double.
 */
class UCCM_Test_Role {
	/**
	 * Granted capabilities.
	 *
	 * @var array<string, bool>
	 */
	public array $capabilities = array();

	public function add_cap( string $capability ): void {
		$this->capabilities[ $capability ] = true;
	}

	public function remove_cap( string $capability ): void {
		unset( $this->capabilities[ $capability ] );
	}
}

$GLOBALS['uccm_test_role'] = new UCCM_Test_Role();

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['uccm_test_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['uccm_test_options'][ $name ] = $value;
	return true;
}

function add_option( string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true ): bool {
	unset( $deprecated, $autoload );

	if ( array_key_exists( $name, $GLOBALS['uccm_test_options'] ) ) {
		return false;
	}

	$GLOBALS['uccm_test_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['uccm_test_options'][ $name ] );
	return true;
}

function get_role( string $role ): ?UCCM_Test_Role {
	return 'administrator' === $role ? $GLOBALS['uccm_test_role'] : null;
}

/**
 * Capture dbDelta input.
 *
 * @param string[]|string $queries Schema queries.
 * @return string[]
 */
function dbDelta( array|string $queries ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	$normalized = is_array( $queries ) ? $queries : array( $queries );
	$GLOBALS['uccm_test_dbdelta_calls'][] = $normalized;
	return array();
}

function is_multisite(): bool {
	return false;
}

function wp_clear_scheduled_hook( string $hook ): int {
	$GLOBALS['uccm_test_cleared_hooks'][] = $hook;
	return 1;
}

function get_sites( array $arguments = array() ): array {
	unset( $arguments );
	return array();
}

function switch_to_blog( int $site_id ): bool {
	unset( $site_id );
	return true;
}

function restore_current_blog(): bool {
	return true;
}

function add_action( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['uccm_test_actions'][ $hook ][] = array(
		'callback' => $callback,
		'priority' => $priority,
	);
}

function is_admin(): bool {
	return $GLOBALS['uccm_test_is_admin'];
}

function wp_enqueue_style( string $handle, string $source, array $dependencies = array(), string|bool|null $version = false ): void {
	$GLOBALS['uccm_test_enqueued_styles'][ $handle ] = array(
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
	);
}

function wp_enqueue_script( string $handle, string $source, array $dependencies = array(), string|bool|null $version = false, bool|array $arguments = false ): void {
	$GLOBALS['uccm_test_enqueued_scripts'][ $handle ] = array(
		'source'       => $source,
		'dependencies' => $dependencies,
		'version'      => $version,
		'arguments'    => $arguments,
	);
}

function wp_localize_script( string $handle, string $object_name, array $data ): bool {
	unset( $handle );
	$GLOBALS['uccm_test_localized'][ $object_name ] = $data;
	return true;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	unset( $domain );
	echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr_e( string $text, string $domain = 'default' ): void {
	esc_html_e( $text, $domain );
}

require_once dirname( __DIR__ ) . '/includes/class-database.php';
require_once dirname( __DIR__ ) . '/includes/class-capabilities.php';
require_once dirname( __DIR__ ) . '/includes/class-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-state.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-interface.php';
