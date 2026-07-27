<?php
/**
 * Plugin lifecycle operations.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation, deactivation and multisite initialization.
 */
final class Activator {

	/**
	 * Prepare a clean installation or version upgrade.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			Multisite::activate_network();
			return;
		}

		self::install_current_site( false );
	}

	/**
	 * Install the foundation for the current site.
	 *
	 * @param bool $network_managed Whether network inheritance should be initialized.
	 */
	public static function install_current_site( bool $network_managed = false ): void {
		Database::install();
		Capabilities::grant();
		Consent_Receipts::schedule_cleanup();
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Scanner supplies the bounded 30-day interval.
		add_filter( 'cron_schedules', array( Scanner::class, 'cron_schedules' ) );
		Scanner::schedule();

		$stored_settings = get_option( Settings::OPTION_NAME, null );

		if ( ! is_array( $stored_settings ) ) {
			add_option( Settings::OPTION_NAME, Settings::installation_defaults( $network_managed ), '', false );

			if ( $network_managed ) {
				add_option( Settings::OVERRIDES_OPTION, array(), '', false );
			}
		} elseif ( $network_managed && ! is_array( get_option( Settings::OVERRIDES_OPTION, null ) ) ) {
			$existing_overrides = array_values( array_intersect( array_keys( $stored_settings ), Multisite::manageable_settings() ) );
			add_option( Settings::OVERRIDES_OPTION, $existing_overrides, '', false );
		}

		add_option( 'uccm_delete_data_on_uninstall', false, '', false );
		update_option( 'uccm_version', UCCM_VERSION, false );
	}

	/**
	 * Remove scheduled work without deleting retained data.
	 *
	 * @param bool $network_wide Whether the plugin is network deactivated.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			Multisite::deactivate_network();
			return;
		}

		self::clear_scheduled_work();
	}

	/**
	 * Clear the plugin's recurring events for the current site.
	 */
	public static function clear_scheduled_work(): void {
		wp_clear_scheduled_hook( 'uccm_monthly_scan' );
		wp_clear_scheduled_hook( Scanner::BATCH_HOOK );
		wp_clear_scheduled_hook( 'uccm_retention_cleanup' );
	}
}
