<?php
/**
 * Secure updater tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Secure_Updater;

final class SecureUpdaterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']         = array();
		$GLOBALS['uccm_test_site_transients'] = array();
		$GLOBALS['uccm_test_actions']         = array();
		$GLOBALS['uccm_test_filters']         = array();
		$GLOBALS['uccm_test_capabilities']    = array();
		$GLOBALS['uccm_test_wp_update_plugins_calls'] = 0;
	}

	public function test_valid_signature_and_newer_compatible_release_are_accepted(): void {
		$manifest = $this->manifest();
		$result   = Secure_Updater::validate_manifest(
			$manifest,
			static function ( string $signature, string $payload, string $public_key ): bool {
				return 64 === strlen( $signature )
					&& 32 === strlen( $public_key )
					&& str_contains( $payload, '"version":"0.2.0"' );
			}
		);

		self::assertIsArray( $result );
		self::assertTrue( Secure_Updater::is_newer_compatible( $result, '0.1.0', '8.2.0', '6.8.0' ) );
		self::assertFalse( Secure_Updater::is_newer_compatible( $result, '0.2.0', '8.2.0', '6.8.0' ) );
		self::assertFalse( Secure_Updater::is_newer_compatible( $result, '0.1.0', '8.1.0', '6.8.0' ) );
	}

	public function test_signature_mismatch_is_blocked(): void {
		$result = Secure_Updater::validate_manifest(
			$this->manifest(),
			static fn (): bool => false
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_update_signature_invalid', $result->get_error_code() );
	}

	public function test_package_outside_the_official_immutable_release_path_is_blocked(): void {
		$manifest                = $this->manifest();
		$manifest['package_url'] = 'https://example.test/uk-cookie-consent-manager-0.2.0.zip';
		$result                  = Secure_Updater::validate_manifest( $manifest, static fn (): bool => true );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_update_manifest_invalid', $result->get_error_code() );
	}

	public function test_checksum_mismatch_is_blocked(): void {
		$file = tempnam( sys_get_temp_dir(), 'uccm-test-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'trusted package bytes' );

		self::assertTrue( Secure_Updater::verify_file( $file, hash_file( 'sha256', $file ) ) );
		self::assertFalse( Secure_Updater::verify_file( $file, str_repeat( '0', 64 ) ) );

		unlink( $file );
	}

	public function test_register_uses_wordpress_native_update_controls(): void {
		Secure_Updater::register();

		self::assertArrayHasKey( 'update_plugins_github.com', $GLOBALS['uccm_test_filters'] );
		self::assertArrayNotHasKey( 'auto_update_plugin', $GLOBALS['uccm_test_filters'] );
		self::assertArrayHasKey( 'load-plugins.php', $GLOBALS['uccm_test_actions'] );
		self::assertArrayHasKey( 'automatic_updates_complete', $GLOBALS['uccm_test_actions'] );
	}

	public function test_first_plugins_screen_visit_primes_wordpress_update_metadata_once(): void {
		$GLOBALS['uccm_test_capabilities']['update_plugins']       = true;
		$GLOBALS['uccm_test_site_transients']['update_plugins']    = array( 'checked' => array() );

		Secure_Updater::prime_native_update_controls();
		Secure_Updater::prime_native_update_controls();

		self::assertSame( 1, $GLOBALS['uccm_test_wp_update_plugins_calls'] );
		self::assertArrayNotHasKey( 'update_plugins', $GLOBALS['uccm_test_site_transients'] );
		self::assertSame( '1', $GLOBALS['uccm_test_site_transients']['uccm_update_bootstrap'] );
	}

	public function test_update_metadata_bootstrap_requires_update_capability(): void {
		Secure_Updater::prime_native_update_controls();

		self::assertSame( 0, $GLOBALS['uccm_test_wp_update_plugins_calls'] );
		self::assertArrayNotHasKey( 'uccm_update_bootstrap', $GLOBALS['uccm_test_site_transients'] );
	}

	public function test_successful_authenticated_check_skips_update_metadata_bootstrap(): void {
		$GLOBALS['uccm_test_capabilities']['update_plugins'] = true;
		$GLOBALS['uccm_test_options'][ Secure_Updater::STATUS_OPTION ] = array(
			'last_successful_check_at' => '2026-07-24 12:47:57',
		);

		Secure_Updater::prime_native_update_controls();

		self::assertSame( 0, $GLOBALS['uccm_test_wp_update_plugins_calls'] );
		self::assertArrayNotHasKey( 'uccm_update_bootstrap', $GLOBALS['uccm_test_site_transients'] );
	}

	public function test_native_update_uri_offer_uses_authenticated_cached_manifest(): void {
		$GLOBALS['uccm_test_site_transients']['uccm_update_manifest'] = array_diff_key(
			$this->manifest(),
			array( 'signature' => true )
		);

		$result = Secure_Updater::update_offer(
			false,
			array(),
			'uk-cookie-consent-manager/uk-cookie-consent-manager.php',
			array( 'en_GB' )
		);

		self::assertIsArray( $result );
		self::assertSame( '0.2.0', $result['version'] );
		self::assertSame( 'https://github.com/rushleighconsulting/uk-cookie-consent-manager', $result['id'] );
		self::assertArrayNotHasKey( 'autoupdate', $result );
	}

	public function test_signed_staged_rollout_is_deterministic_and_older_manifests_remain_eligible(): void {
		self::assertTrue( Secure_Updater::rollout_eligible( array() ) );
		self::assertFalse(
			Secure_Updater::rollout_eligible(
				array(
					'rollout_percentage' => '0',
					'rollout_seed'       => 'v0.2.0',
				)
			)
		);
		self::assertTrue(
			Secure_Updater::rollout_eligible(
				array(
					'rollout_percentage' => '100',
					'rollout_seed'       => 'v0.2.0',
				)
			)
		);
	}

	public function test_staged_rollout_fields_are_covered_by_the_signature_payload(): void {
		$manifest                       = $this->manifest();
		$manifest['rollout_percentage'] = '25';
		$manifest['rollout_seed']       = 'v0.2.0';
		$result                         = Secure_Updater::validate_manifest(
			$manifest,
			static fn ( string $signature, string $payload, string $public_key ): bool => 64 === strlen( $signature )
				&& 32 === strlen( $public_key )
				&& str_contains( $payload, '"rollout_percentage":"25"' )
				&& str_contains( $payload, '"rollout_seed":"v0.2.0"' )
		);

		self::assertIsArray( $result );
		self::assertSame( '25', $result['rollout_percentage'] );
	}

	/**
	 * Return structurally valid signed metadata.
	 *
	 * @return array<string, string>
	 */
	private function manifest(): array {
		return array(
			'slug'         => 'uk-cookie-consent-manager',
			'version'      => '0.2.0',
			'package_url'  => 'https://github.com/rushleighconsulting/uk-cookie-consent-manager/releases/download/v0.2.0/uk-cookie-consent-manager-0.2.0.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'requires_php' => '8.2',
			'requires_wp'  => '6.8',
			'signature'    => base64_encode( str_repeat( 's', 64 ) ),
		);
	}
}
