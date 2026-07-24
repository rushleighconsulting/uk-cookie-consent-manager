<?php
/**
 * Privacy-safe operational alerts.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Records bounded operational problems and optionally notifies the site administrator.
 */
final class Operational_Alerts {

	/**
	 * Per-site alert option.
	 */
	public const OPTION_NAME = 'uccm_operational_alerts';

	/**
	 * A queued or running scan is considered stalled after thirty minutes.
	 */
	public const STALLED_AFTER_SECONDS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Keep a bounded audit history per site.
	 */
	private const MAX_RECORDS = 50;

	/**
	 * Register dashboard and stalled-work checks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'check_stalled_scans' ) );
		add_action( 'admin_notices', array( self::class, 'render_dashboard_notices' ) );
	}

	/**
	 * Record or reopen one safely described operational problem.
	 *
	 * @param string $code      Stable machine-readable error code.
	 * @param string $component Affected plugin component.
	 * @param int    $run_id    Optional scan run identifier.
	 * @return array<string, mixed>
	 */
	public static function report( string $code, string $component, int $run_id = 0 ): array {
		$code      = self::code( $code );
		$component = self::component( $component );
		$run_id    = max( 0, $run_id );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$id        = self::identifier( $code, $component, $run_id );
		$records   = self::records();
		$record    = $records[ $id ] ?? array(
			'id'                  => $id,
			'code'                => $code,
			'component'           => $component,
			'message'             => self::message( $code, $component ),
			'run_id'              => $run_id,
			'first_seen_at'       => $now,
			'last_seen_at'        => $now,
			'occurrences'         => 0,
			'status'              => 'open',
			'resolved_at'         => '',
			'dismissed_at'        => '',
			'email_attempted_at'  => '',
			'email_status'        => 'not-requested',
			'last_email_at'       => '',
			'email_attempt_count' => 0,
		);

		$record['last_seen_at'] = $now;
		$record['occurrences']  = min( PHP_INT_MAX, max( 0, (int) ( $record['occurrences'] ?? 0 ) ) + 1 );
		$record['status']       = 'open';
		$record['resolved_at']  = '';
		$record['dismissed_at'] = '';

		if ( ! empty( Settings::current()['error_email_enabled'] ) && self::email_due( $record ) ) {
			$record = self::send_email( $record );
		}

		$records[ $id ] = $record;
		self::save( $records );

		return $record;
	}

