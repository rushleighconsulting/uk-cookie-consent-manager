<?php
/**
 * Integrity-checked private repository updates.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies fail-closed WordPress updates from signed release metadata.
 */
final class Secure_Updater {

	/**
	 * Update metadata cache key.
	 */
	private const MANIFEST_TRANSIENT = 'uccm_update_manifest';

	/**
	 * Encrypted site credential option.
	 */
	public const CREDENTIAL_OPTION = 'uccm_update_credential';

	/**
	 * Expected release slug.
	 */
	private const SLUG = 'uk-cookie-consent-manager';

	/**
	 * Register update hooks.
	 */
	public static function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'check_updates' ) );
		add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( self::class, 'allow_auto_update' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( self::class, 'verify_download' ), 10, 4 );
	}

	/**
	 * Offer a newer compatible release to WordPress.
	 *
	 * @param mixed $transient Plugin update transient.
	 */
	public static function check_updates( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = self::manifest();

		if ( is_wp_error( $manifest ) || ! self::is_newer_compatible( $manifest ) ) {
			return $transient;
		}

		$plugin = plugin_basename( UCCM_PLUGIN_FILE );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $plugin ] = (object) array(
			'id'           => self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $plugin,
			'new_version'  => $manifest['version'],
			'package'      => $manifest['package_url'],
			'url'          => 'https://github.com/rushleighconsulting/uk-cookie-consent-manager',
			'requires'     => $manifest['requires_wp'],
			'requires_php' => $manifest['requires_php'],
		);

		return $transient;
	}

	/**
	 * Return signed plugin information for the WordPress details modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param object $arguments API arguments.
	 */
	public static function plugin_information( mixed $result, string $action, object $arguments ): mixed {
		if ( 'plugin_information' !== $action || self::SLUG !== (string) ( $arguments->slug ?? '' ) ) {
			return $result;
		}

		$manifest = self::manifest();

		if ( is_wp_error( $manifest ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'UK Cookie Consent Manager',
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'requires'      => $manifest['requires_wp'],
			'requires_php'  => $manifest['requires_php'],
			'download_link' => $manifest['package_url'],
			'homepage'      => 'https://github.com/rushleighconsulting/uk-cookie-consent-manager',
		);
	}

	/**
	 * Honour the explicit administrator opt-in for this plugin only.
	 *
	 * @param bool  $update Whether WordPress should update automatically.
	 * @param mixed $item   Update item.
	 */
	public static function allow_auto_update( bool $update, mixed $item ): bool {
		$plugin = is_object( $item ) ? (string) ( $item->plugin ?? '' ) : '';

		if ( plugin_basename( UCCM_PLUGIN_FILE ) !== $plugin ) {
			return $update;
		}

		$settings = Settings::current();
		return true === ( $settings['auto_update'] ?? false );
	}

	/**
	 * Download this plugin's package over HTTPS and verify its signed checksum.
	 *
	 * @param mixed                $reply      Existing short-circuit value.
	 * @param string               $package    Package URL.
	 * @param mixed                $upgrader   WordPress upgrader.
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 * @return mixed
	 */
	public static function verify_download( mixed $reply, string $package, mixed $upgrader, array $hook_extra ): mixed {
		unset( $upgrader );

		if ( false !== $reply && null !== $reply ) {
			return $reply;
		}

		$plugin = (string) ( $hook_extra['plugin'] ?? '' );

		if ( plugin_basename( UCCM_PLUGIN_FILE ) !== $plugin ) {
			return $reply;
		}

		$manifest = self::manifest();

		if ( is_wp_error( $manifest ) || ! hash_equals( $manifest['package_url'], $package ) ) {
			return new \WP_Error( 'uccm_update_metadata_mismatch', __( 'The update package does not match authenticated release metadata.', 'uk-cookie-consent-manager' ) );
		}

		$temporary = wp_tempnam( $package );

		if ( '' === $temporary ) {
			return new \WP_Error( 'uccm_update_temp_failed', __( 'WordPress could not create a temporary update file.', 'uk-cookie-consent-manager' ) );
		}

		$response = wp_safe_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'stream'      => true,
				'filename'    => $temporary,
				'headers'     => self::authorization_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! self::verify_file( $temporary, $manifest['sha256'] ) ) {
			wp_delete_file( $temporary );
			return new \WP_Error( 'uccm_update_integrity_failed', __( 'The update download failed authentication or checksum verification.', 'uk-cookie-consent-manager' ) );
		}

		return $temporary;
	}

	/**
	 * Validate signed manifest data.
	 *
	 * @param array<string, mixed> $manifest Untrusted decoded manifest.
	 * @param callable|null        $verifier Optional deterministic test verifier.
	 * @return array<string, string>|\WP_Error
	 */
	public static function validate_manifest( array $manifest, ?callable $verifier = null ): array|\WP_Error {
		$required = array( 'slug', 'version', 'package_url', 'sha256', 'requires_php', 'requires_wp', 'signature' );

		foreach ( $required as $field ) {
			if ( ! isset( $manifest[ $field ] ) || ! is_string( $manifest[ $field ] ) || '' === $manifest[ $field ] ) {
				return new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest is incomplete.', 'uk-cookie-consent-manager' ) );
			}
		}

		$validated = array(
			'slug'         => sanitize_key( $manifest['slug'] ),
			'version'      => trim( $manifest['version'] ),
			'package_url'  => esc_url_raw( $manifest['package_url'] ),
			'sha256'       => strtolower( $manifest['sha256'] ),
			'requires_php' => trim( $manifest['requires_php'] ),
			'requires_wp'  => trim( $manifest['requires_wp'] ),
			'signature'    => $manifest['signature'],
		);

		if (
			self::SLUG !== $validated['slug'] ||
			1 !== preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $validated['version'] ) ||
			'https' !== strtolower( (string) wp_parse_url( $validated['package_url'], PHP_URL_SCHEME ) ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/', $validated['sha256'] ) ||
			1 !== preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $validated['requires_php'] ) ||
			1 !== preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $validated['requires_wp'] )
		) {
			return new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest contains invalid release data.', 'uk-cookie-consent-manager' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the documented signature wire format.
		$signature = base64_decode( $validated['signature'], true );
		$settings  = Settings::current();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the documented public-key wire format.
		$public_key = base64_decode( (string) ( $settings['update_public_key'] ?? '' ), true );
		$payload    = self::canonical_payload( $validated );

		if ( false === $signature || 64 !== strlen( $signature ) || false === $public_key || 32 !== strlen( $public_key ) ) {
			return new \WP_Error( 'uccm_update_signature_invalid', __( 'The update manifest signature cannot be verified.', 'uk-cookie-consent-manager' ) );
		}

		$verified = null === $verifier
			? function_exists( 'sodium_crypto_sign_verify_detached' ) && sodium_crypto_sign_verify_detached( $signature, $payload, $public_key )
			: (bool) $verifier( $signature, $payload, $public_key );

		if ( ! $verified ) {
			return new \WP_Error( 'uccm_update_signature_invalid', __( 'The update manifest signature is invalid.', 'uk-cookie-consent-manager' ) );
		}

		unset( $validated['signature'] );
		return $validated;
	}

	/**
	 * Return whether metadata represents a newer compatible release.
	 *
	 * @param array<string, string> $manifest Validated manifest.
	 * @param string|null           $installed Optional installed version for tests.
	 * @param string|null           $php       Optional PHP version for tests.
	 * @param string|null           $wordpress Optional WordPress version for tests.
	 */
	public static function is_newer_compatible( array $manifest, ?string $installed = null, ?string $php = null, ?string $wordpress = null ): bool {
		$installed = $installed ?? UCCM_VERSION;
		$php       = $php ?? PHP_VERSION;
		$wordpress = $wordpress ?? get_bloginfo( 'version' );

		return version_compare( $manifest['version'], $installed, '>' )
			&& version_compare( $php, $manifest['requires_php'], '>=' )
			&& version_compare( $wordpress, $manifest['requires_wp'], '>=' );
	}

	/**
	 * Verify a package file against a lowercase SHA-256 digest.
	 *
	 * @param string $filename Package file path.
	 * @param string $expected Expected SHA-256 digest.
	 */
	public static function verify_file( string $filename, string $expected ): bool {
		return is_file( $filename ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $expected ) && hash_equals( $expected, hash_file( 'sha256', $filename ) );
	}

	/**
	 * Encrypt and persist a site-specific download credential.
	 *
	 * @param string $credential Site-specific repository credential.
	 * @return true|\WP_Error
	 */
	public static function save_credential( string $credential ): bool|\WP_Error {
		$credential = trim( $credential );

		if ( '' === $credential ) {
			return new \WP_Error( 'uccm_update_credential_empty', __( 'The update credential cannot be empty.', 'uk-cookie-consent-manager' ) );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'uccm_update_crypto_unavailable', __( 'OpenSSL is required to protect the update credential.', 'uk-cookie-consent-manager' ) );
		}

		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $credential, 'aes-256-gcm', self::credential_key(), OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new \WP_Error( 'uccm_update_credential_failed', __( 'The update credential could not be protected.', 'uk-cookie-consent-manager' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encrypted storage requires a text-safe representation.
		update_option( self::CREDENTIAL_OPTION, base64_encode( $nonce . $tag . $ciphertext ), false );
		return true;
	}

	/**
	 * Return whether an encrypted credential is configured.
	 */
	public static function has_credential(): bool {
		return '' !== self::credential();
	}

	/**
	 * Remove the encrypted credential.
	 */
	public static function clear_credential(): void {
		delete_option( self::CREDENTIAL_OPTION );
	}

	/**
	 * Fetch, decode and verify update metadata.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	private static function manifest(): array|\WP_Error {
		$cached = get_site_transient( self::MANIFEST_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$settings = Settings::current();
		$url      = (string) ( $settings['update_manifest_url'] ?? '' );

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new \WP_Error( 'uccm_update_url_invalid', __( 'A valid HTTPS update manifest URL is required.', 'uk-cookie-consent-manager' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 131072,
				'headers'             => self::authorization_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'uccm_update_manifest_unavailable', __( 'Authenticated update metadata could not be retrieved.', 'uk-cookie-consent-manager' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$result  = is_array( $decoded ) ? self::validate_manifest( $decoded ) : new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest is not valid JSON.', 'uk-cookie-consent-manager' ) );

		if ( ! is_wp_error( $result ) ) {
			set_site_transient( self::MANIFEST_TRANSIENT, $result, 6 * HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Return the canonical JSON bytes covered by the Ed25519 signature.
	 *
	 * @param array<string, string> $manifest Manifest fields.
	 */
	private static function canonical_payload( array $manifest ): string {
		$payload = wp_json_encode(
			array(
				'slug'         => $manifest['slug'],
				'version'      => $manifest['version'],
				'package_url'  => $manifest['package_url'],
				'sha256'       => $manifest['sha256'],
				'requires_php' => $manifest['requires_php'],
				'requires_wp'  => $manifest['requires_wp'],
			),
			JSON_UNESCAPED_SLASHES
		);

		return false === $payload ? '' : $payload;
	}

	/**
	 * Return an Authorization header only when decryption succeeds.
	 *
	 * @return array<string, string>
	 */
	private static function authorization_headers(): array {
		$credential = self::credential();
		return '' === $credential ? array() : array( 'Authorization' => 'Bearer ' . $credential );
	}

	/**
	 * Decrypt the site-specific credential without exposing it to output.
	 */
	private static function credential(): string {
		$encoded = get_option( self::CREDENTIAL_OPTION, '' );

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
			self::credential_key(),
			OPENSSL_RAW_DATA,
			substr( $stored, 0, 12 ),
			substr( $stored, 12, 16 )
		);

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Derive a site-bound encryption key from WordPress salts.
	 */
	private static function credential_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|uccm-update-credential', true );
	}
}
