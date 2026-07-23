<?php
/**
 * Plugin lifecycle operations.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation and deactivation.
 */
final class Activator {

	/**
	 * Prepare a clean installation or version upgrade.
	 */
	public static function activate(): void {
		update_option( 'uccm_version', UCCM_VERSION, false );
	}

	/**
	 * Remove scheduled work without deleting retained data.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'uccm_monthly_scan' );
		wp_clear_scheduled_hook( 'uccm_retention_cleanup' );
	}
}