	/**
	 * Mark an exact underlying problem resolved while retaining its bounded audit record.
	 *
	 * @param string $code      Stable machine-readable error code.
	 * @param string $component Affected plugin component.
	 * @param int    $run_id    Optional scan run identifier.
	 */
	public static function resolve( string $code, string $component, int $run_id = 0 ): bool {
		$records = self::records();
		$id      = self::identifier( self::code( $code ), self::component( $component ), max( 0, $run_id ) );

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ]['status']      = 'resolved';
		$records[ $id ]['resolved_at'] = gmdate( 'Y-m-d H:i:s' );
		self::save( $records );
		return true;
	}

	/**
	 * Resolve every current alert for a component and optional scan run.
	 *
	 * @param string $component Affected plugin component.
	 * @param int    $run_id    Optional scan run identifier.
	 */
	public static function resolve_component( string $component, int $run_id = 0 ): int {
		$component = self::component( $component );
		$run_id    = max( 0, $run_id );
		$records   = self::records();
		$resolved  = 0;
		$now       = gmdate( 'Y-m-d H:i:s' );

		foreach ( $records as &$record ) {
			if ( 'open' !== (string) ( $record['status'] ?? '' ) || (string) ( $record['component'] ?? '' ) !== $component ) {
				continue;
			}

			if ( 0 < $run_id && (int) ( $record['run_id'] ?? 0 ) !== $run_id ) {
				continue;
			}

			$record['status']      = 'resolved';
			$record['resolved_at'] = $now;
			++$resolved;
		}
		unset( $record );

		if ( 0 < $resolved ) {
			self::save( $records );
		}

		return $resolved;
	}

	/**
	 * Dismiss one dashboard item while allowing a later recurrence to reopen it.
	 *
	 * @param string $id Stable alert identifier.
	 */
	public static function dismiss( string $id ): bool {
		$id      = substr( sanitize_key( $id ), 0, 64 );
		$records = self::records();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ]['status']       = 'dismissed';
		$records[ $id ]['dismissed_at'] = gmdate( 'Y-m-d H:i:s' );
		self::save( $records );
		return true;
	}

	/**
	 * Return current unresolved alerts, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function current(): array {
		$open = array_values(
			array_filter(
				self::records(),
				static fn ( array $record ): bool => 'open' === (string) ( $record['status'] ?? '' )
			)
		);

		usort( $open, static fn ( array $left, array $right ): int => strcmp( (string) ( $right['last_seen_at'] ?? '' ), (string) ( $left['last_seen_at'] ?? '' ) ) );
		return $open;
	}

	/**
	 * Detect bounded queued or running scans which have stopped progressing.
	 */
	public static function check_stalled_scans(): void {
		global $wpdb;

		if ( ! current_user_can( 'manage_uccm_settings' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
			return;
		}

		$table     = Database::table_names()['scan_runs'];
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::STALLED_AFTER_SECONDS );
		$query     = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status IN ('queued', 'running') AND started_at < %s ORDER BY id DESC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned and values are prepared.
			$threshold
		);
		$run_ids   = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above and reads bounded plugin-owned operational state.

		foreach ( $run_ids as $run_id ) {
			self::report( 'uccm_scan_stalled', 'scanner', (int) $run_id );
		}
	}

	/**
	 * Show operational problems only on the main WordPress Dashboard.
	 */
	public static function render_dashboard_notices(): void {
		if ( ! current_user_can( 'manage_uccm_settings' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! is_object( $screen ) || 'dashboard' !== (string) $screen->id ) {
			return;
		}

		foreach ( self::current() as $record ) {
			$id = (string) $record['id'];
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'UK Cookie Consent Manager needs attention', 'uk-cookie-consent-manager' ) . '</strong></p>';
			echo '<p>' . esc_html( (string) $record['message'] ) . ' ' . esc_html__( 'Code:', 'uk-cookie-consent-manager' ) . ' <code>' . esc_html( (string) $record['code'] ) . '</code>. ' . esc_html__( 'Last seen (UTC):', 'uk-cookie-consent-manager' ) . ' ' . esc_html( (string) $record['last_seen_at'] ) . '.</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::review_url( $record ) ) . '">' . esc_html__( 'Review and recover', 'uk-cookie-consent-manager' ) . '</a></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="uccm_dismiss_operational_alert"><input type="hidden" name="alert_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'uccm_dismiss_operational_alert_' . $id );
			echo '<button type="submit" class="button-link">' . esc_html__( 'Dismiss this occurrence', 'uk-cookie-consent-manager' ) . '</button></form></div>';
		}
	}

	/**
	 * Return stored records keyed by stable identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function records(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? array_filter( $stored, 'is_array' ) : array();
	}

	/**
	 * Store only the most recent bounded records.
	 *
	 * @param array<string, array<string, mixed>> $records Alert records.
	 */
	private static function save( array $records ): void {
		uasort( $records, static fn ( array $left, array $right ): int => strcmp( (string) ( $right['last_seen_at'] ?? '' ), (string) ( $left['last_seen_at'] ?? '' ) ) );
		update_option( self::OPTION_NAME, array_slice( $records, 0, self::MAX_RECORDS, true ), false );
	}

	/**
	 * Create a site-, problem- and run-bounded identifier.
	 *
	 * @param string $code      Stable machine-readable error code.
	 * @param string $component Affected plugin component.
	 * @param int    $run_id    Optional scan run identifier.
	 */
	private static function identifier( string $code, string $component, int $run_id ): string {
		return hash( 'sha256', get_current_blog_id() . '|' . $component . '|' . $code . '|' . $run_id );
	}

	/**
	 * Accept stable plugin error codes only, never caller-supplied detail.
	 *
	 * @param string $code Proposed error code.
	 */
	private static function code( string $code ): string {
		$code = substr( sanitize_key( $code ), 0, 50 );
		return str_starts_with( $code, 'uccm_' ) ? $code : 'uccm_operational_error';
	}

	/**
	 * Restrict component names to a small public contract.
	 *
	 * @param string $component Proposed component name.
	 */
	private static function component( string $component ): string {
		$component = sanitize_key( $component );
		return in_array( $component, array( 'scanner', 'browser-check', 'updater', 'system' ), true ) ? $component : 'system';
	}

	/**
	 * Return a fixed plain-language message without caller-supplied detail.
	 *
	 * @param string $code      Stable machine-readable error code.
	 * @param string $component Affected plugin component.
	 */
	private static function message( string $code, string $component ): string {
		if ( 'uccm_scan_stalled' === $code ) {
			return __( 'A background cookie scan has stopped progressing.', 'uk-cookie-consent-manager' );
		}

		if ( 'uccm_update_rollback_failed' === $code ) {
			return __( 'A plugin update failed and WordPress could not restore the previous UCCM version.', 'uk-cookie-consent-manager' );
		}

		if ( 'uccm_update_rolled_back' === $code ) {
			return __( 'WordPress restored the previous UCCM version after an update caused a fatal error.', 'uk-cookie-consent-manager' );
		}

		if ( 'uccm_update_failed' === $code ) {
			return __( 'An attempted UCCM update did not complete successfully.', 'uk-cookie-consent-manager' );
		}

		if ( 'updater' === $component ) {
			return __( 'UCCM could not verify or retrieve its update information.', 'uk-cookie-consent-manager' );
		}

		if ( 'browser-check' === $component ) {
			return __( 'The administrator browser check did not complete successfully.', 'uk-cookie-consent-manager' );
		}

		if ( 'scanner' === $component ) {
			return __( 'A cookie scan could not complete successfully.', 'uk-cookie-consent-manager' );
		}

		return __( 'The plugin handled an operational problem.', 'uk-cookie-consent-manager' );
	}

	/**
	 * Determine whether this recurrence may send another email.
	 *
	 * @param array<string, mixed> $record Alert record.
	 */
	private static function email_due( array $record ): bool {
		$last_email = strtotime( (string) ( $record['last_email_at'] ?? '' ) );
		$settings   = Settings::current();
		$minutes    = max(
			1,
			min(
				Settings::MAX_ERROR_EMAIL_SUPPRESSION_MINUTES,
				(int) ( $settings['error_email_suppression_minutes'] ?? Settings::DEFAULT_ERROR_EMAIL_SUPPRESSION_MINUTES )
			)
		);

		return false === $last_email || $last_email <= time() - ( $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Send one privacy-safe notification and retain delivery metadata only.
	 *
	 * @param array<string, mixed> $record Alert record.
	 * @return array<string, mixed>
	 */
	private static function send_email( array $record ): array {
		$now = gmdate( 'Y-m-d H:i:s' );

		$record['email_attempted_at'] = $now;

		$record['email_attempt_count'] = min( PHP_INT_MAX, max( 0, (int) ( $record['email_attempt_count'] ?? 0 ) ) + 1 );

		$recipient = sanitize_email( (string) get_option( 'admin_email', '' ) );

		$record['email_status'] = 'invalid-recipient';

		if ( '' === $recipient ) {
			return $record;
		}

		$subject  = __( 'UK Cookie Consent Manager needs attention', 'uk-cookie-consent-manager' );
		$message  = (string) $record['message'] . "\n\n";
		$message .= __( 'Error code:', 'uk-cookie-consent-manager' ) . ' ' . (string) $record['code'] . "\n";
		$message .= __( 'Component:', 'uk-cookie-consent-manager' ) . ' ' . (string) $record['component'] . "\n";
		$message .= __( 'Time (UTC):', 'uk-cookie-consent-manager' ) . ' ' . (string) $record['last_seen_at'] . "\n";
		$message .= __( 'Review:', 'uk-cookie-consent-manager' ) . ' ' . self::review_url( $record );

		$sent = wp_mail( $recipient, $subject, $message );

		$record['email_status']  = $sent ? 'sent' : 'failed';
		$record['last_email_at'] = $now;

		return $record;
	}

	/**
	 * Build a same-site recovery URL from bounded identifiers only.
	 *
	 * @param array<string, mixed> $record Alert record.
	 */
	private static function review_url( array $record ): string {
		if ( 'updater' === (string) ( $record['component'] ?? '' ) ) {
			return admin_url( 'admin.php?page=uccm-advanced' );
		}

		$run_id = max( 0, (int) ( $record['run_id'] ?? 0 ) );
		return admin_url( 'admin.php?page=uccm-scans' . ( 0 < $run_id ? '&scan_id=' . $run_id : '' ) );
	}
}
