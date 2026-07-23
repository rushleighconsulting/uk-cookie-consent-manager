<?php
/**
 * Append-only consent receipt storage and access.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Records versioned visitor decisions with privacy-preserving evidence.
 */
final class Consent_Receipts {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'uccm/v1';

	/**
	 * Register runtime hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'uccm_retention_cleanup', array( self::class, 'run_cleanup' ) );
		self::schedule_cleanup();
	}

	/**
	 * Register public receipt creation and capability-gated evidence routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/consents',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create_response' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'list_response' ),
					'permission_callback' => array( self::class, 'can_view' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/consents/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'export_response' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/consents/(?P<id>\d+)/ip',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'reveal_response' ),
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);
	}

	/**
	 * Schedule daily retention cleanup when it is not already scheduled.
	 */
	public static function schedule_cleanup(): void {
		if ( false === wp_next_scheduled( 'uccm_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'uccm_retention_cleanup' );
		}
	}

	/**
	 * Store one validated append-only receipt.
	 *
	 * @param array<string, mixed> $payload Browser decision payload.
	 * @param string|null          $ip      Optional validated source for testing.
	 * @return array{receiptId: string, status: string}|\WP_Error
	 */
	public static function record( array $payload, ?string $ip = null ): array|\WP_Error {
		global $wpdb;

		$receipt_id = isset( $payload['receiptId'] ) ? (string) $payload['receiptId'] : '';
		$action     = isset( $payload['action'] ) ? (string) $payload['action'] : '';
		$policy     = isset( $payload['policyVersion'] ) ? (string) $payload['policyVersion'] : '';
		$choices    = isset( $payload['categories'] ) && is_array( $payload['categories'] )
			? self::normalise_choices( $payload['categories'] )
			: null;

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{16,64}$/', $receipt_id ) ) {
			return new \WP_Error( 'uccm_invalid_receipt_id', __( 'The consent receipt identifier is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $action, array( 'grant', 'reject', 'update', 'withdraw' ), true ) ) {
			return new \WP_Error( 'uccm_invalid_action', __( 'The consent action is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$policy = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $policy );

		if ( '' === $policy || null === $choices ) {
			return new \WP_Error( 'uccm_invalid_decision', __( 'The consent decision is incomplete.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$occurred_at = gmdate( 'Y-m-d H:i:s' );
		$ip_data     = IP_Privacy::protect( null === $ip ? IP_Privacy::client_ip() : $ip );
		$encoded     = wp_json_encode( $choices );

		if ( false === $encoded ) {
			return new \WP_Error( 'uccm_invalid_choices', __( 'The consent choices could not be encoded.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$row                   = array(
			'receipt_id'      => $receipt_id,
			'occurred_at'     => $occurred_at,
			'action'          => $action,
			'choices'         => $encoded,
			'policy_version'  => $policy,
			'plugin_version'  => UCCM_VERSION,
			'site_identifier' => self::site_identifier(),
			'wp_user_id'      => self::current_user_id(),
			'ip_masked'       => $ip_data['masked'],
			'ip_fingerprint'  => $ip_data['fingerprint'],
			'ip_ciphertext'   => $ip_data['ciphertext'],
			'integrity_hash'  => '',
			'created_at'      => $occurred_at,
		);
		$row['integrity_hash'] = self::integrity_hash( $row );

		$table = Database::table_names()['consents'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only write to the plugin-owned receipt table.
		$stored = $wpdb->insert(
			$table,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $stored ) {
			return new \WP_Error( 'uccm_receipt_not_stored', __( 'The consent receipt could not be stored.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		do_action( 'uccm_consent_receipt_stored', $receipt_id, $action );

		return array(
			'receiptId' => $receipt_id,
			'status'    => 'stored',
		);
	}

	/**
	 * Public REST callback for creating a receipt.
	 *
	 * @param \WP_REST_Request $request Receipt creation request.
	 */
	public static function create_response( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = self::record( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
	}

	/**
	 * Capability-gated REST callback for masked receipt listings.
	 *
	 * @param \WP_REST_Request $request Receipt listing request.
	 */
	public static function list_response( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$limit   = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		$records = self::records( 'view_uccm_consents', $limit, false );
		return is_wp_error( $records ) ? $records : new \WP_REST_Response( $records, 200 );
	}

	/**
	 * Capability-gated REST callback for evidence exports without complete IPs.
	 *
	 * @param \WP_REST_Request $request Receipt export request.
	 */
	public static function export_response( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		unset( $request );
		$records = self::records( 'export_uccm_consents', 1000, true );

		if ( is_wp_error( $records ) ) {
			return $records;
		}

		$response = new \WP_REST_Response( $records, 200 );
		$response->header( 'Content-Disposition', 'attachment; filename="uccm-consent-records.json"' );
		return $response;
	}

	/**
	 * Capability-gated REST callback for revealing one encrypted complete IP.
	 *
	 * @param \WP_REST_Request $request Complete-IP reveal request.
	 */
	public static function reveal_response( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = self::reveal_ip( (int) $request->get_param( 'id' ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'ip' => $result ), 200 );
	}

	/**
	 * List or export bounded receipt evidence after an explicit capability check.
	 *
	 * @param string $capability        Required capability.
	 * @param int    $limit             Maximum records to return.
	 * @param bool   $include_integrity Whether integrity evidence is included.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function records( string $capability, int $limit, bool $include_integrity ): array|\WP_Error {
		global $wpdb;

		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to access consent records.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$limit  = max( 1, min( 1000, $limit ) );
		$table  = Database::table_names()['consents'];
		$fields = 'id, receipt_id, occurred_at, action, choices, policy_version, plugin_version, site_identifier, wp_user_id, ip_masked';

		if ( $include_integrity ) {
			$fields .= ', ip_fingerprint, integrity_hash';
		}

		$query = $wpdb->prepare( "SELECT {$fields} FROM {$table} ORDER BY id DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and field names are plugin-owned constants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared, bounded read from the plugin-owned receipt table.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reveal a complete encrypted IP only to consent-record viewers.
	 *
	 * @param int $record_id Consent receipt database ID.
	 * @return string|\WP_Error
	 */
	public static function reveal_ip( int $record_id ): string|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'view_uccm_consents' ) ) {
			return new \WP_Error( 'uccm_forbidden', __( 'You are not allowed to reveal complete IP addresses.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$table = Database::table_names()['consents'];
		$query = $wpdb->prepare( "SELECT ip_ciphertext FROM {$table} WHERE id = %d", $record_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared lookup in the plugin-owned receipt table.
		$ciphertext = $wpdb->get_var( $query );

		if ( ! is_string( $ciphertext ) || '' === $ciphertext ) {
			return new \WP_Error( 'uccm_full_ip_unavailable', __( 'No complete IP address is stored for this receipt.', 'uk-cookie-consent-manager' ), array( 'status' => 404 ) );
		}

		$ip = IP_Privacy::decrypt( $ciphertext );
		return '' === $ip
			? new \WP_Error( 'uccm_ip_decryption_failed', __( 'The stored IP address could not be decrypted.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) )
			: $ip;
	}

	/**
	 * Run scheduled retention cleanup without returning a value to WordPress.
	 */
	public static function run_cleanup(): void {
		self::cleanup_expired();
	}

	/**
	 * Delete receipts older than the configured retention period.
	 */
	public static function cleanup_expired(): int {
		global $wpdb;

		$settings       = get_option( 'uccm_settings', array() );
		$settings       = is_array( $settings ) ? $settings : array();
		$retention_days = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 365;
		$retention_days = max( 1, min( 3650, $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$table          = Database::table_names()['consents'];
		$query          = $wpdb->prepare( "DELETE FROM {$table} WHERE occurred_at < %s", $cutoff ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared retention cleanup in the plugin-owned receipt table.
		$deleted = $wpdb->query( $query );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Check the consent-record viewing capability.
	 */
	public static function can_view(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		return current_user_can( 'view_uccm_consents' );
	}

	/**
	 * Check the consent-record export capability.
	 */
	public static function can_export(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		return current_user_can( 'export_uccm_consents' );
	}

	/**
	 * Normalise category choices to the four-category contract.
	 *
	 * @param array<string, mixed> $choices Untrusted category choices.
	 * @return array{necessary: true, functional: bool, analytics: bool, marketing: bool}
	 */
	private static function normalise_choices( array $choices ): array {
		return array(
			'necessary'  => true,
			'functional' => true === ( $choices['functional'] ?? false ),
			'analytics'  => true === ( $choices['analytics'] ?? false ),
			'marketing'  => true === ( $choices['marketing'] ?? false ),
		);
	}

	/**
	 * Return a keyed, non-public site identifier.
	 */
	private static function site_identifier(): string {
		$site = get_current_blog_id() . '|' . home_url( '/' );
		return hash_hmac( 'sha256', $site, wp_salt( 'auth' ) );
	}

	/**
	 * Return the current user ID or null for an anonymous visitor.
	 */
	private static function current_user_id(): ?int {
		$user_id = get_current_user_id();
		return 0 < $user_id ? $user_id : null;
	}

	/**
	 * Sign the immutable receipt evidence.
	 *
	 * @param array<string, mixed> $row Receipt fields before insertion.
	 */
	private static function integrity_hash( array $row ): string {
		unset( $row['integrity_hash'] );
		$canonical = wp_json_encode( $row );
		return hash_hmac( 'sha256', false === $canonical ? '' : $canonical, wp_salt( 'auth' ) );
	}
}
