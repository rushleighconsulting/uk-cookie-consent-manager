<?php
/**
 * Integrity-checked WordPress updates.
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
	 * Per-site update health and outcome option.
	 */
	public const STATUS_OPTION = 'uccm_update_status';

	/**
	 * Public release metadata endpoint.
	 */
	private const MANIFEST_URL = 'https://github.com/rushleighconsulting/uk-cookie-consent-manager/releases/latest/download/update-manifest.json';

	/**
	 * Non-secret Ed25519 verification key for official UCCM releases.
	 */
	private const PUBLIC_KEY = 'Ok08SqvCqJivmZsa78W5xVZ8cVgMLJnHhVFTodDU2+c=';

	/**
	 * Expected release slug.
	 */
	private const SLUG = 'uk-cookie-consent-manager';

	/**
	 * Stable external update identifier.
	 */
	private const UPDATE_URI = 'https://github.com/rushleighconsulting/uk-cookie-consent-manager';

	/**
	 * Conservative free-space readiness threshold for download, unpack and backup.
	 */
	private const MIN_FREE_BYTES = 52428800;

	/**
	 * Register update hooks.
	 */
	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'update_offer' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'verify_download' ), 10, 4 );
		add_action( 'automatic_updates_complete', array( self::class, 'automatic_updates_complete' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'upgrader_process_complete' ), 10, 2 );
	}

	/**
	 * Supply authenticated release data through WordPress's Update URI API.
	 *
	 * @param array<string, mixed>|false $update      Existing external update.
	 * @param array<string, mixed>       $plugin_data Installed plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @param string[]                   $locales     Requested locales.
	 * @return array<string, mixed>|false
	 */
	public static function update_offer( array|false $update, array $plugin_data, string $plugin_file, array $locales ): array|false {
		unset( $plugin_data, $locales );

		if ( plugin_basename( UCCM_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$manifest = self::manifest();

		if ( is_wp_error( $manifest ) ) {
			return false;
		}

		if ( version_compare( $manifest['version'], UCCM_VERSION, '>' ) && ! self::is_compatible( $manifest ) ) {
			self::record_error(
				new \WP_Error(
					'uccm_update_incompatible',
					__( 'The latest authenticated release is not compatible with this WordPress or PHP version.', 'uk-cookie-consent-manager' )
				)
			);
			return false;
		}

		if ( version_compare( $manifest['version'], UCCM_VERSION, '>' ) && ! self::rollout_eligible( $manifest ) ) {
			self::store_status(
				array(
					'rollout_eligible' => false,
				)
			);
			return false;
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::SLUG,
			'version'      => $manifest['version'],
			'package'      => $manifest['package_url'],
			'url'          => self::UPDATE_URI,
			'requires_php' => $manifest['requires_php'],
		);
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
			'homepage'      => self::UPDATE_URI,
		);
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

		$has_rollout_percentage = array_key_exists( 'rollout_percentage', $manifest );
		$has_rollout_seed       = array_key_exists( 'rollout_seed', $manifest );

		if ( $has_rollout_percentage !== $has_rollout_seed ) {
			return new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest has incomplete staged-rollout data.', 'uk-cookie-consent-manager' ) );
		}

		if ( $has_rollout_percentage ) {
			$validated['rollout_percentage'] = trim( (string) $manifest['rollout_percentage'] );
			$validated['rollout_seed']       = trim( (string) $manifest['rollout_seed'] );
		}

		if (
			self::SLUG !== $validated['slug'] ||
			1 !== preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $validated['version'] ) ||
			! self::valid_package_url( $validated['package_url'], $validated['version'] ) ||
			1 !== preg_match( '/^[a-f0-9]{64}$/', $validated['sha256'] ) ||
			1 !== preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $validated['requires_php'] ) ||
			1 !== preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $validated['requires_wp'] ) ||
			( $has_rollout_percentage && ( 1 !== preg_match( '/^(100|[1-9]?[0-9])$/', $validated['rollout_percentage'] ) || 1 !== preg_match( '/^[0-9A-Za-z._-]{1,80}$/', $validated['rollout_seed'] ) ) )
		) {
			return new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest contains invalid release data.', 'uk-cookie-consent-manager' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the documented signature wire format.
		$signature = base64_decode( $validated['signature'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the documented public-key wire format.
		$public_key = base64_decode( self::PUBLIC_KEY, true );
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
	 * Return whether the running platform can safely install a release.
	 *
	 * @param array<string, string> $manifest Validated manifest.
	 */
	private static function is_compatible( array $manifest ): bool {
		return version_compare( PHP_VERSION, $manifest['requires_php'], '>=' )
			&& version_compare( get_bloginfo( 'version' ), $manifest['requires_wp'], '>=' );
	}

	/**
	 * Restrict signed packages to immutable assets in the official repository.
	 *
	 * @param string $url     Candidate package URL.
	 * @param string $version Authenticated release version.
	 */
	private static function valid_package_url( string $url, string $version ): bool {
		$parts         = wp_parse_url( $url );
		$expected_path = '/rushleighconsulting/uk-cookie-consent-manager/releases/download/v' . $version . '/uk-cookie-consent-manager-' . $version . '.zip';

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& 'github.com' === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& (string) ( $parts['path'] ?? '' ) === $expected_path
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['fragment'] );
	}

	/**
	 * Deterministically include a site in an optional signed staged rollout.
	 *
	 * Older manifests without rollout fields remain fully eligible.
	 *
	 * @param array<string, string> $manifest Validated manifest.
	 */
	public static function rollout_eligible( array $manifest ): bool {
		if ( ! isset( $manifest['rollout_percentage'], $manifest['rollout_seed'] ) ) {
			return true;
		}

		$percentage = max( 0, min( 100, (int) $manifest['rollout_percentage'] ) );

		if ( 100 === $percentage ) {
			return true;
		}

		$bucket = hexdec( substr( hash( 'sha256', home_url( '/' ) . '|' . $manifest['rollout_seed'] ), 0, 8 ) ) % 100;
		return $bucket < $percentage;
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
	 * Return bounded update health for the administration screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $stored ) ? $stored : array();

		return array_merge(
			array(
				'channel'                  => 'GitHub releases',
				'installed_version'        => UCCM_VERSION,
				'latest_version'           => '',
				'last_checked_at'          => '',
				'last_successful_check_at' => '',
				'last_error_code'          => '',
				'last_error_message'       => '',
				'last_error_at'            => '',
				'last_update_at'           => '',
				'last_update_version'      => '',
				'last_update_outcome'      => '',
				'rollout_eligible'         => true,
				'diagnostics'              => array(),
			),
			$status,
			array( 'installed_version' => UCCM_VERSION )
		);
	}

	/**
	 * Clear cached metadata and ask WordPress to check immediately.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	public static function refresh(): array|\WP_Error {
		delete_site_transient( self::MANIFEST_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		$result = self::manifest();
		self::diagnostics( true );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		return $result;
	}

	/**
	 * Return update and rollback readiness without exposing server paths.
	 *
	 * @param bool $probe_loopback Whether to perform a fresh same-site request.
	 * @return array<string, mixed>
	 */
	public static function diagnostics( bool $probe_loopback = false ): array {
		$status      = self::status();
		$previous    = is_array( $status['diagnostics'] ?? null ) ? $status['diagnostics'] : array();
		$content_dir = WP_CONTENT_DIR;
		$backup_dir  = rtrim( $content_dir, '/\\' ) . '/upgrade-temp-backup';
		$parent      = is_dir( $backup_dir ) ? $backup_dir : $content_dir;
		$free_bytes  = function_exists( 'disk_free_space' ) ? disk_free_space( $content_dir ) : false;
		$loopback    = $previous['loopback'] ?? 'not-checked';

		if ( $probe_loopback ) {
			$response = wp_safe_remote_get(
				home_url( '/' ),
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$loopback = 200 <= $code && 400 > $code ? 'available' : 'unavailable';
		}

		$diagnostics = array(
			'rollback_supported' => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ),
			'backup_writable'    => is_dir( $parent ) && wp_is_writable( $parent ),
			'free_bytes'         => false === $free_bytes ? null : max( 0, (int) $free_bytes ),
			'disk_space_usable'  => false !== $free_bytes && self::MIN_FREE_BYTES <= (int) $free_bytes,
			'loopback'           => $loopback,
			'checked_at'         => $probe_loopback ? gmdate( 'Y-m-d H:i:s' ) : (string) ( $previous['checked_at'] ?? '' ),
		);

		if ( $probe_loopback ) {
			self::store_status( array( 'diagnostics' => $diagnostics ) );
		}

		return $diagnostics;
	}

	/**
	 * Record results emitted after WordPress's automatic updater has finished.
	 *
	 * @param array<string, mixed> $results Automatic update results.
	 */
	public static function automatic_updates_complete( array $results ): void {
		$plugins = is_array( $results['plugin'] ?? null ) ? $results['plugin'] : array();

		foreach ( $plugins as $entry ) {
			$item = is_object( $entry ) && is_object( $entry->item ?? null ) ? $entry->item : null;

			if ( ! self::is_uccm_update_item( $item ) ) {
				continue;
			}

			self::record_update_result( is_object( $entry ) ? ( $entry->result ?? false ) : false, $item );
		}
	}

	/**
	 * Record successful and failed interactive upgrades.
	 *
	 * @param mixed                $upgrader   WordPress upgrader.
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 */
	public static function upgrader_process_complete( mixed $upgrader, array $hook_extra ): void {
		if ( 'update' !== (string) ( $hook_extra['action'] ?? '' ) || 'plugin' !== (string) ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = is_array( $hook_extra['plugins'] ?? null )
			? $hook_extra['plugins']
			: array( (string) ( $hook_extra['plugin'] ?? '' ) );

		if ( ! in_array( plugin_basename( UCCM_PLUGIN_FILE ), $plugins, true ) ) {
			return;
		}

		$result = is_object( $upgrader ) && property_exists( $upgrader, 'result' ) ? $upgrader->result : true;
		self::record_update_result( $result, null );
	}

	/**
	 * Record one update outcome using only bounded release metadata.
	 *
	 * @param mixed       $result WordPress update result.
	 * @param object|null $item   Authenticated update offer.
	 */
	private static function record_update_result( mixed $result, ?object $item ): void {
		$version = sanitize_text_field( (string) ( $item->new_version ?? $item->version ?? '' ) );
		$now     = gmdate( 'Y-m-d H:i:s' );

		if ( true === $result || is_array( $result ) ) {
			self::store_status(
				array(
					'last_update_at'      => $now,
					'last_update_version' => $version,
					'last_update_outcome' => 'success',
				)
			);
			Operational_Alerts::resolve_component( 'updater' );
			return;
		}

		$codes = is_wp_error( $result )
			? array_map( 'strval', $result->get_error_codes() )
			: array( 'update_failed' );

		$rollback_failed = in_array( 'plugin_update_fatal_error_rollback_failed', $codes, true );
		$rollback_ok     = in_array( 'plugin_update_fatal_error_rollback_successful', $codes, true );
		$outcome         = $rollback_failed ? 'rollback-failed' : ( $rollback_ok ? 'rolled-back' : 'failed' );
		$alert_code      = $rollback_failed ? 'uccm_update_rollback_failed' : ( $rollback_ok ? 'uccm_update_rolled_back' : 'uccm_update_failed' );

		self::store_status(
			array(
				'last_update_at'      => $now,
				'last_update_version' => $version,
				'last_update_outcome' => $outcome,
			)
		);
		Operational_Alerts::report( $alert_code, 'updater' );
	}

	/**
	 * Return whether an automatic update result belongs to UCCM.
	 *
	 * @param object|null $item Update offer.
	 */
	private static function is_uccm_update_item( ?object $item ): bool {
		if ( null === $item ) {
			return false;
		}

		return self::SLUG === (string) ( $item->slug ?? '' )
			|| plugin_basename( UCCM_PLUGIN_FILE ) === (string) ( $item->plugin ?? '' );
	}

	/**
	 * Merge a bounded update status change into the existing option.
	 *
	 * @param array<string, mixed> $changes Status fields.
	 */
	private static function store_status( array $changes ): void {
		$current = get_option( self::STATUS_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		update_option( self::STATUS_OPTION, array_merge( $current, $changes ), false );
	}

	/**
	 * Persist and report a fixed update error.
	 *
	 * @param \WP_Error $error Safe WordPress error.
	 */
	private static function record_error( \WP_Error $error ): \WP_Error {
		self::store_status(
			array(
				'last_checked_at'    => gmdate( 'Y-m-d H:i:s' ),
				'last_error_code'    => sanitize_key( (string) $error->get_error_code() ),
				'last_error_message' => sanitize_text_field( (string) $error->get_error_message() ),
				'last_error_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Operational_Alerts::report( 'uccm_update_check_failed', 'updater' );
		return $error;
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

		$url = self::MANIFEST_URL;

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 131072,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::record_error( new \WP_Error( 'uccm_update_manifest_unavailable', __( 'The authenticated update information could not be retrieved.', 'uk-cookie-consent-manager' ) ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$result  = is_array( $decoded ) ? self::validate_manifest( $decoded ) : new \WP_Error( 'uccm_update_manifest_invalid', __( 'The update manifest is not valid JSON.', 'uk-cookie-consent-manager' ) );

		if ( is_wp_error( $result ) ) {
			return self::record_error( $result );
		}

		set_site_transient( self::MANIFEST_TRANSIENT, $result, 6 * HOUR_IN_SECONDS );
		self::store_status(
			array(
				'latest_version'           => $result['version'],
				'rollout_eligible'         => self::rollout_eligible( $result ),
				'last_checked_at'          => gmdate( 'Y-m-d H:i:s' ),
				'last_successful_check_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error_code'          => '',
				'last_error_message'       => '',
				'last_error_at'            => '',
			)
		);
		Operational_Alerts::resolve_component( 'updater' );

		return $result;
	}

	/**
	 * Return the canonical JSON bytes covered by the Ed25519 signature.
	 *
	 * @param array<string, string> $manifest Manifest fields.
	 */
	private static function canonical_payload( array $manifest ): string {
		$signed = array(
			'slug'         => $manifest['slug'],
			'version'      => $manifest['version'],
			'package_url'  => $manifest['package_url'],
			'sha256'       => $manifest['sha256'],
			'requires_php' => $manifest['requires_php'],
			'requires_wp'  => $manifest['requires_wp'],
		);

		if ( isset( $manifest['rollout_percentage'], $manifest['rollout_seed'] ) ) {
			$signed['rollout_percentage'] = $manifest['rollout_percentage'];
			$signed['rollout_seed']       = $manifest['rollout_seed'];
		}

		$payload = wp_json_encode( $signed, JSON_UNESCAPED_SLASHES );

		return false === $payload ? '' : $payload;
	}
}
