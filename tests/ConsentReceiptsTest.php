<?php
/**
 * Consent receipt and IP privacy tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Consent_Receipts;
use UCCM\IP_Privacy;

/**
 * Verifies the UCCM-5 privacy and evidence contract.
 */
final class ConsentReceiptsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                       = new wpdb();
		$GLOBALS['uccm_test_options']          = array(
			'uccm_settings' => array(
				'retention_days'      => 365,
				'store_full_ip'       => false,
				'trust_proxy_headers' => false,
				'trusted_proxy_ips'   => array(),
			),
		);
		$GLOBALS['uccm_test_actions']          = array();
		$GLOBALS['uccm_test_fired_actions']    = array();
		$GLOBALS['uccm_test_scheduled_hooks']  = array();
		$GLOBALS['uccm_test_capabilities']     = array();
		$GLOBALS['uccm_test_db_rows']          = array();
		$GLOBALS['uccm_test_db_var']           = null;
		$GLOBALS['uccm_test_db_query_result']  = 0;
	}

	public function test_addresses_are_masked_without_revealing_complete_values(): void {
		self::assertSame( '192.0.2.0', IP_Privacy::mask( '192.0.2.87' ) );
		self::assertSame( '2001:db8:abcd::', IP_Privacy::mask( '2001:db8:abcd:1234:5678:90ab:cdef:1234' ) );
		self::assertSame( '', IP_Privacy::mask( 'not-an-ip' ) );
	}

	public function test_clean_install_protection_never_stores_a_complete_ip(): void {
		$protected = IP_Privacy::protect( '192.0.2.87' );

		self::assertSame( '192.0.2.0', $protected['masked'] );
		self::assertSame( 64, strlen( $protected['fingerprint'] ) );
		self::assertNull( $protected['ciphertext'] );
		self::assertStringNotContainsString( '192.0.2.87', implode( '|', array_filter( $protected ) ) );
	}

	public function test_complete_ip_is_encrypted_only_after_explicit_opt_in(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			self::markTestSkipped( 'OpenSSL is unavailable.' );
		}

		$GLOBALS['uccm_test_options']['uccm_settings']['store_full_ip'] = true;
		$protected = IP_Privacy::protect( '198.51.100.42' );

		self::assertIsString( $protected['ciphertext'] );
		self::assertStringStartsWith( 'v1.', $protected['ciphertext'] );
		self::assertStringNotContainsString( '198.51.100.42', $protected['ciphertext'] );
		self::assertSame( '198.51.100.42', IP_Privacy::decrypt( $protected['ciphertext'] ) );
	}

	public function test_forwarded_headers_require_explicit_trusted_proxy_configuration(): void {
		$server = array(
			'REMOTE_ADDR'         => '203.0.113.10',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 203.0.113.10',
		);

		self::assertSame( '203.0.113.10', IP_Privacy::client_ip( $server ) );

		$GLOBALS['uccm_test_options']['uccm_settings']['trust_proxy_headers'] = true;
		$GLOBALS['uccm_test_options']['uccm_settings']['trusted_proxy_ips']   = array( '203.0.113.10' );

		self::assertSame( '198.51.100.25', IP_Privacy::client_ip( $server ) );
	}

	public function test_every_valid_decision_creates_one_append_only_receipt(): void {
		$result = Consent_Receipts::record(
			array(
				'receiptId'    => '12345678-1234-1234-1234-123456789abc',
				'action'       => 'grant',
				'policyVersion' => '1',
				'categories'    => array(
					'functional' => false,
					'analytics'  => true,
					'marketing'  => false,
				),
			),
			'192.0.2.87'
		);

		self::assertIsArray( $result );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		$row = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( 'grant', $row['action'] );
		self::assertSame( '192.0.2.0', $row['ip_masked'] );
		self::assertNull( $row['ip_ciphertext'] );
		self::assertSame( 64, strlen( $row['ip_fingerprint'] ) );
		self::assertSame( 64, strlen( $row['site_identifier'] ) );
		self::assertSame( 64, strlen( $row['integrity_hash'] ) );
		self::assertStringNotContainsString( '192.0.2.87', wp_json_encode( $row ) );
	}

	public function test_invalid_decisions_are_rejected_without_database_writes(): void {
		$result = Consent_Receipts::record(
			array(
				'receiptId'    => '12345678-1234-1234-1234-123456789abc',
				'action'       => 'assumed',
				'policyVersion' => '1',
				'categories'    => array(),
			),
			'192.0.2.87'
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_invalid_action', $result->get_error_code() );
		self::assertCount( 0, $GLOBALS['wpdb']->inserts );
	}

	public function test_record_access_requires_the_dedicated_capabilities(): void {
		$list = Consent_Receipts::records( 'view_uccm_consents', 20, false );
		self::assertInstanceOf( WP_Error::class, $list );
		self::assertSame( 'uccm_forbidden', $list->get_error_code() );

		$reveal = Consent_Receipts::reveal_ip( 1 );
		self::assertInstanceOf( WP_Error::class, $reveal );
		self::assertSame( 'uccm_forbidden', $reveal->get_error_code() );

		$GLOBALS['uccm_test_capabilities']['view_uccm_consents'] = true;
		self::assertSame( array(), Consent_Receipts::records( 'view_uccm_consents', 20, false ) );
	}

	public function test_retention_cleanup_is_scheduled_and_deletes_expired_rows(): void {
		Consent_Receipts::schedule_cleanup();
		self::assertArrayHasKey( 'uccm_retention_cleanup', $GLOBALS['uccm_test_scheduled_hooks'] );

		$GLOBALS['uccm_test_db_query_result'] = 3;
		self::assertSame( 3, Consent_Receipts::cleanup_expired() );
		self::assertStringContainsString( 'DELETE FROM wp_uccm_consents', $GLOBALS['wpdb']->queries[0] );
		self::assertStringContainsString( 'occurred_at < %s', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_browser_submits_each_decision_to_the_receipt_endpoint(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/consent.js' );

		self::assertIsString( $script );
		self::assertStringContainsString( 'storeReceipt( decision )', $script );
		self::assertStringContainsString( "method: 'POST'", $script );
		self::assertStringContainsString( 'keepalive: true', $script );
		self::assertStringContainsString( "'uccm:receipt-failed'", $script );
	}
}
