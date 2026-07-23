<?php
/**
 * Named threat-class regression tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Consent_Receipts;
use UCCM\Resource_Rules;
use UCCM\Scanner;
use UCCM\Secure_Updater;
use UCCM\Settings;

final class SecurityThreatsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                   = new wpdb();
		$GLOBALS['uccm_test_options']      = array();
		$GLOBALS['uccm_test_transients']   = array();
		$GLOBALS['uccm_test_capabilities'] = array();
		$GLOBALS['uccm_test_is_admin']     = false;
	}

	public function test_csrf_cross_origin_receipt_submission_is_rejected(): void {
		$request = new WP_REST_Request( array( 'origin' => 'https://attacker.example' ) );
		$result  = Consent_Receipts::can_create( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_consent_cross_origin', $result->get_error_code() );
		self::assertTrue( Consent_Receipts::can_create( new WP_REST_Request( array( 'origin' => 'https://example.test' ) ) ) );
	}

	public function test_stored_xss_input_is_sanitised_before_persistence(): void {
		$settings = Settings::sanitize(
			array( 'consent_policy_version' => '<script>alert(1)</script>policy-2' ),
			array()
		);

		self::assertSame( 'scriptalert1scriptpolicy-2', $settings['consent_policy_version'] );
		self::assertStringNotContainsString( '<', $settings['consent_policy_version'] );
	}

	public function test_sql_injection_input_never_changes_prepared_record_query(): void {
		$GLOBALS['uccm_test_capabilities']['view_uccm_consents'] = true;
		Consent_Receipts::records( 'view_uccm_consents', 20, false );

		self::assertStringContainsString( 'LIMIT %d', $GLOBALS['wpdb']->queries[0] );
		self::assertStringNotContainsString( 'LIMIT 20', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_privilege_escalation_cannot_read_consent_records(): void {
		$result = Consent_Receipts::records( 'view_uccm_consents', 20, false );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 403, $result->data['status'] );
	}

	public function test_ssrf_private_network_target_is_rejected(): void {
		$result = Scanner::validate_target( 'http://127.0.0.1/metadata', 'http://127.0.0.1/' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_scan_private_target', $result->get_error_code() );
	}

	public function test_consent_bypass_keeps_mapped_optional_script_inert(): void {
		$GLOBALS['uccm_test_options'][ Resource_Rules::OPTION_NAME ] = array(
			'analytics' => array(
				'type'     => 'script',
				'category' => 'analytics',
				'handle'   => 'analytics-loader',
				'source'   => 'https://analytics.example/tracker.js',
				'title'    => 'Analytics',
			),
		);
		$tag = Resource_Rules::filter_script_tag(
			'<script src="https://analytics.example/tracker.js"></script>',
			'analytics-loader',
			'https://analytics.example/tracker.js'
		);

		self::assertStringContainsString( 'type="text/plain"', $tag );
		self::assertStringContainsString( 'data-uccm-category="analytics"', $tag );
	}

	public function test_package_substitution_checksum_mismatch_is_rejected(): void {
		$file = tempnam( sys_get_temp_dir(), 'uccm-threat-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'substituted package' );

		self::assertFalse( Secure_Updater::verify_file( $file, str_repeat( 'a', 64 ) ) );
		unlink( $file );
	}

	public function test_authenticated_runner_is_rate_limited(): void {
		$payload = array( 'observations' => array() );
		$token   = Scanner::runner_token();

		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			self::assertIsArray( Scanner::accept_browser_observations( $payload, $token ) );
		}

		$result = Scanner::accept_browser_observations( $payload, $token );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_runner_rate_limited', $result->get_error_code() );
	}
}
