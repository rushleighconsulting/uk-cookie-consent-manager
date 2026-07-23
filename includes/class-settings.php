<?php
/**
 * Validated plugin settings.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the Release 1 settings contract used by administration screens.
 */
final class Settings {

	/**
	 * WordPress option name.
	 */
	public const OPTION_NAME = 'uccm_settings';

	/**
	 * Return privacy-preserving defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'consent_lifetime_days'  => 180,
			'consent_policy_version' => Consent_State::POLICY_VERSION,
			'retention_days'         => 365,
			'store_full_ip'          => false,
			'trust_proxy_headers'    => false,
			'trusted_proxy_ips'      => array(),
			'scan_urls'              => array(),
		);
	}

	/**
	 * Return current settings merged over safe defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Validate a partial settings update without dropping unrelated values.
	 *
	 * @param array<string, mixed>      $input   Untrusted partial settings.
	 * @param array<string, mixed>|null $current Existing settings for testing.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, ?array $current = null ): array {
		$settings = null === $current ? self::current() : array_merge( self::defaults(), $current );

		if ( array_key_exists( 'consent_lifetime_days', $input ) ) {
			$settings['consent_lifetime_days'] = max( 1, min( 730, (int) $input['consent_lifetime_days'] ) );
		}

		if ( array_key_exists( 'consent_policy_version', $input ) ) {
			$version                            = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $input['consent_policy_version'] );
			$settings['consent_policy_version'] = '' === $version ? Consent_State::POLICY_VERSION : substr( $version, 0, 40 );
		}

		if ( array_key_exists( 'retention_days', $input ) ) {
			$settings['retention_days'] = max( 1, min( 3650, (int) $input['retention_days'] ) );
		}

		foreach ( array( 'store_full_ip', 'trust_proxy_headers' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$settings[ $flag ] = self::boolean( $input[ $flag ] );
			}
		}

		if ( array_key_exists( 'trusted_proxy_ips', $input ) ) {
			$settings['trusted_proxy_ips'] = self::trusted_proxy_ips( $input['trusted_proxy_ips'] );
		}

		if ( array_key_exists( 'scan_urls', $input ) ) {
			$settings['scan_urls'] = self::scan_urls( $input['scan_urls'] );
		}

		return $settings;
	}

	/**
	 * Persist a validated partial settings update.
	 *
	 * @param array<string, mixed> $input Untrusted partial settings.
	 * @return array<string, mixed>
	 */
	public static function update( array $input ): array {
		$settings = self::sanitize( $input );
		update_option( self::OPTION_NAME, $settings, false );
		return $settings;
	}

	/**
	 * Interpret checkbox-like values without accepting arbitrary truthy strings.
	 *
	 * @param mixed $value Candidate flag.
	 */
	private static function boolean( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}

	/**
	 * Return unique, bounded scan URLs.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]
	 */
	private static function scan_urls( mixed $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		$values = is_array( $values ) ? $values : array();
		$urls   = array();

		foreach ( array_slice( $values, 0, Scanner::MAX_TARGETS - 1 ) as $candidate ) {
			$url = esc_url_raw( trim( (string) $candidate ) );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Return unique, canonical proxy IP addresses.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]
	 */
	private static function trusted_proxy_ips( mixed $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$values = is_array( $values ) ? $values : array();
		$valid  = array();

		foreach ( $values as $candidate ) {
			$ip = filter_var( trim( (string) $candidate ), FILTER_VALIDATE_IP );

			if ( false !== $ip ) {
				$valid[] = (string) $ip;
			}
		}

		return array_values( array_unique( $valid ) );
	}
}
