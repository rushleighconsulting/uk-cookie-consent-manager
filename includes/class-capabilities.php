<?php
/**
 * Plugin capabilities.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin capability set.
 */
final class Capabilities {

	/**
	 * Current capability-set version.
	 */
	private const VERSION = '1.0.0';

	/**
	 * Option containing the installed capability-set version.
	 */
	private const VERSION_OPTION = 'uccm_capabilities_version';

	/**
	 * Capabilities granted to site administrators.
	 */
	private const CAPABILITIES = array(
		'manage_uccm_settings',
		'manage_uccm_inventory',
		'run_uccm_scans',
		'view_uccm_consents',
		'export_uccm_consents',
		'erase_uccm_consents',
	);

	/**
	 * Return every capability owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return self::CAPABILITIES;
	}

	/**
	 * Restore capabilities when the capability set changes.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( self::VERSION !== $installed_version ) {
			self::grant();
		}
	}

	/**
	 * Grant plugin capabilities to the site administrator role.
	 */
	public static function grant(): void {
		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		foreach ( self::CAPABILITIES as $capability ) {
			$administrator->add_cap( $capability );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Remove plugin capabilities from the site administrator role.
	 */
	public static function revoke(): void {
		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			foreach ( self::CAPABILITIES as $capability ) {
				$administrator->remove_cap( $capability );
			}
		}

		delete_option( self::VERSION_OPTION );
	}
}
