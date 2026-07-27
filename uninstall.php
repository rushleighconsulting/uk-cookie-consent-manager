<?php
/**
 * Optional plugin data cleanup.
 *
 * @package UCCM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-multisite.php';
require_once __DIR__ . '/includes/class-settings.php';

/**
 * Remove all UCCM data for the current site when explicitly requested.
 *
 * @param bool $approved Whether the correct administrator explicitly approved deletion.
 */
function uccm_uninstall_current_site( bool $approved ): void {
	if ( ! $approved ) {
		return;
	}

	global $wpdb;

	wp_clear_scheduled_hook( 'uccm_monthly_scan' );
	wp_clear_scheduled_hook( 'uccm_scan_batch' );
	wp_clear_scheduled_hook( 'uccm_retention_cleanup' );

	foreach ( UCCM\Database::table_names() as $table_name ) {
		// The table name is composed exclusively from the trusted WordPress prefix and a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	UCCM\Capabilities::revoke();

	delete_option( 'uccm_version' );
	delete_option( 'uccm_schema_version' );
	delete_option( 'uccm_settings' );
	delete_option( 'uccm_network_overrides' );
	delete_option( 'uccm_update_credential' );
	delete_option( 'uccm_update_status' );
	delete_option( 'uccm_post_password' );
	delete_option( 'uccm_operational_alerts' );
	delete_site_transient( 'uccm_update_manifest' );
	delete_option( 'uccm_delete_data_on_uninstall' );
}

if ( is_multisite() ) {
	$uccm_network_delete_approved = (bool) get_site_option( UCCM\Multisite::DELETE_OPTION, false );

	if ( $uccm_network_delete_approved ) {
		$uccm_offset = 0;

		do {
			$uccm_site_ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => UCCM\Multisite::BATCH_SIZE,
					'offset'  => $uccm_offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			$uccm_site_ids = is_array( $uccm_site_ids ) ? $uccm_site_ids : array();

			foreach ( $uccm_site_ids as $uccm_site_id ) {
				switch_to_blog( (int) $uccm_site_id );

				try {
					uccm_uninstall_current_site( true );
				} finally {
					restore_current_blog();
				}
			}

			$uccm_offset += count( $uccm_site_ids );
		} while ( UCCM\Multisite::BATCH_SIZE === count( $uccm_site_ids ) );

		delete_site_option( UCCM\Multisite::SETTINGS_OPTION );
		delete_site_option( UCCM\Multisite::STATE_OPTION );
		delete_site_option( UCCM\Multisite::VERSION_OPTION );
		delete_site_option( UCCM\Multisite::DELETE_OPTION );
		delete_site_transient( 'uccm_network_install_lock' );
		delete_site_transient( 'uccm_update_manifest' );
	}
} else {
	uccm_uninstall_current_site( (bool) get_option( 'uccm_delete_data_on_uninstall', false ) );
}
