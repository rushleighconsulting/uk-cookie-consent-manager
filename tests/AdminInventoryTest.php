<?php
/**
 * Administration settings and cookie inventory tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Admin;
use UCCM\Cookie_Inventory;
use UCCM\Settings;

/**
 * Verifies the UCCM-6 administration and inventory contract.
 */
final class AdminInventoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                       = new wpdb();
		$GLOBALS['uccm_test_options']          = array();
		$GLOBALS['uccm_test_capabilities']     = array();
		$GLOBALS['uccm_test_db_rows']          = array();
		$GLOBALS['uccm_test_db_var']           = 0;
		$GLOBALS['uccm_test_admin_menus']      = array();
		$GLOBALS['uccm_test_admin_submenus']   = array();
		$GLOBALS['uccm_test_enqueued_scripts'] = array();
	}

	public function test_nine_capability_separated_admin_screens_are_registered(): void {
		$screens = Admin::screens();

		self::assertCount( 9, $screens );
		self::assertSame( 'manage_uccm_inventory', $screens['uccm-inventory']['capability'] );
		self::assertSame( 'view_uccm_consents', $screens['uccm-consents']['capability'] );
		self::assertSame( 'run_uccm_scans', $screens['uccm-scans']['capability'] );
		self::assertSame( 'View Categories', $screens['uccm-categories']['title'] );

		Admin::register_menu();
		self::assertCount( 1, $GLOBALS['uccm_test_admin_menus'] );
		self::assertCount( 9, $GLOBALS['uccm_test_admin_submenus'] );
	}

	public function test_overview_uses_plain_language_for_actions_and_scan_results(): void {
		$GLOBALS['uccm_test_capabilities']['manage_uccm_settings'] = true;

		ob_start();
		Admin::render_overview();
		$markup = (string) ob_get_clean();

		self::assertStringContainsString( 'Set up your cookie banner, review what your site stores, and check visitors’ choices.', $markup );
		self::assertStringContainsString( 'Scans continue in the background.', $markup );
		self::assertStringContainsString( 'Anything new is listed for you to review before it is added to your cookie list.', $markup );
		self::assertStringNotContainsString( 'observed storage', $markup );
		self::assertStringNotContainsString( 'privacy-preserving evidence', $markup );
		self::assertStringNotContainsString( 'resumable background batches', $markup );
		self::assertStringNotContainsString( 'curated inventory', $markup );
	}

	public function test_settings_are_bounded_and_proxy_addresses_are_validated(): void {
		$settings = Settings::sanitize(
			array(
				'consent_lifetime_days'  => 5000,
				'consent_policy_version' => 'release 2<script>',
				'retention_days'         => 0,
				'store_full_ip'          => 'on',
				'trust_proxy_headers'    => 'yes',
				'trusted_proxy_ips'      => "192.0.2.10\nnot-an-ip\n2001:db8::1\n192.0.2.10",
			),
			array()
		);

		self::assertSame( 730, $settings['consent_lifetime_days'] );
		self::assertSame( 'release2script', $settings['consent_policy_version'] );
		self::assertSame( 1, $settings['retention_days'] );
		self::assertTrue( $settings['store_full_ip'] );
		self::assertTrue( $settings['trust_proxy_headers'] );
		self::assertSame( array( '192.0.2.10', '2001:db8::1' ), $settings['trusted_proxy_ips'] );
	}

	public function test_proxy_allowlist_is_preserved_and_ignored_when_header_trust_is_disabled(): void {
		$current = array(
			'trust_proxy_headers' => true,
			'trusted_proxy_ips'   => array( '192.0.2.10' ),
		);
		$settings = Settings::sanitize(
			array(
				'trust_proxy_headers' => false,
				'trusted_proxy_ips'   => "198.51.100.20\n",
			),
			$current
		);

		self::assertFalse( $settings['trust_proxy_headers'] );
		self::assertSame( array( '192.0.2.10' ), $settings['trusted_proxy_ips'] );
	}

	public function test_privacy_screen_makes_proxy_addresses_unavailable_until_header_trust_is_enabled(): void {
		$GLOBALS['uccm_test_capabilities']['manage_uccm_settings'] = true;
		$GLOBALS['uccm_test_options']['uccm_settings']             = array(
			'trust_proxy_headers' => false,
			'trusted_proxy_ips'   => array( '192.0.2.10' ),
		);

		ob_start();
		Admin::render_privacy();
		$markup = (string) ob_get_clean();

		self::assertArrayHasKey( 'uccm-privacy-settings', $GLOBALS['uccm_test_enqueued_scripts'] );
		self::assertStringContainsString( 'data-uccm-trust-proxy-headers', $markup );
		self::assertStringContainsString( 'aria-expanded="false"', $markup );
		self::assertStringContainsString( 'data-uccm-trusted-proxies-settings hidden', $markup );
		self::assertStringContainsString( 'disabled aria-disabled="true">192.0.2.10</textarea>', $markup );

		$GLOBALS['uccm_test_options']['uccm_settings']['trust_proxy_headers'] = true;

		ob_start();
		Admin::render_privacy();
		$enabled_markup = (string) ob_get_clean();

		self::assertStringContainsString( 'aria-expanded="true"', $enabled_markup );
		self::assertStringNotContainsString( 'data-uccm-trusted-proxies-settings hidden', $enabled_markup );
		self::assertStringNotContainsString( 'disabled aria-disabled="true">192.0.2.10</textarea>', $enabled_markup );
	}

	public function test_scan_url_settings_reject_cross_origin_entries_before_save(): void {
		$valid = Settings::validate_scan_urls( "https://example.test/privacy\nhttps://example.test/contact" );

		self::assertSame( array( 'https://example.test/privacy', 'https://example.test/contact' ), $valid );

		$invalid = Settings::validate_scan_urls( "https://example.test/privacy\nhttps://tracker.test/pixel" );

		self::assertInstanceOf( WP_Error::class, $invalid );
		self::assertSame( 'uccm_scan_disallowed_target', $invalid->get_error_code() );
		self::assertSame( 'https://tracker.test/pixel', $invalid->get_error_data()['url'] );
	}


	public function test_crawler_settings_bound_limits_and_sanitise_exclusions(): void {
		$settings = Settings::sanitize(
			array(
				'scan_page_limit'     => 5000,
				'scan_batch_size'     => 0,
				'scan_excluded_paths' => "/private/*\ninvalid\n*/checkout/\n/private/*",
			),
			array()
		);

		self::assertSame( 1024, $settings['scan_page_limit'] );
		self::assertSame( 1, $settings['scan_batch_size'] );
		self::assertSame( array( '/private/*', '*/checkout/' ), $settings['scan_excluded_paths'] );
	}

	public function test_inventory_rejects_invalid_category_and_storage_type(): void {
		$input = self::valid_item();
		$input['category'] = 'advertising';
		$result = Cookie_Inventory::validate( $input );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_inventory_invalid_category', $result->get_error_code() );

		$input = self::valid_item();
		$input['storage_type'] = 'database';
		$result = Cookie_Inventory::validate( $input );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_inventory_invalid_storage_type', $result->get_error_code() );
	}

	public function test_inventory_writes_require_capability_and_store_sanitised_values(): void {
		$denied = Cookie_Inventory::save( self::valid_item() );
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 'uccm_forbidden', $denied->get_error_code() );
		self::assertCount( 0, $GLOBALS['wpdb']->inserts );

		$GLOBALS['uccm_test_capabilities']['manage_uccm_inventory'] = true;
		$input = self::valid_item();
		$input['provider'] = '<b>Example Analytics</b>';
		$input['source_url'] = 'javascript:alert(1)';
		$id = Cookie_Inventory::save( $input );

		self::assertSame( 1, $id );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		$row = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( 'Example Analytics', $row['provider'] );
		self::assertSame( '', $row['source_url'] );
		self::assertSame( 64, strlen( $row['fingerprint'] ) );
		self::assertSame( 'known', $row['status'] );
	}

	public function test_inventory_queries_preserve_filters_and_bound_pagination(): void {
		$GLOBALS['uccm_test_capabilities']['manage_uccm_inventory'] = true;
		$GLOBALS['uccm_test_db_var'] = 201;

		$result = Cookie_Inventory::records(
			array(
				'category' => 'analytics',
				'status'   => 'known',
				'search'   => 'example',
			),
			3,
			500
		);

		self::assertIsArray( $result );
		self::assertSame( 100, $result['per_page'] );
		self::assertSame( 3, $result['page'] );
		self::assertSame( 3, $result['pages'] );
		self::assertCount( 2, $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( 'category = %s', $GLOBALS['wpdb']->queries[0] );
		self::assertStringContainsString( 'LIMIT %d OFFSET %d', $GLOBALS['wpdb']->queries[1] );
		self::assertStringContainsString( ',100,200]', $GLOBALS['wpdb']->queries[1] );
	}

	public function test_csv_export_neutralises_spreadsheet_formulas(): void {
		$csv = Cookie_Inventory::csv(
			array(
				array(
					'storage_key'     => '=SUM(1,1)',
					'provider'        => '+cmd',
					'domain'          => 'example.test',
					'party'           => 'first-party',
					'storage_type'    => 'cookie',
					'purpose'         => 'Testing',
					'category'        => 'necessary',
					'duration'        => 'session',
					'source_url'      => 'https://example.test',
					'first_seen_at'   => '2026-07-23 12:00:00',
					'last_seen_at'    => '2026-07-23 12:00:00',
					'last_reviewed_at' => '2026-07-23 12:00:00',
					'status'          => 'known',
				),
			)
		);

		self::assertStringContainsString( "'=SUM(1,1)", $csv );
		self::assertStringContainsString( "'+cmd", $csv );
		self::assertStringContainsString( 'Name,Provider,Domain', $csv );
	}

	public function test_blocking_rule_json_is_strictly_allowlisted(): void {
		$invalid = Admin::sanitize_blocking_rules( '{"tracker":{"type":"script","category":"unknown","source":"https://example.test/a.js"}}' );
		self::assertInstanceOf( WP_Error::class, $invalid );

		$valid = Admin::sanitize_blocking_rules( '{"tracker":{"type":"script","category":"analytics","source":"https://example.test/a.js","title":"Analytics"}}' );
		self::assertIsArray( $valid );
		self::assertSame( 'analytics', $valid['tracker']['category'] );
		self::assertSame( 'https://example.test/a.js', $valid['tracker']['source'] );
	}


	public function test_empty_and_guided_blocking_rules_use_the_object_contract(): void {
		$empty = Admin::sanitize_blocking_rules( '{}' );
		self::assertSame( array(), $empty );

		$array = Admin::sanitize_blocking_rules( '[]' );
		self::assertInstanceOf( WP_Error::class, $array );
		self::assertSame( 'uccm_invalid_rules_json', $array->get_error_code() );

		$guided = Admin::sanitize_blocking_rule_rows(
			array(
				array(
					'id'       => 'analytics-test',
					'type'     => 'script',
					'category' => 'analytics',
					'handle'   => 'analytics-test',
					'source'   => '',
					'title'    => 'Analytics test script',
				),
			)
		);

		self::assertIsArray( $guided );
		self::assertSame( 'analytics-test', $guided['analytics-test']['handle'] );
		self::assertSame( 'Analytics test script', $guided['analytics-test']['title'] );
	}

	public function test_guided_blocking_rules_return_field_specific_errors(): void {
		$insecure = Admin::sanitize_blocking_rule_rows(
			array(
				array(
					'id'       => 'map',
					'type'     => 'iframe',
					'category' => 'functional',
					'source'   => 'http://maps.example.test/embed',
				),
			)
		);

		self::assertInstanceOf( WP_Error::class, $insecure );
		self::assertSame( 'uccm_insecure_blocking_source', $insecure->get_error_code() );
		self::assertSame( 'source', $insecure->get_error_data()['field'] );
		self::assertStringContainsString( 'Rule 1', $insecure->get_error_message() );

		$duplicate = Admin::sanitize_blocking_rule_rows(
			array(
				array(
					'id'       => 'analytics-test',
					'type'     => 'script',
					'category' => 'analytics',
					'handle'   => 'analytics-one',
				),
				array(
					'id'       => 'analytics-test',
					'type'     => 'script',
					'category' => 'analytics',
					'handle'   => 'analytics-two',
				),
			)
		);

		self::assertInstanceOf( WP_Error::class, $duplicate );
		self::assertSame( 'uccm_duplicate_blocking_rule_id', $duplicate->get_error_code() );
		self::assertSame( 'id', $duplicate->get_error_data()['field'] );
	}

	public function test_blocking_editor_assets_cover_guided_add_remove_and_validation(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-blocking.js' );

		self::assertIsString( $script );
		self::assertStringContainsString( '[data-uccm-add-rule]', $script );
		self::assertStringContainsString( '[data-uccm-remove-rule]', $script );
		self::assertStringContainsString( 'setCustomValidity', $script );
		self::assertStringContainsString( "JSON.stringify( rulesObject(), null, 2 )", $script );
	}

	public function test_privacy_script_preserves_and_controls_the_proxy_allowlist_field(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-privacy.js' );

		self::assertIsString( $script );
		self::assertStringContainsString( 'proxySettings.hidden = ! enabled', $script );
		self::assertStringContainsString( 'proxyAddresses.disabled = ! enabled', $script );
		self::assertStringContainsString( "trustHeaders.setAttribute( 'aria-expanded'", $script );
	}

	/**
	 * Return a complete valid inventory item.
	 *
	 * @return array<string, string>
	 */
	private static function valid_item(): array {
		return array(
			'storage_key'  => '_example',
			'domain'       => 'example.test',
			'provider'     => 'Example',
			'party'        => 'first-party',
			'storage_type' => 'cookie',
			'purpose'      => 'Remember an essential choice.',
			'category'     => 'necessary',
			'duration'     => 'session',
			'source_url'   => 'https://example.test/',
			'status'       => 'known',
		);
	}
}
