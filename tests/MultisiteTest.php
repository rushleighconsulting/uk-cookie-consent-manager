<?php
/**
 * Multisite lifecycle, isolation and precedence tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Activator;
use UCCM\Multisite;
use UCCM\Settings;

/**
 * Verifies bounded network operation without cross-site state leakage.
 */
final class MultisiteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                              = new wpdb();
		$GLOBALS['uccm_test_is_multisite']            = true;
		$GLOBALS['uccm_test_is_main_site']            = true;
		$GLOBALS['uccm_test_current_blog_id']         = 1;
		$GLOBALS['uccm_test_options']                 = array();
		$GLOBALS['uccm_test_site_options']            = array();
		$GLOBALS['uccm_test_site_options_by_blog']    = array();
		$GLOBALS['uccm_test_blog_stack']              = array();
		$GLOBALS['uccm_test_switched_blogs']          = array();
		$GLOBALS['uccm_test_sites']                   = array();
		$GLOBALS['uccm_test_dbdelta_calls']           = array();
		$GLOBALS['uccm_test_scheduled_hooks']         = array();
		$GLOBALS['uccm_test_schedule_events']         = array();
		$GLOBALS['uccm_test_cleared_hooks']           = array();
		$GLOBALS['uccm_test_site_transients']         = array();
		$GLOBALS['uccm_test_role']                    = new UCCM_Test_Role();
		$GLOBALS['uccm_test_capabilities']            = array();
		$GLOBALS['uccm_test_actions']                 = array();
		$GLOBALS['uccm_test_admin_menus']             = array();
	}

	protected function tearDown(): void {
		$GLOBALS['uccm_test_is_multisite'] = false;
		$GLOBALS['wpdb']->prefix           = 'wp_';
	}

	public function test_network_activation_uses_resumable_bounded_batches(): void {
		$GLOBALS['uccm_test_sites'] = range( 1, 30 );

		Activator::activate( true );

		$state = Multisite::install_state();
		self::assertSame( 'running', $state['status'] );
		self::assertSame( 25, $state['processed'] );
		self::assertSame( range( 1, 25 ), $GLOBALS['uccm_test_switched_blogs'] );
		self::assertSame( 1, $GLOBALS['uccm_test_current_blog_id'] );
		self::assertCount( 25, $GLOBALS['uccm_test_dbdelta_calls'] );
		self::assertContains(
			Multisite::BATCH_HOOK,
			array_column( $GLOBALS['uccm_test_schedule_events'], 'hook' )
		);

		Multisite::process_install_batch();

		$state = Multisite::install_state();
		self::assertSame( 'completed', $state['status'] );
		self::assertSame( 30, $state['processed'] );
		self::assertSame( UCCM_VERSION, get_site_option( Multisite::VERSION_OPTION ) );
		self::assertSame( 1, $GLOBALS['uccm_test_current_blog_id'] );
		self::assertStringContainsString( 'CREATE TABLE wp_30_uccm_consents', $GLOBALS['uccm_test_dbdelta_calls'][29][0] );
	}

	public function test_existing_site_values_become_explicit_overrides_and_locks_win(): void {
		$GLOBALS['uccm_test_sites']                    = array( 2 );
		$GLOBALS['uccm_test_site_options_by_blog'][2] = array(
			Settings::OPTION_NAME => array(
				'consent_lifetime_days'  => 400,
				'retention_days'         => 777,
				'consent_policy_version' => 'site-policy',
			),
		);

		Activator::activate( true );
		$GLOBALS['uccm_test_site_options']['active_sitewide_plugins'] = array(
			plugin_basename( UCCM_PLUGIN_FILE ) => time(),
		);
		update_site_option(
			Multisite::SETTINGS_OPTION,
			array(
				'defaults' => array(
					'consent_lifetime_days' => 180,
					'retention_days'        => 365,
				),
				'locked'   => array( 'retention_days' ),
			)
		);

		switch_to_blog( 2 );
		$settings = Settings::current();

		self::assertSame( 400, $settings['consent_lifetime_days'] );
		self::assertSame( 365, $settings['retention_days'] );
		self::assertSame( 'site-policy', $settings['consent_policy_version'] );
		self::assertContains( 'consent_lifetime_days', Settings::site_overrides() );

		Settings::update(
			array(
				'consent_lifetime_days' => 200,
				'retention_days'        => 900,
			),
			array( 'consent_lifetime_days' )
		);
		$settings = Settings::current();

		self::assertSame( 180, $settings['consent_lifetime_days'] );
		self::assertSame( 365, $settings['retention_days'] );
		self::assertTrue( Settings::is_network_inherited( 'consent_lifetime_days' ) );
		self::assertTrue( Settings::is_network_locked( 'retention_days' ) );
		restore_current_blog();
	}

	public function test_new_site_is_initialized_in_its_own_context(): void {
		$GLOBALS['uccm_test_site_options']['active_sitewide_plugins'] = array(
			plugin_basename( UCCM_PLUGIN_FILE ) => time(),
		);

		Multisite::initialize_site( new WP_Site( 7 ) );

		self::assertSame( array( 7 ), $GLOBALS['uccm_test_switched_blogs'] );
		self::assertSame( 1, $GLOBALS['uccm_test_current_blog_id'] );
		self::assertStringContainsString( 'CREATE TABLE wp_7_uccm_consents', $GLOBALS['uccm_test_dbdelta_calls'][0][0] );
		self::assertSame( UCCM_VERSION, $GLOBALS['uccm_test_site_options_by_blog'][7]['uccm_version'] );
		self::assertSame( array(), $GLOBALS['uccm_test_site_options_by_blog'][7][ Settings::OVERRIDES_OPTION ] );
		self::assertArrayNotHasKey( 'consent_lifetime_days', $GLOBALS['uccm_test_site_options_by_blog'][7][ Settings::OPTION_NAME ] );
	}

	public function test_network_configuration_rejects_site_specific_legal_and_privacy_values(): void {
		update_site_option(
			Multisite::SETTINGS_OPTION,
			array(
				'defaults' => array(
					'consent_lifetime_days'  => 365,
					'consent_policy_version' => 'network-policy',
					'store_full_ip'          => true,
					'scan_urls'              => array( 'https://other.example/' ),
				),
				'locked'   => array( 'consent_policy_version', 'store_full_ip', 'consent_lifetime_days' ),
			)
		);

		$configuration = Multisite::configuration();

		self::assertSame( array( 'consent_lifetime_days' => 365 ), $configuration['defaults'] );
		self::assertSame( array( 'consent_lifetime_days' ), $configuration['locked'] );
	}

	public function test_network_deactivation_clears_site_jobs_in_bounded_pages(): void {
		$GLOBALS['uccm_test_sites'] = range( 1, 28 );

		Activator::deactivate( true );

		self::assertSame( range( 1, 28 ), $GLOBALS['uccm_test_switched_blogs'] );
		self::assertCount( 1 + ( 28 * 4 ), $GLOBALS['uccm_test_cleared_hooks'] );
		self::assertSame( Multisite::BATCH_HOOK, $GLOBALS['uccm_test_cleared_hooks'][0] );
		self::assertSame( 1, $GLOBALS['uccm_test_current_blog_id'] );
	}

	public function test_network_hooks_and_menu_use_network_administrator_boundary(): void {
		$GLOBALS['uccm_test_site_options']['active_sitewide_plugins'] = array(
			plugin_basename( UCCM_PLUGIN_FILE ) => time(),
		);

		Multisite::register();
		do_action( 'network_admin_menu' );

		self::assertArrayHasKey( 'wp_initialize_site', $GLOBALS['uccm_test_actions'] );
		self::assertArrayHasKey( Multisite::BATCH_HOOK, $GLOBALS['uccm_test_actions'] );
		self::assertSame( 'manage_network_options', $GLOBALS['uccm_test_admin_menus'][0]['capability'] );
		self::assertSame( 'uccm-network', $GLOBALS['uccm_test_admin_menus'][0]['menu_slug'] );
	}
}
