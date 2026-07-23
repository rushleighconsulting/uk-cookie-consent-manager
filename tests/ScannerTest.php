<?php
/**
 * Hybrid scanner tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Activator;
use UCCM\Scanner;
use UCCM\Settings;

final class ScannerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                         = new wpdb();
		$GLOBALS['uccm_test_options']            = array();
		$GLOBALS['uccm_test_scheduled_hooks']    = array();
		$GLOBALS['uccm_test_schedule_events']    = array();
		$GLOBALS['uccm_test_cleared_hooks']      = array();
		$GLOBALS['uccm_test_capabilities']       = array();
		$GLOBALS['uccm_test_transients']         = array();
		$GLOBALS['uccm_test_http_validity']      = true;
		$GLOBALS['uccm_test_remote_responses']   = array();
	}

	public function test_monthly_schedule_is_idempotent_and_deactivation_clears_it(): void {
		Scanner::schedule();
		Scanner::schedule();

		self::assertCount( 1, $GLOBALS['uccm_test_schedule_events'] );
		self::assertSame( Scanner::RECURRENCE, $GLOBALS['uccm_test_schedule_events'][0]['recurrence'] );

		Activator::deactivate();

		self::assertContains( Scanner::HOOK, $GLOBALS['uccm_test_cleared_hooks'] );
	}

	public function test_monthly_recurrence_is_declared(): void {
		$schedules = Scanner::cron_schedules( array() );

		self::assertSame( 30 * DAY_IN_SECONDS, $schedules[ Scanner::RECURRENCE ]['interval'] );
		self::assertSame( 'Every 30 days', $schedules[ Scanner::RECURRENCE ]['display'] );
	}

	public function test_target_validation_rejects_cross_origin_and_private_networks(): void {
		self::assertSame(
			'https://example.test/privacy',
			Scanner::validate_target( 'https://example.test/privacy', 'https://example.test/' )
		);
		self::assertSame(
			'uccm_scan_disallowed_target',
			Scanner::validate_target( 'https://tracker.test/', 'https://example.test/' )->get_error_code()
		);
		self::assertSame(
			'uccm_scan_private_target',
			Scanner::validate_target( 'http://192.168.1.10/', 'http://192.168.1.10/' )->get_error_code()
		);
		self::assertSame(
			'uccm_scan_disallowed_target',
			Scanner::validate_target( 'https://user:secret@example.test/', 'https://example.test/' )->get_error_code()
		);
	}

	public function test_browser_observations_require_authentication_and_are_allowlisted(): void {
		$payload = array(
			'observations' => array(
				array(
					'type'        => 'local_storage',
					'storage_key' => 'preferences',
					'domain'      => 'example.test',
					'source_url'  => 'https://example.test/app.js',
				),
				array(
					'type'        => 'form_value',
					'storage_key' => 'email',
				),
			),
		);

		$denied = Scanner::accept_browser_observations( $payload, 'wrong-token' );
		$valid  = Scanner::accept_browser_observations( $payload, Scanner::runner_token() );

		self::assertSame( 'uccm_runner_unauthorised', $denied->get_error_code() );
		self::assertCount( 1, $valid );
		self::assertSame( 'local_storage', $valid[0]['storage_type'] );
	}

	public function test_manual_scan_creates_bounded_run_and_hybrid_findings(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$fetcher = static fn (): array => array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'set-cookie' => 'server_id=abc; Path=/; HttpOnly; Max-Age=3600',
			),
		);
		$payload = array(
			'token'        => Scanner::runner_token(),
			'observations' => array(
				array(
					'type'        => 'local_storage',
					'storage_key' => 'preferences',
					'domain'      => 'example.test',
					'source_url'  => 'https://example.test/app.js',
				),
				array(
					'type'        => 'script',
					'storage_key' => 'analytics-loader',
					'domain'      => 'analytics.example',
					'source_url'  => 'https://analytics.example/tracker.js',
				),
			),
		);

		$run_id = Scanner::run( true, array( 'https://example.test/' ), $fetcher, $payload );

		self::assertSame( 1, $run_id );
		self::assertCount( 4, $GLOBALS['wpdb']->inserts );
		self::assertSame( 'wp_uccm_scan_runs', $GLOBALS['wpdb']->inserts[0]['table'] );
		self::assertSame( 'wp_uccm_scan_findings', $GLOBALS['wpdb']->inserts[1]['table'] );
		self::assertCount( 1, $GLOBALS['wpdb']->updates );

		$summary = json_decode( (string) $GLOBALS['wpdb']->updates[0]['data']['summary'], true );
		$pages   = json_decode( (string) $GLOBALS['wpdb']->updates[0]['data']['pages_visited'], true );

		self::assertSame( 3, $summary['findings'] );
		self::assertCount( 3, $summary['limitations'] );
		self::assertSame( 'https://example.test/', $pages[0]['url'] );
		self::assertSame( 200, $pages[0]['status'] );
		self::assertArrayNotHasKey( 'consents', $payload );
	}

	public function test_invalid_target_is_rejected_before_a_scan_run_is_created(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;

		$result = Scanner::run( true, array( 'http://127.0.0.1/' ) );

		self::assertSame( 'uccm_scan_disallowed_target', $result->get_error_code() );
		self::assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_configured_targets_are_sanitised_to_the_temporary_ceiling(): void {
		$urls = array();

		self::assertSame( 1024, Scanner::MAX_TARGETS );

		for ( $index = 0; $index < Scanner::MAX_TARGETS + 10; ++$index ) {
			$urls[] = 'https://example.test/page-' . $index;
		}

		$settings = Settings::sanitize( array( 'scan_urls' => implode( "\n", $urls ) ), array() );

		self::assertCount( Scanner::MAX_TARGETS - 1, $settings['scan_urls'] );
		self::assertSame( 'https://example.test/page-0', $settings['scan_urls'][0] );
	}
}
