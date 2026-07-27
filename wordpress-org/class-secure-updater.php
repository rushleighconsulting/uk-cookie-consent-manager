<?php
/**
 * Native WordPress.org update status.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Reports native WordPress.org update and rollback readiness.
 *
 * The WordPress.org distribution replaces the GitHub updater with this class.
 * It never requests, downloads or authenticates executable code from GitHub.
 */
final class Secure_Updater {

	/**
	 * Per-site update outcome option.
	 */
	public const STATUS_OPTION = 'uccm_update_status';

	/**
	 * Conservative free-space readiness threshold.
	 */
	private const MIN_FREE_BYTES = 52428800;

	/**
	 * Register native WordPress update outcome hooks.
	 */
	public static function register(): void {
		add_action( 'automatic_updates_complete', array( self::class, 'automatic_updates_complete' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'upgrader_process_complete' ), 10, 2 );
	}

	/**
	 * Return update information supplied by WordPress.org.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$stored        = get_option( self::STATUS_OPTION, array() );
		$status        = is_array( $stored ) ? $stored : array();
		$updates       = get_site_transient( 'update_plugins' );
		$plugin_file   = plugin_basename( UCCM_PLUGIN_FILE );
		$latest        = '';
		$last_checked  = '';
		$update_source = null;

		if ( is_object( $updates ) ) {
			$response      = is_array( $updates->response ?? null ) ? $updates->response : array();
			$no_update     = is_array( $updates->no_update ?? null ) ? $updates->no_update : array();
			$update_source = $response[ $plugin_file ] ?? $no_update[ $plugin_file ] ?? null;
			$checked       = (int) ( $updates->last_checked ?? 0 );
			$last_checked  = 0 < $checked ? gmdate( 'Y-m-d H:i:s', $checked ) : '';
		}

		if ( is_object( $update_source ) ) {
			$latest = sanitize_text_field( (string) ( $update_source->new_version ?? '' ) );
		}

		return array_merge(
			array(
				'channel'                  => 'WordPress.org Plugin Directory',
				'channel_description'      => __( 'WordPress.org supplies UCCM updates through WordPress’s normal plugin-update service.', 'uk-cookie-consent-manager' ),
				'installed_version'        => UCCM_VERSION,
				'latest_version'           => $latest,
				'last_checked_at'          => $last_checked,
				'last_successful_check_at' => $last_checked,
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
			array(
				'channel'                  => 'WordPress.org Plugin Directory',
				'channel_description'      => __( 'WordPress.org supplies UCCM updates through WordPress’s normal plugin-update service.', 'uk-cookie-consent-manager' ),
				'installed_version'        => UCCM_VERSION,
				'latest_version'           => $latest,
				'last_checked_at'          => $last_checked,
				'last_successful_check_at' => $last_checked,
				'rollout_eligible'         => true,
			)
		);
	}

	/**
	 * Ask WordPress to check its configured update services immediately.
	 *
	 * @return array<string, mixed>
	 */
	public static function refresh(): array {
		delete_site_transient( 'update_plugins' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		return self::status();
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
	 * Record native automatic-update results.
	 *
	 * @param array<string, mixed> $results Automatic update results.
	 */
	public static function automatic_updates_complete( array $results ): void {
		$plugins = is_array( $results['plugin'] ?? null ) ? $results['plugin'] : array();

		foreach ( $plugins as $entry ) {
			$item = is_object( $entry ) && is_object( $entry->item ?? null ) ? $entry->item : null;

			if ( ! is_object( $item ) || 'uk-cookie-consent-manager' !== (string) ( $item->slug ?? '' ) ) {
				continue;
			}

			self::record_result( is_object( $entry ) ? ( $entry->result ?? false ) : false, (string) ( $item->new_version ?? '' ) );
		}
	}

	/**
	 * Record native interactive update results.
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

		$result = is_object( $upgrader ) ? ( $upgrader->result ?? false ) : false;
		self::record_result( $result, UCCM_VERSION );
	}

	/**
	 * Persist one bounded native update outcome.
	 *
	 * @param mixed  $result  WordPress update result.
	 * @param string $version Updated version.
	 */
	private static function record_result( mixed $result, string $version ): void {
		$success = true === $result || is_array( $result );
		self::store_status(
			array(
				'last_update_at'      => gmdate( 'Y-m-d H:i:s' ),
				'last_update_version' => sanitize_text_field( $version ),
				'last_update_outcome' => $success ? 'success' : 'failed',
			)
		);
	}

	/**
	 * Merge bounded native update status.
	 *
	 * @param array<string, mixed> $changes Status changes.
	 */
	private static function store_status( array $changes ): void {
		$current = get_option( self::STATUS_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		update_option( self::STATUS_OPTION, array_merge( $current, $changes ), false );
	}
}
