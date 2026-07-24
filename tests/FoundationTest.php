<?php
/**
 * Foundation lifecycle tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Activator;
use UCCM\Capabilities;
use UCCM\Database;

/**
 * Verifies the UCCM-2 foundation.
 */
final class FoundationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']       = array();
		$GLOBALS['uccm_test_dbdelta_calls'] = array();
		$GLOBALS['uccm_test_cleared_hooks'] = array();
		$GLOBALS['uccm_test_role']          = new UCCM_Test_Role();
		$GLOBALS['uccm_test_scheduled_hooks'] = array();
	}

	public function test_release_version_metadata_agrees(): void {
		$plugin = file_get_contents( dirname( __DIR__ ) . '/uk-cookie-consent-manager.php' );
		$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		self::assertIsString( $plugin );
		self::assertIsString( $readme );
		self::assertSame( 1, preg_match( '/^ \* Version:\\s*(\\S+)$/m', $plugin, $header ) );
		self::assertSame( 1, preg_match( "/^define\\( 'UCCM_VERSION', '([^']+)' \\);$/m", $plugin, $constant ) );
		self::assertSame( 1, preg_match( '/^Stable tag:\\s*(\\S+)$/m', $readme, $stable ) );
		self::assertSame( '0.1.0-rc.10', $header[1] );
		self::assertSame( $header[1], $constant[1] );
		self::assertSame( $header[1], $stable[1] );
	}

	public function test_schema_defines_four_prefixed_tables(): void {
		$schema = Database::schema( $GLOBALS['wpdb'] );

		self::assertCount( 4, $schema );
		self::assertStringContainsString( 'CREATE TABLE wp_uccm_consents', $schema[0] );
		self::assertStringContainsString( 'CREATE TABLE wp_uccm_cookie_inventory', $schema[1] );
		self::assertStringContainsString( 'CREATE TABLE wp_uccm_scan_runs', $schema[2] );
		self::assertStringContainsString( 'CREATE TABLE wp_uccm_scan_findings', $schema[3] );

		foreach ( $schema as $statement ) {
			self::assertStringContainsString( 'PRIMARY KEY  (id)', $statement );
			self::assertStringNotContainsString( 'FOREIGN KEY', $statement );
		}
	}

	public function test_repeated_activation_is_deterministic(): void {
		Activator::activate();
		$first_options      = $GLOBALS['uccm_test_options'];
		$first_capabilities = $GLOBALS['uccm_test_role']->capabilities;

		Activator::activate();

		self::assertSame( $first_options, $GLOBALS['uccm_test_options'] );
		self::assertSame( $first_capabilities, $GLOBALS['uccm_test_role']->capabilities );
		self::assertCount( 2, $GLOBALS['uccm_test_dbdelta_calls'] );
		self::assertSame(
			$GLOBALS['uccm_test_dbdelta_calls'][0],
			$GLOBALS['uccm_test_dbdelta_calls'][1]
		);
	}

	public function test_activation_uses_privacy_preserving_defaults(): void {
		Activator::activate();

		self::assertSame( Database::SCHEMA_VERSION, get_option( 'uccm_schema_version' ) );
		self::assertSame( UCCM_VERSION, get_option( 'uccm_version' ) );
		self::assertFalse( get_option( 'uccm_delete_data_on_uninstall' ) );

		$settings = get_option( 'uccm_settings' );
		self::assertIsArray( $settings );
		self::assertFalse( $settings['store_full_ip'] );
		self::assertFalse( $settings['trust_proxy_headers'] );
		self::assertSame( array(), $settings['trusted_proxy_ips'] );
		self::assertSame( 365, $settings['retention_days'] );
		self::assertArrayHasKey( 'uccm_retention_cleanup', $GLOBALS['uccm_test_scheduled_hooks'] );
	}

	public function test_administrator_receives_dedicated_capabilities(): void {
		Activator::activate();

		self::assertSame(
			array_fill_keys( Capabilities::all(), true ),
			$GLOBALS['uccm_test_role']->capabilities
		);
		self::assertNotContains( 'manage_options', Capabilities::all() );
	}

	public function test_deactivation_only_clears_scheduled_work(): void {
		Activator::activate();
		$options_before = $GLOBALS['uccm_test_options'];

		Activator::deactivate();

		self::assertSame(
			array( 'uccm_monthly_scan', 'uccm_scan_batch', 'uccm_retention_cleanup' ),
			$GLOBALS['uccm_test_cleared_hooks']
		);
		self::assertSame( $options_before, $GLOBALS['uccm_test_options'] );
	}
}
