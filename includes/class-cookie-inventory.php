<?php
/**
 * Curated cookie and storage inventory.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, stores, filters and exports the administrator-reviewed inventory.
 */
final class Cookie_Inventory {

	/**
	 * Supported party classifications.
	 *
	 * @var string[]
	 */
	private const PARTIES = array( 'first-party', 'third-party' );

	/**
	 * Supported browser storage types.
	 *
	 * @var string[]
	 */
	private const STORAGE_TYPES = array( 'cookie', 'local_storage', 'session_storage', 'other' );

	/**
	 * Supported consent categories.
	 *
	 * @var string[]
	 */
	private const CATEGORIES = array( 'necessary', 'functional', 'analytics', 'marketing' );

	/**
	 * Supported review statuses.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'known', 'new', 'changed', 'ignored', 'resolved' );

	/**
	 * Validate and normalise one inventory entry.
	 *
	 * @param array<string, mixed> $input Untrusted inventory values.
	 * @return array<string, string>|\WP_Error
	 */
	public static function validate( array $input ): array|\WP_Error {
		$storage_key  = substr( sanitize_text_field( (string) ( $input['storage_key'] ?? '' ) ), 0, 191 );
		$domain       = strtolower( substr( sanitize_text_field( (string) ( $input['domain'] ?? '' ) ), 0, 191 ) );
		$provider     = substr( sanitize_text_field( (string) ( $input['provider'] ?? '' ) ), 0, 191 );
		$party        = sanitize_key( (string) ( $input['party'] ?? '' ) );
		$storage_type = sanitize_key( (string) ( $input['storage_type'] ?? '' ) );
		$purpose      = sanitize_textarea_field( (string) ( $input['purpose'] ?? '' ) );
		$category     = sanitize_key( (string) ( $input['category'] ?? '' ) );
		$duration     = substr( sanitize_text_field( (string) ( $input['duration'] ?? '' ) ), 0, 100 );
		$source_url   = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
		$status       = sanitize_key( (string) ( $input['status'] ?? '' ) );

		if ( '' === $storage_key ) {
			return new \WP_Error( 'uccm_inventory_key_required', __( 'A cookie or storage key name is required.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $party, self::PARTIES, true ) ) {
			return new \WP_Error( 'uccm_inventory_invalid_party', __( 'The party classification is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $storage_type, self::STORAGE_TYPES, true ) ) {
			return new \WP_Error( 'uccm_inventory_invalid_storage_type', __( 'The storage type is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return new \WP_Error( 'uccm_inventory_invalid_category', __( 'The consent category is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'uccm_inventory_invalid_status', __( 'The review status is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		return array(
			'storage_key'  => $storage_key,
			'domain'       => $domain,
			'provider'     => $provider,
			'party'        => $party,
			'storage_type' => $storage_type,
			'purpose'      => $purpose,
			'category'     => $category,
			'duration'     => $duration,
			'source_url'   => $source_url,
			'status'       => $status,
			'fingerprint'  => hash( 'sha256', strtolower( $storage_key . '|' . $domain . '|' . $storage_type ) ),
		);
	}

	/**
	 * Insert or update one reviewed inventory item.
	 *
	 * @param array<string, mixed> $input Untrusted inventory values.
	 * @return int|\WP_Error Database ID or error.
	 */
	public static function save( array $input ): int|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'manage_uccm_inventory' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to manage the cookie inventory.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$validated = self::validate( $input );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$table = Database::table_names()['cookie_inventory'];
		$id    = max( 0, (int) ( $input['id'] ?? 0 ) );
		$now   = gmdate( 'Y-m-d H:i:s' );

		if ( 0 < $id ) {
			$validated['last_reviewed_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Capability-gated update to the plugin-owned inventory table.
			$updated = $wpdb->update( $table, $validated, array( 'id' => $id ) );

			return false === $updated
				? new \WP_Error( 'uccm_inventory_not_saved', __( 'The inventory item could not be updated.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) )
				: $id;
		}

		$validated['first_seen_at']    = $now;
		$validated['last_seen_at']     = $now;
		$validated['last_reviewed_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Capability-gated insert into the plugin-owned inventory table.
		$inserted = $wpdb->insert( $table, $validated );

		return false === $inserted
			? new \WP_Error( 'uccm_inventory_not_saved', __( 'The inventory item could not be created.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) )
			: (int) $wpdb->insert_id;
	}

	/**
	 * Return a capability-gated, filtered and bounded inventory page.
	 *
	 * @param array<string, mixed> $filters  Category, status and search filters.
	 * @param int                  $page     One-based page.
	 * @param int                  $per_page Items per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}|\WP_Error
	 */
	public static function records( array $filters = array(), int $page = 1, int $per_page = 20 ): array|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'manage_uccm_inventory' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to view the cookie inventory.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$page                  = max( 1, $page );
		$per_page              = max( 1, min( 100, $per_page ) );
		$offset                = ( $page - 1 ) * $per_page;
		[ $where, $arguments ] = self::where_clause( $filters, $wpdb );
		$table                 = Database::table_names()['cookie_inventory'];
		$count_sql             = "SELECT COUNT(*) FROM {$table} WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and generated clauses are plugin-owned.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is generated internally and values are prepared when present.
		$count_query = empty( $arguments ) ? $count_sql : $wpdb->prepare( $count_sql, ...$arguments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded read from the plugin-owned inventory table.
		$total = (int) $wpdb->get_var( $count_query );

		$data_sql       = "SELECT id, storage_key, domain, provider, party, storage_type, purpose, category, duration, source_url, first_seen_at, last_seen_at, last_reviewed_at, status FROM {$table} WHERE {$where} ORDER BY last_seen_at DESC, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and generated clauses are plugin-owned.
		$data_arguments = array_merge( $arguments, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is generated internally; pagination and filter values use placeholders.
		$data_query = $wpdb->prepare( $data_sql, ...$data_arguments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded read from the plugin-owned inventory table.
		$items = $wpdb->get_results( $data_query, ARRAY_A );

		return array(
			'items'    => is_array( $items ) ? $items : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Return up to 5,000 records for a filtered CSV export.
	 *
	 * @param array<string, mixed> $filters Category, status and search filters.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function export_records( array $filters = array() ): array|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'manage_uccm_inventory' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to export the cookie inventory.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		[ $where, $arguments ] = self::where_clause( $filters, $wpdb );
		$table                 = Database::table_names()['cookie_inventory'];
		$sql                   = "SELECT storage_key, provider, domain, party, storage_type, purpose, category, duration, source_url, first_seen_at, last_seen_at, last_reviewed_at, status FROM {$table} WHERE {$where} ORDER BY storage_key ASC LIMIT 5000"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and generated clauses are plugin-owned.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is generated internally and values are prepared when present.
		$query = empty( $arguments ) ? $sql : $wpdb->prepare( $sql, ...$arguments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded export from the plugin-owned inventory table.
		$items = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Encode inventory rows as CSV and neutralise spreadsheet formula prefixes.
	 *
	 * @param array<int, array<string, mixed>> $records Inventory records.
	 */
	public static function csv( array $records ): string {
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for a generated download.

		if ( false === $stream ) {
			return '';
		}

		$headers = array( 'Name', 'Provider', 'Domain', 'Party', 'Storage type', 'Purpose', 'Category', 'Duration', 'Source', 'First seen', 'Last seen', 'Last reviewed', 'Status' );
		fputcsv( $stream, $headers, ',', '"', '\\' );

		foreach ( $records as $record ) {
			$row = array();

			foreach ( array_keys( $record ) as $field ) {
				$row[] = self::csv_cell( (string) $record[ $field ] );
			}

			fputcsv( $stream, $row, ',', '"', '\\' );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the in-memory stream.
		return false === $csv ? '' : $csv;
	}

	/**
	 * Make one value safe for spreadsheet applications.
	 *
	 * @param string $value Exported value.
	 */
	public static function csv_cell( string $value ): string {
		return 1 === preg_match( '/^\s*[=+\-@]/u', $value ) ? "'" . $value : $value;
	}

	/**
	 * Build an allowlisted SQL filter clause.
	 *
	 * @param array<string, mixed> $filters  Untrusted filters.
	 * @param \wpdb                $database WordPress database abstraction.
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function where_clause( array $filters, \wpdb $database ): array {
		$clauses   = array( '1=1' );
		$arguments = array();
		$category  = sanitize_key( (string) ( $filters['category'] ?? '' ) );
		$status    = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		$search    = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );

		if ( in_array( $category, self::CATEGORIES, true ) ) {
			$clauses[]   = 'category = %s';
			$arguments[] = $category;
		}

		if ( in_array( $status, self::STATUSES, true ) ) {
			$clauses[]   = 'status = %s';
			$arguments[] = $status;
		}

		if ( '' !== $search ) {
			$like        = '%' . $database->esc_like( $search ) . '%';
			$clauses[]   = '(storage_key LIKE %s OR provider LIKE %s OR domain LIKE %s)';
			$arguments[] = $like;
			$arguments[] = $like;
			$arguments[] = $like;
		}

		return array( implode( ' AND ', $clauses ), $arguments );
	}
}
