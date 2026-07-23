<?php
/**
 * WordPress privacy-tool integration.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and anonymises consent evidence linked to a WordPress user.
 */
final class Privacy {

	/**
	 * Maximum records processed per privacy-tool page.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Register privacy exporters, erasers and suggested policy text.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/**
	 * Register the UCCM personal-data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['uccm-consent-receipts'] = array(
			'exporter_friendly_name' => __( 'UK Cookie Consent Manager receipts', 'uk-cookie-consent-manager' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the UCCM personal-data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['uccm-consent-receipts'] = array(
			'eraser_friendly_name' => __( 'UK Cookie Consent Manager receipts', 'uk-cookie-consent-manager' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export consent evidence associated with the WordPress account for an email.
	 *
	 * Anonymous receipts cannot be attributed to an email and are intentionally
	 * excluded.
	 *
	 * @param string $email_address Privacy request email.
	 * @param int    $page          One-based page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', sanitize_email( $email_address ) );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$table  = Database::table_names()['consents'];
		$query  = $wpdb->prepare(
			"SELECT id, receipt_id, occurred_at, action, choices, policy_version, plugin_version, site_identifier, ip_masked FROM {$table} WHERE wp_user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table and fixed fields.
			$user->ID,
			self::PAGE_SIZE,
			$offset
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared, bounded privacy-tool query.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'uccm-consent-receipts',
				'group_label' => __( 'Cookie consent receipts', 'uk-cookie-consent-manager' ),
				'item_id'     => 'uccm-consent-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Receipt ID', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['receipt_id'],
					),
					array(
						'name'  => __( 'Time (UTC)', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['occurred_at'],
					),
					array(
						'name'  => __( 'Action', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['action'],
					),
					array(
						'name'  => __( 'Category choices', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['choices'],
					),
					array(
						'name'  => __( 'Policy version', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['policy_version'],
					),
					array(
						'name'  => __( 'Plugin version', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['plugin_version'],
					),
					array(
						'name'  => __( 'Site identifier', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['site_identifier'],
					),
					array(
						'name'  => __( 'Masked IP address', 'uk-cookie-consent-manager' ),
						'value' => (string) $row['ip_masked'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Anonymise direct identifiers in receipts linked to a WordPress user.
	 *
	 * Receipt decisions remain as non-attributable compliance evidence until the
	 * configured retention job removes them.
	 *
	 * @param string $email_address Privacy request email.
	 * @param int    $page          One-based page.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', sanitize_email( $email_address ) );

		if ( ! $user instanceof \WP_User ) {
			return self::erasure_result( false, true );
		}

		$page  = max( 1, $page );
		$table = Database::table_names()['consents'];
		$query = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE wp_user_id = %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table and fixed field.
			$user->ID,
			self::PAGE_SIZE
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared, bounded privacy-tool query.
		$rows    = $wpdb->get_results( $query, ARRAY_A );
		$rows    = is_array( $rows ) ? $rows : array();
		$removed = false;

		foreach ( $rows as $row ) {
			$record_id = (int) ( $row['id'] ?? 0 );

			if ( 1 > $record_id ) {
				continue;
			}

			$erased_hash = hash_hmac( 'sha256', 'privacy-erased|' . $record_id, wp_salt( 'auth' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit anonymisation in the plugin-owned receipt table.
			$result      = $wpdb->update(
				$table,
				array(
					'wp_user_id'     => null,
					'ip_masked'      => '',
					'ip_fingerprint' => '',
					'ip_ciphertext'  => null,
					'integrity_hash' => $erased_hash,
				),
				array( 'id' => $record_id )
			);
			$removed = false !== $result || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => array() !== $rows,
			'messages'       => array() === $rows
				? array()
				: array( __( 'Direct identifiers were removed. Non-attributable consent evidence remains until the configured retention period expires.', 'uk-cookie-consent-manager' ) ),
			'done'           => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Add suggested text to the WordPress privacy-policy guide.
	 */
	public static function add_policy_content(): void {
		wp_add_privacy_policy_content(
			__( 'UK Cookie Consent Manager', 'uk-cookie-consent-manager' ),
			wp_kses_post( wpautop( self::policy_text() ) )
		);
	}

	/**
	 * Return the suggested privacy-policy text.
	 */
	public static function policy_text(): string {
		return __( 'This site uses UK Cookie Consent Manager to record cookie choices. Each decision may include a random receipt identifier, UTC time, selected categories, policy and plugin versions, a site identifier, an optional WordPress user ID, a masked IP address and a keyed non-reversible IP fingerprint. Complete IP storage is disabled by default; if enabled by the site operator it is encrypted at rest. Consent records remain on this WordPress site, are not sent to Rushleigh Consulting by default, and are retained for the period configured by the site operator (365 days by default). Logged-in users may request export or erasure through the WordPress privacy tools. Erasure removes account and IP identifiers while non-attributable consent evidence remains until retention cleanup.', 'uk-cookie-consent-manager' );
	}

	/**
	 * Return an empty erasure result.
	 *
	 * @param bool $removed Whether data was removed.
	 * @param bool $done    Whether processing is complete.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	private static function erasure_result( bool $removed, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}
}
