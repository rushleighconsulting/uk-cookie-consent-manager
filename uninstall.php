<?php
/**
 * Optional plugin data cleanup.
 *
 * @package UCCM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-capabilities.php';

/**
 * Remove all UCCM data for the current site when explicitly requested.
 */
function uccm_uninstall_current_site(): void {
	if ( ! get_option( 'uccm_delete_data_on_uninstall', false ) ) {
		return;
	}

	global $wpdb;

	wp_clear_scheduled_hook( 'uccm_monthly_scan' );
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
	delete_option( 'uccm_update_credential' );
	delete_option( 'uccm_post_password' );
	delete_site_transient( 'uccm_update_manifest' );
	delete_option( 'uccm_delete_data_on_uninstall' );
}

if ( is_multisite() ) {
	$uccm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $uccm_site_ids as $uccm_site_id ) {
		switch_to_blog( (int) $uccm_site_id );

		try {
			uccm_uninstall_current_site();
		} finally {
			restore_current_blog();
		}
	}
} else {
	uccm_uninstall_current_site();
}
