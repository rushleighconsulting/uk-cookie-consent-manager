<?php
/**
 * Encrypted access to WordPress post-password protected content.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one optional site-local post password outside ordinary settings and evidence.
 */
final class Post_Password_Access {

	/**
	 * Encrypted per-site password option.
	 */
	public const PASSWORD_OPTION = 'uccm_post_password';

	/**
	 * Encrypt and persist one WordPress post password.
	 *
	 * @param string $password Administrator-supplied post password.
	 * @return true|\WP_Error
	 */
	public static function save_password( string $password ): bool|\WP_Error {
		if ( '' === $password ) {
			return new \WP_Error( 'uccm_post_password_empty', __( 'The post password cannot be empty.', 'rushleigh-cookie-choices' ) );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'uccm_post_password_crypto_unavailable', __( 'OpenSSL is required to protect the post password.', 'rushleigh-cookie-choices' ) );
		}

		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $password, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new \WP_Error( 'uccm_post_password_protection_failed', __( 'The post password could not be protected.', 'rushleigh-cookie-choices' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encrypted storage requires a text-safe representation.
		update_option( self::PASSWORD_OPTION, base64_encode( $nonce . $tag . $ciphertext ), false );
		return true;
	}

	/**
	 * Return whether a decryptable password is configured for this site.
	 */
	public static function has_password(): bool {
		return '' !== self::password();
	}

	/**
	 * Remove the encrypted password.
	 */
	public static function clear_password(): void {
		delete_option( self::PASSWORD_OPTION );
	}

	/**
	 * Return whether protected-content scanning is explicitly enabled and usable.
	 */
	public static function enabled(): bool {
		$settings = Settings::current();
		return true === ( $settings['scan_protected_content_enabled'] ?? false ) && self::has_password();
	}

	/**
	 * Match one stored WordPress post password without disclosing either value.
	 *
	 * WordPress stores the post_password field in plain text and hashes the
	 * submitted value into its short-lived wp-postpass cookie.
	 *
	 * @param string $post_password Password assigned to the post.
	 */
	public static function matches( string $post_password ): bool {
		if ( '' === $post_password || ! self::enabled() ) {
			return false;
		}

		$password = self::password();
		return '' !== $password && hash_equals( $post_password, $password );
	}

	/**
	 * Return whether a same-origin target is a protected post unlocked by the configured password.
	 *
	 * @param string $url Validated same-origin URL.
	 */
	public static function target_is_unlocked( string $url ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$post_id = url_to_postid( $url );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;

		return is_object( $post )
			&& 'publish' === (string) $post->post_status
			&& in_array( (string) $post->post_type, array( 'page', 'post' ), true )
			&& self::matches( (string) $post->post_password );
	}

	/**
	 * Return WordPress's native post-password cookie name.
	 */
	public static function cookie_name(): string {
		$cookie_hash = defined( 'COOKIEHASH' ) ? (string) COOKIEHASH : md5( home_url( '/' ) );
		return 'wp-postpass_' . $cookie_hash;
	}

	/**
	 * Build a fresh native WordPress post-password cookie value.
	 *
	 * @param callable|null $hasher Optional deterministic hasher for tests.
	 */
	public static function cookie_value( ?callable $hasher = null ): string {
		$password = self::password();

		if ( '' === $password || ! self::enabled() ) {
			return '';
		}

		if ( null === $hasher ) {
			if ( ! class_exists( '\\PasswordHash' ) ) {
				require_once ABSPATH . ( defined( 'WPINC' ) ? WPINC : 'wp-includes' ) . '/class-phpass.php';
			}

			$instance = new \PasswordHash( 8, true );
			$hasher   = static fn ( string $value ): string => $instance->HashPassword( $value );
		}

		$hash = (string) $hasher( $password );
		return str_starts_with( $hash, '$P$B' ) ? $hash : '';
	}

	/**
	 * Build WordPress's native wp-postpass Cookie request header.
	 *
	 * @param callable|null $hasher Optional deterministic hasher for tests.
	 */
	public static function cookie_header( ?callable $hasher = null ): string {
		$value = self::cookie_value( $hasher );
		return '' === $value ? '' : self::cookie_name() . '=' . rawurlencode( $value );
	}

	/**
	 * Issue a short-lived browser-bootstrap token for exact protected scan targets.
	 *
	 * The token is an opaque capability, not the password or WordPress cookie.
	 *
	 * @param int      $run_id  Scan run identifier.
	 * @param string[] $targets Exact protected targets in this browser pass.
	 */
	public static function issue_browser_token( int $run_id, array $targets ): string {
		$targets = array_values( array_filter( array_slice( array_unique( $targets ), 0, 100 ), array( self::class, 'target_is_unlocked' ) ) );

		if ( 1 > $run_id || array() === $targets || ! self::enabled() ) {
			return '';
		}

		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			'uccm_post_password_browser_' . $token,
			array(
				'run_id'  => $run_id,
				'site'    => home_url( '/' ),
				'targets' => $targets,
			),
			20 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Authorise one same-origin browser bootstrap without exposing the password.
	 *
	 * @param string $token   Opaque browser token.
	 * @param int    $run_id  Scan run identifier.
	 * @param string $target  Requested target.
	 */
	public static function browser_token_allows( string $token, int $run_id, string $target ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) || 1 > $run_id || ! self::target_is_unlocked( $target ) ) {
			return false;
		}

		$record  = get_transient( 'uccm_post_password_browser_' . $token );
		$targets = is_array( $record ) && is_array( $record['targets'] ?? null ) ? $record['targets'] : array();

		return is_array( $record )
			&& (int) ( $record['run_id'] ?? 0 ) === $run_id
			&& hash_equals( home_url( '/' ), (string) ( $record['site'] ?? '' ) )
			&& in_array( $target, $targets, true );
	}

	/**
	 * Decrypt the site-specific password. Fail closed after salt rotation or corruption.
	 */
	private static function password(): string {
		$encoded = get_option( self::PASSWORD_OPTION, '' );

		if ( ! is_string( $encoded ) || '' === $encoded || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the documented encrypted option format.
		$stored = base64_decode( $encoded, true );

		if ( false === $stored || 29 > strlen( $stored ) ) {
			return '';
		}

		$plaintext = openssl_decrypt(
			substr( $stored, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $stored, 0, 12 ),
			substr( $stored, 12, 16 )
		);

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Derive a site-bound key from WordPress salt material.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|uccm-post-password', true );
	}
}
