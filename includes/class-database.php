<?php
/**
 * Database schema and migrations.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin database schema.
 */
final class Database {

	/**
	 * Current schema version.
	 */
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option containing the installed schema version.
	 */
	private const VERSION_OPTION = 'uccm_schema_version';

	/**
	 * Table suffixes owned by the plugin.
	 */
	private const TABLE_SUFFIXES = array(
		'consents',
		'cookie_inventory',
		'scan_runs',
		'scan_findings',
	);

	/**
	 * Upgrade the current site when its schema is stale.
	 */
	public static function maybe_upgrade(): void {
		$installed_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( self::SCHEMA_VERSION !== $installed_version ) {
			self::install();
		}
	}

	/**
	 * Create or update all plugin tables for the current site.
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema( $wpdb ) );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Return the fully prefixed table names.
	 *
	 * @param string|null $prefix Optional table prefix.
	 * @return array<string, string>
	 */
	public static function table_names( ?string $prefix = null ): array {
		global $wpdb;

		$table_prefix = null === $prefix ? $wpdb->prefix : $prefix;
		$names        = array();

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$names[ $suffix ] = $table_prefix . 'uccm_' . $suffix;
		}

		return $names;
	}

	/**
	 * Build dbDelta-compatible schema statements.
	 *
	 * @param \wpdb $database WordPress database abstraction.
	 * @return string[]
	 */
	public static function schema( \wpdb $database ): array {
		$tables            = self::table_names( $database->prefix );
		$charset_collation = $database->get_charset_collate();

		return array(
			"CREATE TABLE {$tables['consents']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				receipt_id char(36) NOT NULL,
				occurred_at datetime NOT NULL,
				action varchar(20) NOT NULL,
				choices longtext NOT NULL,
				policy_version varchar(40) NOT NULL,
				plugin_version varchar(40) NOT NULL,
				site_identifier char(64) NOT NULL,
				wp_user_id bigint(20) unsigned NULL,
				ip_masked varchar(45) NOT NULL DEFAULT '',
				ip_fingerprint char(64) NOT NULL DEFAULT '',
				ip_ciphertext longtext NULL,
				integrity_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY receipt_id (receipt_id),
				KEY occurred_at (occurred_at),
				KEY action (action),
				KEY ip_fingerprint (ip_fingerprint)
			) {$charset_collation};",
			"CREATE TABLE {$tables['cookie_inventory']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				storage_key varchar(191) NOT NULL,
				domain varchar(191) NOT NULL DEFAULT '',
				provider varchar(191) NOT NULL DEFAULT '',
				party varchar(20) NOT NULL,
				storage_type varchar(20) NOT NULL,
				purpose text NOT NULL,
				category varchar(20) NOT NULL,
				duration varchar(100) NOT NULL DEFAULT '',
				source_url text NOT NULL,
				first_seen_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				last_reviewed_at datetime NULL,
				status varchar(20) NOT NULL,
				fingerprint char(64) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY fingerprint (fingerprint),
				KEY category (category),
				KEY status (status),
				KEY last_seen_at (last_seen_at)
			) {$charset_collation};",
			"CREATE TABLE {$tables['scan_runs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(20) NOT NULL,
				methods longtext NOT NULL,
				coverage longtext NOT NULL,
				pages_visited longtext NOT NULL,
				summary longtext NOT NULL,
				error_code varchar(50) NOT NULL DEFAULT '',
				started_at datetime NOT NULL,
				completed_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY started_at (started_at)
			) {$charset_collation};",
			"CREATE TABLE {$tables['scan_findings']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_run_id bigint(20) unsigned NOT NULL,
				inventory_id bigint(20) unsigned NULL,
				finding_type varchar(20) NOT NULL,
				storage_key varchar(191) NOT NULL,
				domain varchar(191) NOT NULL DEFAULT '',
				before_data longtext NOT NULL,
				after_data longtext NOT NULL,
				fingerprint char(64) NOT NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				reviewed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY scan_run_id (scan_run_id),
				KEY inventory_id (inventory_id),
				KEY status (status),
				KEY fingerprint (fingerprint)
			) {$charset_collation};",
		);
	}
}
