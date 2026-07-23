<?php
/**
 * Scan finding comparison and review tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Scan_Findings;

/**
 * Verifies the UCCM-8 human review boundary.
 */
final class ScanFindingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                       = new wpdb();
		$GLOBALS['uccm_test_options']          = array();
		$GLOBALS['uccm_test_capabilities']     = array();
		$GLOBALS['uccm_test_db_rows']          = array();
		$GLOBALS['uccm_test_db_var']           = null;
		$GLOBALS['uccm_test_db_query_result']  = 0;
		$GLOBALS['uccm_test_db_results_queue'] = array();
		$GLOBALS['uccm_test_db_vars_queue']    = array();
		$GLOBALS['uccm_test_mail']             = array();
	}

	public function test_new_observation_creates_one_actionable_finding(): void {
		$GLOBALS['uccm_test_options']['admin_email'] = 'privacy@example.test';
		$GLOBALS['uccm_test_db_results_queue']       = array( array() );
		$GLOBALS['uccm_test_db_vars_queue']          = array( null );

		$counts = Scan_Findings::process( 41, array( self::observation() ) );

		self::assertSame( 1, $counts['actionable'] );
		self::assertSame( 1, $counts['new'] );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		$finding = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( 'new', $finding['finding_type'] );
		self::assertSame( 'pending', $finding['status'] );
		self::assertNull( $finding['inventory_id'] );
	}

	public function test_repeated_identical_observation_does_not_duplicate_pending_finding(): void {
		$GLOBALS['uccm_test_db_results_queue'] = array( array(), array() );
		$GLOBALS['uccm_test_db_vars_queue']    = array( null, 17 );

		$first  = Scan_Findings::process( 41, array( self::observation() ) );
		$second = Scan_Findings::process( 42, array( self::observation() ) );

		self::assertSame( 1, $first['actionable'] );
		self::assertSame( 0, $second['actionable'] );
		self::assertSame( 1, $second['duplicates'] );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
	}

	public function test_duration_domain_source_and_category_candidate_are_visible_in_diff(): void {
		$inventory = array(
			'id'           => 9,
			'storage_key'  => '_example',
			'storage_type' => 'cookie',
			'domain'       => 'old.example.test',
			'duration'     => 'session',
			'source_url'   => 'https://example.test/old.js',
			'category'     => 'functional',
		);
		$observation = self::observation();
		$comparison  = Scan_Findings::compare( $observation, $inventory );

		self::assertIsArray( $comparison );
		self::assertSame( 'changed', $comparison['type'] );
		self::assertSame( 'session', $comparison['before']['duration'] );
		self::assertSame( '30 days', $comparison['after']['duration'] );
		self::assertSame( 'old.example.test', $comparison['before']['domain'] );
		self::assertSame( 'example.test', $comparison['after']['domain'] );
		self::assertSame( 'https://example.test/old.js', $comparison['before']['source_url'] );
		self::assertSame( 'https://example.test/new.js', $comparison['after']['source_url'] );
		self::assertSame( 'functional', $comparison['before']['category'] );
		self::assertSame( 'analytics', $comparison['after']['category'] );
	}

	public function test_unchanged_inventory_observation_creates_no_finding(): void {
		$observation = self::observation();
		$inventory   = array(
			'id'           => 9,
			'storage_key'  => $observation['storage_key'],
			'storage_type' => $observation['storage_type'],
			'domain'       => $observation['domain'],
			'duration'     => $observation['duration'],
			'source_url'   => $observation['source_url'],
			'category'     => $observation['category_candidate'],
		);

		self::assertNull( Scan_Findings::compare( $observation, $inventory ) );
	}

	public function test_review_requires_inventory_capability_and_pending_status(): void {
		$denied = Scan_Findings::review( 4, 'reviewed' );
		self::assertInstanceOf( WP_Error::class, $denied );
		self::assertSame( 'uccm_forbidden', $denied->get_error_code() );

		$GLOBALS['uccm_test_capabilities']['manage_uccm_inventory'] = true;
		$GLOBALS['uccm_test_db_query_result']                       = 1;
		self::assertTrue( Scan_Findings::review( 4, 'ignored' ) );
		self::assertStringContainsString( 'status = %s', $GLOBALS['wpdb']->queries[0] );
		self::assertStringContainsString( 'status = %s', $GLOBALS['wpdb']->queries[0] );
		self::assertStringContainsString( 'pending', $GLOBALS['wpdb']->queries[0] );

		$invalid = Scan_Findings::review( 4, 'published' );
		self::assertInstanceOf( WP_Error::class, $invalid );
		self::assertSame( 'uccm_invalid_finding_review', $invalid->get_error_code() );
	}

	public function test_notification_contains_only_summary_and_scan_link(): void {
		$GLOBALS['uccm_test_options']['admin_email'] = 'privacy@example.test';
		$GLOBALS['uccm_test_db_results_queue']       = array( array() );
		$GLOBALS['uccm_test_db_vars_queue']          = array( null );

		Scan_Findings::process( 73, array( self::observation() ) );

		self::assertCount( 1, $GLOBALS['uccm_test_mail'] );
		$mail = $GLOBALS['uccm_test_mail'][0];
		self::assertSame( array( 'privacy@example.test' ), $mail['to'] );
		self::assertStringContainsString( 'scan_id=73', $mail['message'] );
		self::assertStringNotContainsString( 'consent', strtolower( $mail['message'] ) );
		self::assertStringNotContainsString( 'ip address', strtolower( $mail['message'] ) );
		self::assertStringNotContainsString( '_example', $mail['message'] );
	}

	/**
	 * Return one bounded observation.
	 *
	 * @return array<string, string>
	 */
	private static function observation(): array {
		return array(
			'type'               => 'cookie',
			'storage_key'        => '_example',
			'domain'             => 'example.test',
			'source_url'         => 'https://example.test/new.js',
			'duration'           => '30 days',
			'storage_type'       => 'cookie',
			'method'             => 'browser',
			'category_candidate' => 'analytics',
		);
	}
}
