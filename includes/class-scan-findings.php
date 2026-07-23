<?php
/**
 * Scan finding comparison, review workflow and notifications.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Converts scan observations into a bounded human review queue.
 */
final class Scan_Findings {

	/**
	 * Actionable finding states.
	 *
	 * @var string[]
	 */
	private const ACTIONABLE_STATUSES = array( 'pending' );

	/**
	 * Human review outcomes.
	 *
	 * @var string[]
	 */
	private const REVIEW_STATUSES = array( 'reviewed', 'ignored', 'resolved' );

	/**
	 * Fields that can constitute a material observation change.
	 *
	 * @var string[]
	 */
	private const MATERIAL_FIELDS = array( 'duration', 'domain', 'source_url', 'category' );

	/**
	 * Compare observations with curated inventory and store actionable findings.
	 *
	 * @param int                               $run_id       Scan run identifier.
	 * @param array<int, array<string, string>> $observations Normalised scan observations.
	 * @return array{actionable: int, new: int, changed: int, duplicates: int, unchanged: int}
	 */
	public static function process( int $run_id, array $observations ): array {
		global $wpdb;

		$tables = Database::table_names();
		$counts = array(
			'actionable' => 0,
			'new'        => 0,
			'changed'    => 0,
			'duplicates' => 0,
			'unchanged'  => 0,
		);
		$seen   = array();
		$now    = gmdate( 'Y-m-d H:i:s' );

		foreach ( array_slice( $observations, 0, Scanner::MAX_FINDINGS ) as $observation ) {
			$observation = self::summary_fields( $observation );
			$identity    = hash( 'sha256', strtolower( $observation['storage_key'] . '|' . $observation['storage_type'] . '|' . $observation['domain'] . '|' . $observation['source_url'] ) );

			if ( isset( $seen[ $identity ] ) ) {
				++$counts['duplicates'];
				continue;
			}

			$seen[ $identity ] = true;
			$inventory         = self::inventory_match( $observation, $tables['cookie_inventory'] );
			$comparison        = self::compare( $observation, $inventory );

			if ( null === $comparison ) {
				++$counts['unchanged'];
				self::touch_inventory( $inventory, $tables['cookie_inventory'], $now );
				continue;
			}

			$fingerprint = self::fingerprint( $comparison, $observation );

			if ( self::pending_exists( $fingerprint, $tables['scan_findings'] ) ) {
				++$counts['duplicates'];
				self::touch_inventory( $inventory, $tables['cookie_inventory'], $now );
				continue;
			}

			$before_data = wp_json_encode( $comparison['before'] );
			$after_data  = wp_json_encode( $comparison['after'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded insert into the plugin-owned findings table.
			$inserted = $wpdb->insert(
				$tables['scan_findings'],
				array(
					'scan_run_id'  => $run_id,
					'inventory_id' => null === $inventory ? null : (int) $inventory['id'],
					'finding_type' => $comparison['type'],
					'storage_key'  => $observation['storage_key'],
					'domain'       => $observation['domain'],
					'before_data'  => false === $before_data ? '{}' : $before_data,
					'after_data'   => false === $after_data ? '{}' : $after_data,
					'fingerprint'  => $fingerprint,
					'status'       => 'pending',
					'created_at'   => $now,
					'reviewed_at'  => null,
				)
			);

			if ( false !== $inserted ) {
				++$counts['actionable'];
				++$counts[ $comparison['type'] ];
			}

			self::touch_inventory( $inventory, $tables['cookie_inventory'], $now );
		}

		if ( 0 < $counts['actionable'] ) {
			self::notify_administrators( $run_id, $counts );
		}

		return $counts;
	}

	/**
	 * Compare an observation with an optional inventory record.
	 *
	 * @param array<string, string>     $observation Normalised observation.
	 * @param array<string, mixed>|null $inventory   Curated inventory record.
	 * @return array{type: string, before: array<string, string>, after: array<string, string>}|null
	 */
	public static function compare( array $observation, ?array $inventory ): ?array {
		$after = self::summary_fields( $observation );

		if ( null === $inventory ) {
			if ( '' !== $after['category_candidate'] ) {
				$after['category'] = $after['category_candidate'];
			}
			unset( $after['category_candidate'] );

			return array(
				'type'   => 'new',
				'before' => array(),
				'after'  => $after,
			);
		}

		$before  = array();
		$changed = array();

		foreach ( self::MATERIAL_FIELDS as $field ) {
			$inventory_field   = 'category' === $field ? 'category' : $field;
			$observation_field = 'category' === $field ? 'category_candidate' : $field;
			$old_value         = (string) ( $inventory[ $inventory_field ] ?? '' );
			$new_value         = (string) ( $observation[ $observation_field ] ?? '' );

			if ( '' === $new_value || $old_value === $new_value ) {
				continue;
			}

			$before[ $field ]  = $old_value;
			$changed[ $field ] = $new_value;
		}

		if ( array() === $changed ) {
			return null;
		}

		return array(
			'type'   => 'changed',
			'before' => $before,
			'after'  => $changed,
		);
	}

	/**
	 * Return a bounded findings list for the administration screen.
	 *
	 * @param int $scan_run_id Optional scan filter.
	 * @param int $limit       Maximum rows.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function records( int $scan_run_id = 0, int $limit = 100 ): array|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to view scan findings.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$table = Database::table_names()['scan_findings'];
		$limit = min( 200, max( 1, $limit ) );

		if ( 0 < $scan_run_id ) {
			$sql = $wpdb->prepare(
				"SELECT id, scan_run_id, inventory_id, finding_type, storage_key, domain, before_data, after_data, status, created_at, reviewed_at FROM {$table} WHERE scan_run_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
				$scan_run_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, scan_run_id, inventory_id, finding_type, storage_key, domain, before_data, after_data, status, created_at, reviewed_at FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared above and reads a bounded plugin-owned table.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Apply an explicit human review outcome without changing inventory content.
	 *
	 * @param int    $finding_id Finding identifier.
	 * @param string $status     Reviewed, ignored or resolved.
	 * @return bool|\WP_Error
	 */
	public static function review( int $finding_id, string $status ): bool|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'manage_uccm_inventory' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to review scan findings.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$status = sanitize_key( $status );

		if ( 1 > $finding_id || ! in_array( $status, self::REVIEW_STATUSES, true ) ) {
			return new \WP_Error( 'uccm_invalid_finding_review', __( 'The scan finding review request is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$table = Database::table_names()['scan_findings'];
		$sql   = $wpdb->prepare(
			"UPDATE {$table} SET status = %s, reviewed_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
			$status,
			gmdate( 'Y-m-d H:i:s' ),
			$finding_id,
			'pending'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared above and performs a capability- and nonce-gated update.
		$updated = $wpdb->query( $sql );

		if ( false === $updated ) {
			return new \WP_Error( 'uccm_finding_not_reviewed', __( 'The scan finding could not be reviewed.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		if ( 0 === $updated ) {
			return new \WP_Error( 'uccm_finding_not_pending', __( 'The scan finding is no longer pending review.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Return material field names for rendering a safe diff.
	 *
	 * @return string[]
	 */
	public static function material_fields(): array {
		return self::MATERIAL_FIELDS;
	}

	/**
	 * Find the closest curated inventory record by key and storage type.
	 *
	 * @param array<string, string> $observation Observation summary.
	 * @param string                $table       Inventory table.
	 * @return array<string, mixed>|null
	 */
	private static function inventory_match( array $observation, string $table ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT id, storage_key, domain, storage_type, category, duration, source_url FROM {$table} WHERE storage_key = %s AND storage_type = %s ORDER BY (domain = %s) DESC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
			$observation['storage_key'],
			$observation['storage_type'],
			$observation['domain']
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared above and performs a bounded plugin-table lookup.
		$records = $wpdb->get_results( $sql, ARRAY_A );

		return isset( $records[0] ) && is_array( $records[0] ) ? $records[0] : null;
	}

	/**
	 * Detect an existing identical actionable finding.
	 *
	 * @param string $fingerprint Deterministic finding fingerprint.
	 * @param string $table       Findings table.
	 */
	private static function pending_exists( string $fingerprint, string $table ): bool {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::ACTIONABLE_STATUSES ), '%s' ) );
		$arguments    = array_merge( array( $fingerprint ), self::ACTIONABLE_STATUSES );
		$sql          = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE fingerprint = %s AND status IN ({$placeholders}) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table and static placeholders.
			...$arguments
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared above and performs an exact bounded lookup.
		return null !== $wpdb->get_var( $sql );
	}

	/**
	 * Update observation time only; never alter reviewed classifications.
	 *
	 * @param array<string, mixed>|null $inventory Inventory record.
	 * @param string                    $table     Inventory table.
	 * @param string                    $now       Current UTC database timestamp.
	 */
	private static function touch_inventory( ?array $inventory, string $table, string $now ): void {
		global $wpdb;

		if ( null === $inventory || 1 > (int) ( $inventory['id'] ?? 0 ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates observation metadata only in a plugin-owned table.
		$wpdb->update(
			$table,
			array( 'last_seen_at' => $now ),
			array( 'id' => (int) $inventory['id'] )
		);
	}

	/**
	 * Create a deterministic fingerprint for duplicate suppression.
	 *
	 * @param array{type: string, before: array<string, string>, after: array<string, string>} $comparison Comparison result.
	 * @param array<string, string>                                                            $observation Observation summary.
	 */
	private static function fingerprint( array $comparison, array $observation ): string {
		$data = array(
			'type'         => $comparison['type'],
			'storage_key'  => $observation['storage_key'],
			'storage_type' => $observation['storage_type'],
			'before'       => $comparison['before'],
			'after'        => $comparison['after'],
		);

		return hash( 'sha256', strtolower( (string) wp_json_encode( $data ) ) );
	}

	/**
	 * Keep only bounded, non-content observation metadata.
	 *
	 * @param array<string, string> $observation Observation.
	 * @return array<string, string>
	 */
	private static function summary_fields( array $observation ): array {
		$category_candidate = sanitize_key( (string) ( $observation['category_candidate'] ?? '' ) );

		if ( ! in_array( $category_candidate, array( 'necessary', 'functional', 'analytics', 'marketing' ), true ) ) {
			$category_candidate = '';
		}

		return array(
			'storage_key'        => substr( sanitize_text_field( (string) ( $observation['storage_key'] ?? '' ) ), 0, 191 ),
			'domain'             => strtolower( substr( sanitize_text_field( (string) ( $observation['domain'] ?? '' ) ), 0, 191 ) ),
			'storage_type'       => sanitize_key( (string) ( $observation['storage_type'] ?? 'other' ) ),
			'duration'           => substr( sanitize_text_field( (string) ( $observation['duration'] ?? '' ) ), 0, 100 ),
			'source_url'         => esc_url_raw( (string) ( $observation['source_url'] ?? '' ) ),
			'category_candidate' => $category_candidate,
		);
	}

	/**
	 * Send one summary-only notification for an actionable scan.
	 *
	 * @param int                $run_id Scan run identifier.
	 * @param array<string, int> $counts Finding counts.
	 */
	private static function notify_administrators( int $run_id, array $counts ): void {
		$default_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		$recipients    = '' === $default_email ? array() : array( $default_email );

		/**
		 * Filters administrator recipients for actionable scan summaries.
		 *
		 * @param string[] $recipients Recipient email addresses.
		 * @param int      $run_id     Scan run identifier.
		 */
		$recipients = apply_filters( 'uccm_scan_notification_recipients', $recipients, $run_id );
		$recipients = array_slice( array_unique( array_filter( array_map( 'sanitize_email', $recipients ) ) ), 0, 50 );

		if ( array() === $recipients ) {
			return;
		}

		$link    = admin_url( 'admin.php?page=uccm-scans&scan_id=' . $run_id );
		$subject = sprintf(
			/* translators: %d: actionable finding count. */
			__( 'Cookie scan found %d item(s) requiring review', 'uk-cookie-consent-manager' ),
			$counts['actionable']
		);
		$message = sprintf(
			/* translators: 1: scan ID, 2: new count, 3: changed count, 4: administration URL. */
			__( "Scan %1\$d created %2\$d new and %3\$d changed finding(s).\n\nReview summary: %4\$s", 'uk-cookie-consent-manager' ),
			$run_id,
			$counts['new'],
			$counts['changed'],
			$link
		);

		wp_mail( $recipients, $subject, $message );
	}
}
