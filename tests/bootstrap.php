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
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['uccm_test_options']          = array();
$GLOBALS['uccm_test_dbdelta_calls']    = array();
$GLOBALS['uccm_test_cleared_hooks']    = array();
$GLOBALS['uccm_test_actions']          = array();
$GLOBALS['uccm_test_filters']          = array();
$GLOBALS['uccm_test_fired_actions']    = array();
$GLOBALS['uccm_test_enqueued_styles']  = array();
$GLOBALS['uccm_test_enqueued_scripts'] = array();
$GLOBALS['uccm_test_localized']        = array();
$GLOBALS['uccm_test_is_admin']         = false;
$GLOBALS['uccm_test_scheduled_hooks']  = array();
$GLOBALS['uccm_test_capabilities']     = array();
$GLOBALS['uccm_test_rest_routes']      = array();
$GLOBALS['uccm_test_db_rows']          = array();
$GLOBALS['uccm_test_db_var']           = null;
$GLOBALS['uccm_test_db_query_result']  = 0;

/**
 * Minimal wpdb test double.
 */
class wpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;

	/** @var array<int, array{table: string, data: array<string, mixed>, formats: array<int, string>}> */
	public array $inserts = array();

	/** @var string[] */
	public array $queries = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param string[]             $formats
	 */
	public function insert( string $table, array $data, array $formats = array() ): int|false {
		$this->inserts[] = compact( 'table', 'data', 'formats' );
		++$this->insert_id;
		return 1;
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		return $query . ' -- ' . wp_json_encode( $arguments );
	}

	/** @return array<int, array<string, mixed>> */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		unset( $output );
		$this->queries[] = $query;
		return $GLOBALS['uccm_test_db_rows'];
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		return $GLOBALS['uccm_test_db_var'];
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		return $GLOBALS['uccm_test_db_query_result'];
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

/**
 * Minimal WordPress error test double.
 */
class WP_Error {
	public function __construct(
		public string $code = '',
		public string $message = '',
		public mixed $data = null
	) {
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}


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

function wp_next_scheduled( string $hook ): int|false {
	return $GLOBALS['uccm_test_scheduled_hooks'][ $hook ] ?? false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	unset( $recurrence );
	$GLOBALS['uccm_test_scheduled_hooks'][ $hook ] = $timestamp;
	return true;
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

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_arguments = 1 ): void {
	$GLOBALS['uccm_test_filters'][ $hook ][] = array(
		'callback'           => $callback,
		'priority'           => $priority,
		'accepted_arguments' => $accepted_arguments,
	);
}

/**
 * Apply registered test filters.
 *
 * @param mixed ...$arguments Additional filter arguments.
 */
function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
	foreach ( $GLOBALS['uccm_test_filters'][ $hook ] ?? array() as $filter ) {
		$call_arguments = array_slice(
			array_merge( array( $value ), $arguments ),
			0,
			(int) $filter['accepted_arguments']
		);
		$value          = call_user_func_array( $filter['callback'], $call_arguments );
	}

	return $value;
}

/**
 * Fire and capture test actions.
 *
 * @param mixed ...$arguments Action arguments.
 */
function do_action( string $hook, mixed ...$arguments ): void {
	$GLOBALS['uccm_test_fired_actions'][ $hook ][] = $arguments;

	foreach ( $GLOBALS['uccm_test_actions'][ $hook ] ?? array() as $action ) {
		call_user_func_array( $action['callback'], $arguments );
	}
}

function is_admin(): bool {
	return $GLOBALS['uccm_test_is_admin'];
}

function current_user_can( string $capability ): bool {
	return true === ( $GLOBALS['uccm_test_capabilities'][ $capability ] ?? false );
}

function get_current_user_id(): int {
	return 0;
}

function get_current_blog_id(): int {
	return 1;
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-site-secret-' . $scheme;
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function register_rest_route( string $namespace, string $route, array $arguments ): bool {
	$GLOBALS['uccm_test_rest_routes'][ $namespace . $route ] = $arguments;
	return true;
}

function plugin_dir_url( string $file ): string {
	unset( $file );
	return UCCM_PLUGIN_URL;
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

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( string $url ): string {
	return preg_match( '#^https?://#i', $url ) ? $url : '';
}

function esc_url( string $url ): string {
	return esc_attr( esc_url_raw( $url ) );
}

function sanitize_text_field( string $text ): string {
	return trim( strip_tags( $text ) );
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
require_once dirname( __DIR__ ) . '/includes/class-ip-privacy.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-receipts.php';
require_once dirname( __DIR__ ) . '/includes/class-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-state.php';
require_once dirname( __DIR__ ) . '/includes/class-resource-rules.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-interface.php';
