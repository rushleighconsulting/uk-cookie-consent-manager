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
			self::for_each_site( array( self::class, 'install_current_site' ) );
			return;
		}

		self::install_current_site();
	}

	/**
	 * Install the foundation for the current site.
	 */
	public static function install_current_site(): void {
		Database::install();
		Capabilities::grant();
		Consent_Receipts::schedule_cleanup();

		add_option(
			'uccm_settings',
			array(
				'consent_lifetime_days'  => 180,
				'consent_policy_version' => Consent_State::POLICY_VERSION,
				'retention_days'      => 365,
				'store_full_ip'       => false,
				'trust_proxy_headers' => false,
				'trusted_proxy_ips'   => array(),
			),
			'',
			false
		);
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
			self::for_each_site( array( self::class, 'clear_scheduled_work' ) );
			return;
		}

		self::clear_scheduled_work();
	}

	/**
	 * Install the foundation when a site is added to an active network.
	 *
	 * @param \WP_Site $site Newly initialized site.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}

		self::with_site( (int) $site->blog_id, array( self::class, 'install_current_site' ) );
	}

	/**
	 * Clear the plugin's recurring events for the current site.
	 */
	private static function clear_scheduled_work(): void {
		wp_clear_scheduled_hook( 'uccm_monthly_scan' );
		wp_clear_scheduled_hook( 'uccm_retention_cleanup' );
	}

	/**
	 * Run an operation on every existing site.
	 *
	 * @param callable $operation Operation to run after switching site.
	 */
	private static function for_each_site( callable $operation ): void {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			self::with_site( (int) $site_id, $operation );
		}
	}

	/**
	 * Run an operation in a site context and always restore the original site.
	 *
	 * @param int      $site_id   Site ID.
	 * @param callable $operation Operation to run.
	 */
	private static function with_site( int $site_id, callable $operation ): void {
		switch_to_blog( $site_id );

		try {
			$operation();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Determine whether this plugin is active for the network.
	 */
	private static function is_network_active(): bool {
		$active_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

		return isset( $active_plugins[ plugin_basename( UCCM_PLUGIN_FILE ) ] );
	}
}
