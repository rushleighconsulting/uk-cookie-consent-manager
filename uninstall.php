<?php
/**
 * Optional plugin data cleanup.
 *
 * @package UCCM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'uccm_delete_data_on_uninstall', false ) ) {
	return;
}

wp_clear_scheduled_hook( 'uccm_monthly_scan' );
wp_clear_scheduled_hook( 'uccm_retention_cleanup' );

delete_option( 'uccm_version' );
delete_option( 'uccm_settings' );
delete_option( 'uccm_delete_data_on_uninstall' );
