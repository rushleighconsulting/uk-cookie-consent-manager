<?php
/**
 * Privacy-preserving IP processing.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Masks, fingerprints and optionally encrypts visitor IP addresses.
 */
final class IP_Privacy {

	/**
	 * Return the client IP without trusting proxy headers by default.
	 *
	 * Forwarded headers are considered only when proxy trust is enabled and the
	 * direct peer is in the configured trusted-proxy allowlist.
	 *
	 * @param array<string, mixed>|null $server Optional server values for testing.
	 */
	public static function client_ip( ?array $server = null ): string {
		if ( null === $server ) {
			$server = $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated as IP addresses below.
		}

		$remote   = isset( $server['REMOTE_ADDR'] ) ? self::valid_ip( (string) $server['REMOTE_ADDR'] ) : '';
		$settings = self::settings();
		$trusted  = isset( $settings['trusted_proxy_ips'] ) && is_array( $settings['trusted_proxy_ips'] )
			? array_filter( array_map( array( self::class, 'valid_ip' ), $settings['trusted_proxy_ips'] ) )
			: array();

		if ( empty( $settings['trust_proxy_headers'] ) || '' === $remote || ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) : array();

		foreach ( $forwarded as $candidate ) {
			$ip = self::valid_ip( trim( $candidate ) );

			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return $remote;
	}

	/**
	 * Produce the default privacy-safe representation of an address.
	 *
	 * @param string $ip Complete IP address.
	 * @return array{masked: string, fingerprint: string, ciphertext: string|null}
	 */
	public static function protect( string $ip ): array {
		$ip = self::valid_ip( $ip );

		if ( '' === $ip ) {
			return array(
				'masked'      => '',
				'fingerprint' => '',
				'ciphertext'  => null,
			);
		}

		$settings   = self::settings();
		$ciphertext = ! empty( $settings['store_full_ip'] ) ? self::encrypt( $ip ) : null;

		return array(
			'masked'      => self::mask( $ip ),
			'fingerprint' => hash_hmac( 'sha256', $ip, self::key( 'fingerprint' ) ),
			'ciphertext'  => '' !== $ciphertext ? $ciphertext : null,
		);
	}

	/**
	 * Mask an IPv4 address to /24 and an IPv6 address to /48.
	 *
	 * @param string $ip Complete IP address.
	 */
	public static function mask( string $ip ): string {
		$ip = self::valid_ip( $ip );

		if ( '' === $ip ) {
			return '';
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return '';
		}

		for ( $index = 6; $index < 16; $index++ ) {
			$packed[ $index ] = "\0";
		}

		$masked = inet_ntop( $packed );
		return false === $masked ? '' : $masked;
	}

	/**
	 * Decrypt a stored full address for an already-authorised caller.
	 *
	 * @param string $payload Authenticated encrypted payload.
	 */
	public static function decrypt( string $payload ): string {
		if ( ! str_starts_with( $payload, 'v1.' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes an authenticated ciphertext envelope, not executable code.
		$decoded = base64_decode( substr( $payload, 3 ), true );

		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::key( 'encryption' ),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'uccm-ip-v1'
		);

		return false === $plaintext ? '' : self::valid_ip( $plaintext );
	}

	/**
	 * Encrypt a complete address with site-held key material.
	 *
	 * @param string $ip Complete IP address.
	 */
	private static function encrypt( string $ip ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			do_action( 'uccm_ip_encryption_failed', 'openssl_unavailable' );
			return '';
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( \Exception ) {
			do_action( 'uccm_ip_encryption_failed', 'random_source_unavailable' );
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$ip,
			'aes-256-gcm',
			self::key( 'encryption' ),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'uccm-ip-v1'
		);

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			do_action( 'uccm_ip_encryption_failed', 'encryption_failed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes binary ciphertext for storage, not obfuscation.
		return 'v1.' . base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Derive purpose-specific binary key material from the site salts.
	 *
	 * @param string $purpose Key-derivation purpose.
	 */
	private static function key( string $purpose ): string {
		return hash_hmac( 'sha256', 'uccm|' . $purpose, wp_salt( 'auth' ), true );
	}

	/**
	 * Read plugin privacy settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$settings = get_option( 'uccm_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Return a canonical IP address or an empty string.
	 *
	 * @param string $ip Candidate IP address.
	 */
	private static function valid_ip( string $ip ): string {
		$validated = filter_var( $ip, FILTER_VALIDATE_IP );
		return false === $validated ? '' : (string) $validated;
	}
}
