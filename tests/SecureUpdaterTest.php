<?php
/**
 * Secure updater tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Secure_Updater;
use UCCM\Settings;

final class SecureUpdaterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']         = array();
		$GLOBALS['uccm_test_site_transients'] = array();
	}

	public function test_valid_signature_and_newer_compatible_release_are_accepted(): void {
		Settings::update(
			array(
				'update_public_key' => base64_encode( str_repeat( 'p', 32 ) ),
			)
		);
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
		Settings::update(
			array(
				'update_public_key' => base64_encode( str_repeat( 'p', 32 ) ),
			)
		);
		$result = Secure_Updater::validate_manifest(
			$this->manifest(),
			static fn (): bool => false
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'uccm_update_signature_invalid', $result->get_error_code() );
	}

	public function test_checksum_mismatch_is_blocked(): void {
		$file = tempnam( sys_get_temp_dir(), 'uccm-test-' );
		self::assertIsString( $file );
		file_put_contents( $file, 'trusted package bytes' );

		self::assertTrue( Secure_Updater::verify_file( $file, hash_file( 'sha256', $file ) ) );
		self::assertFalse( Secure_Updater::verify_file( $file, str_repeat( '0', 64 ) ) );

		unlink( $file );
	}

	public function test_site_credential_is_encrypted_and_never_stored_in_settings(): void {
		$result = Secure_Updater::save_credential( 'site-specific-token' );

		self::assertTrue( $result );
		self::assertArrayHasKey( Secure_Updater::CREDENTIAL_OPTION, $GLOBALS['uccm_test_options'] );
		self::assertStringNotContainsString( 'site-specific-token', $GLOBALS['uccm_test_options'][ Secure_Updater::CREDENTIAL_OPTION ] );
		self::assertArrayNotHasKey( 'update_credential', Settings::current() );
		self::assertTrue( Secure_Updater::has_credential() );

		Secure_Updater::clear_credential();
		self::assertFalse( Secure_Updater::has_credential() );
	}

	public function test_auto_update_accepts_nullable_wordpress_value_and_requires_explicit_opt_in(): void {
		$item       = (object) array( 'plugin' => 'uk-cookie-consent-manager/uk-cookie-consent-manager.php' );
		$other_item = (object) array( 'plugin' => 'other/plugin.php' );

		self::assertFalse( Secure_Updater::allow_auto_update( null, $item ) );
		self::assertFalse( Secure_Updater::allow_auto_update( false, $item ) );
		self::assertNull( Secure_Updater::allow_auto_update( null, $other_item ) );
		self::assertFalse( Secure_Updater::allow_auto_update( false, $other_item ) );
		self::assertTrue( Secure_Updater::allow_auto_update( true, $other_item ) );

		Settings::update( array( 'auto_update' => true ) );
		self::assertTrue( Secure_Updater::allow_auto_update( null, $item ) );
		self::assertTrue( Secure_Updater::allow_auto_update( false, $item ) );
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
