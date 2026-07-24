<?php
/**
 * Operational alert and email tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Admin;
use UCCM\Operational_Alerts;
use UCCM\Settings;

final class OperationalAlertsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']         = array( 'admin_email' => 'admin@example.test' );
		$GLOBALS['uccm_test_mail']            = array();
		$GLOBALS['uccm_test_actions']         = array();
		$GLOBALS['uccm_test_capabilities']    = array();
		$GLOBALS['uccm_test_db_rows']         = array();
		$GLOBALS['uccm_test_current_blog_id'] = 1;
	}

	public function test_dashboard_record_is_bounded_and_email_is_disabled_by_default(): void {
		$record = Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 42 );

		self::assertSame( 'open', $record['status'] );
		self::assertSame( 'uccm_scan_batch_failed', $record['code'] );
		self::assertSame( 42, $record['run_id'] );
		self::assertSame( 'not-requested', $record['email_status'] );
		self::assertCount( 1, Operational_Alerts::current() );
		self::assertSame( array(), $GLOBALS['uccm_test_mail'] );
		self::assertFalse( Settings::current()['error_email_enabled'] );
	}

	public function test_enabled_email_uses_site_administrator_and_suppresses_repeats(): void {
		Settings::update( array( 'error_email_enabled' => true ) );
		$first = Operational_Alerts::report( 'uccm_scan_progress_not_saved', 'scanner', 7 );
		Operational_Alerts::report( 'uccm_scan_progress_not_saved', 'scanner', 7 );

		self::assertSame( 'sent', $first['email_status'] );
		self::assertCount( 1, $GLOBALS['uccm_test_mail'] );
		self::assertSame( 'admin@example.test', $GLOBALS['uccm_test_mail'][0]['to'] );
		self::assertStringContainsString( 'uccm_scan_progress_not_saved', $GLOBALS['uccm_test_mail'][0]['message'] );
		self::assertStringContainsString( 'https://example.test/wp-admin/admin.php?page=uccm-scans&scan_id=7', $GLOBALS['uccm_test_mail'][0]['message'] );

		$records = $GLOBALS['uccm_test_options'][ Operational_Alerts::OPTION_NAME ];
		$id      = array_key_first( $records );
		$records[ $id ]['last_email_at'] = gmdate( 'Y-m-d H:i:s', time() - Operational_Alerts::EMAIL_SUPPRESSION_SECONDS - 1 );
		$GLOBALS['uccm_test_options'][ Operational_Alerts::OPTION_NAME ] = $records;
		Operational_Alerts::report( 'uccm_scan_progress_not_saved', 'scanner', 7 );

		self::assertCount( 2, $GLOBALS['uccm_test_mail'] );
		self::assertSame( 3, Operational_Alerts::current()[0]['occurrences'] );
	}

	public function test_resolution_and_dismissal_clear_current_notice_but_recurrence_reopens_it(): void {
		$record = Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 9 );
		self::assertSame( 1, Operational_Alerts::resolve_component( 'scanner', 9 ) );
		self::assertSame( array(), Operational_Alerts::current() );

		Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 9 );
		self::assertCount( 1, Operational_Alerts::current() );
		self::assertTrue( Operational_Alerts::dismiss( (string) $record['id'] ) );
		self::assertSame( array(), Operational_Alerts::current() );

		Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 9 );
		self::assertCount( 1, Operational_Alerts::current() );
	}

	public function test_records_and_recipients_are_site_scoped(): void {
		Settings::update( array( 'error_email_enabled' => true ) );
		$site_one = Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 3 );

		$GLOBALS['uccm_test_current_blog_id'] = 2;
		$GLOBALS['uccm_test_options']         = array(
			'admin_email'  => 'second@example.test',
			Settings::OPTION_NAME => array( 'error_email_enabled' => true ),
		);
		$site_two = Operational_Alerts::report( 'uccm_scan_batch_failed', 'scanner', 3 );

		self::assertNotSame( $site_one['id'], $site_two['id'] );
		self::assertSame( 'second@example.test', $GLOBALS['uccm_test_mail'][1]['to'] );
	}

	public function test_stalled_scan_detection_and_admin_hooks_are_capability_scoped(): void {
		Operational_Alerts::register();
		Admin::register();
		self::assertArrayHasKey( 'admin_notices', $GLOBALS['uccm_test_actions'] );
		self::assertArrayHasKey( 'admin_post_uccm_dismiss_operational_alert', $GLOBALS['uccm_test_actions'] );

		$GLOBALS['uccm_test_db_rows'] = array( array( 'id' => 17 ) );
		Operational_Alerts::check_stalled_scans();
		self::assertSame( array(), Operational_Alerts::current() );

		$GLOBALS['uccm_test_capabilities']['manage_uccm_settings'] = true;
		Operational_Alerts::check_stalled_scans();
		self::assertSame( 'uccm_scan_stalled', Operational_Alerts::current()[0]['code'] );
		self::assertSame( 17, Operational_Alerts::current()[0]['run_id'] );
	}

	public function test_untrusted_detail_cannot_enter_alert_or_email_content(): void {
		Settings::update( array( 'error_email_enabled' => true ) );
		$record     = Operational_Alerts::report( 'password=secret@example.test', 'unexpected component', 0 );
		$serialized = serialize( $GLOBALS['uccm_test_options'][ Operational_Alerts::OPTION_NAME ] );

		self::assertSame( 'uccm_operational_error', $record['code'] );
		self::assertStringNotContainsString( 'secret@example.test', $serialized );
		self::assertStringNotContainsString( 'password=', $serialized );
		self::assertStringNotContainsString( 'secret@example.test', $GLOBALS['uccm_test_mail'][0]['message'] );
	}
}
