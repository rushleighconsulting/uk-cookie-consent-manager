<?php
/**
 * WordPress privacy integration tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Privacy;

final class PrivacyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                       = new wpdb();
		$GLOBALS['uccm_test_users_by_email']  = array();
		$GLOBALS['uccm_test_db_rows']         = array();
		$GLOBALS['uccm_test_privacy_content'] = array();
	}

	public function test_registers_wordpress_exporter_and_eraser(): void {
		$exporters = Privacy::register_exporter( array() );
		$erasers   = Privacy::register_eraser( array() );

		self::assertSame( array( Privacy::class, 'export_personal_data' ), $exporters['uccm-consent-receipts']['callback'] );
		self::assertSame( array( Privacy::class, 'erase_personal_data' ), $erasers['uccm-consent-receipts']['callback'] );
	}

	public function test_exports_only_receipts_linked_to_the_requested_account(): void {
		$GLOBALS['uccm_test_users_by_email']['person@example.test'] = new WP_User( 42 );
		$GLOBALS['uccm_test_db_rows'] = array(
			array(
				'id'              => 7,
				'receipt_id'      => 'receipt-123456789',
				'occurred_at'     => '2026-07-23 12:00:00',
				'action'          => 'grant',
				'choices'         => '{"necessary":true}',
				'policy_version'  => '1',
				'plugin_version'  => '0.1.0-dev',
				'site_identifier' => str_repeat( 'a', 64 ),
				'ip_masked'       => '192.0.2.0',
			),
		);

		$result = Privacy::export_personal_data( 'person@example.test', 1 );

		self::assertCount( 1, $result['data'] );
		self::assertSame( 'uccm-consent-7', $result['data'][0]['item_id'] );
		self::assertTrue( $result['done'] );
		self::assertStringContainsString( 'WHERE wp_user_id = %d', $GLOBALS['wpdb']->queries[0] );
		self::assertStringNotContainsString( 'person@example.test', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_anonymises_account_and_ip_identifiers_but_retains_decision_evidence(): void {
		$GLOBALS['uccm_test_users_by_email']['person@example.test'] = new WP_User( 42 );
		$GLOBALS['uccm_test_db_rows'] = array( array( 'id' => 7 ) );

		$result = Privacy::erase_personal_data( 'person@example.test', 1 );

		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertCount( 1, $GLOBALS['wpdb']->updates );
		$anonymised = $GLOBALS['wpdb']->updates[0]['data'];
		self::assertNull( $anonymised['wp_user_id'] );
		self::assertSame( '', $anonymised['ip_masked'] );
		self::assertSame( '', $anonymised['ip_fingerprint'] );
		self::assertNull( $anonymised['ip_ciphertext'] );
		self::assertSame( 64, strlen( $anonymised['integrity_hash'] ) );
	}

	public function test_anonymous_receipts_cannot_be_attributed_by_email(): void {
		$result = Privacy::export_personal_data( 'unknown@example.test', 1 );

		self::assertSame( array(), $result['data'] );
		self::assertTrue( $result['done'] );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_adds_complete_suggested_privacy_policy_text(): void {
		Privacy::add_policy_content();

		$text = $GLOBALS['uccm_test_privacy_content']['Rushleigh Cookie Choices'];
		self::assertStringContainsString( 'masked IP address', $text );
		self::assertStringContainsString( '365 days', $text );
		self::assertStringContainsString( 'not sent to Rushleigh Consulting', $text );
	}
}
