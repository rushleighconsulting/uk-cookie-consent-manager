<?php
/**
 * Hybrid storage detection and scan scheduling.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Runs bounded same-origin scans and accepts authenticated browser observations.
 */
final class Scanner {

	/**
	 * Monthly scan hook.
	 */
	public const HOOK = 'uccm_monthly_scan';

	/**
	 * Custom monthly recurrence.
	 */
	public const RECURRENCE = 'uccm_monthly';

	/**
	 * Maximum targets per scan.
	 */
	public const MAX_TARGETS = 20;

	/**
	 * Maximum findings stored per scan.
	 */
	public const MAX_FINDINGS = 500;

	/**
	 * Register scheduling and execution hooks.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		self::schedule();
	}

	/**
	 * Add the plugin's approximately monthly recurrence.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules[ self::RECURRENCE ] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Every 30 days', 'uk-cookie-consent-manager' ),
		);

		return $schedules;
	}

	/**
	 * Schedule exactly one recurring scan event.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, self::RECURRENCE, self::HOOK );
		}
	}

	/**
	 * Run the scheduled scan without an interactive capability check.
	 */
	public static function run_scheduled(): void {
		self::run( false );
	}

	/**
	 * Return the stable site-held browser-runner authentication token.
	 */
	public static function runner_token(): string {
		return hash_hmac( 'sha256', 'uccm-browser-runner-v1', wp_salt( 'auth' ) );
	}

	/**
	 * Validate and bound observations returned by a controlled browser runner.
	 *
	 * @param array<string, mixed> $payload Runner payload containing observations.
	 * @param string               $token   Presented authentication token.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function accept_browser_observations( array $payload, string $token ): array|\WP_Error {
		if ( '' === $token || ! hash_equals( self::runner_token(), $token ) ) {
			return new \WP_Error( 'uccm_runner_unauthorised', __( 'The browser runner could not be authenticated.', 'uk-cookie-consent-manager' ), array( 'status' => 401 ) );
		}

		$observations = $payload['observations'] ?? array();

		if ( ! is_array( $observations ) ) {
			return new \WP_Error( 'uccm_runner_invalid_payload', __( 'Browser observations must be supplied as a list.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$accepted = array();

		foreach ( array_slice( $observations, 0, self::MAX_FINDINGS ) as $observation ) {
			if ( ! is_array( $observation ) ) {
				continue;
			}

			$normalized = self::normalize_observation( $observation );

			if ( null !== $normalized ) {
				$accepted[] = $normalized;
			}
		}

		return $accepted;
	}

	/**
	 * Validate one target against the site origin and WordPress safe-HTTP rules.
	 *
	 * @param string $target    Candidate target.
	 * @param string $site_home Site home URL.
	 * @return string|\WP_Error
	 */
	public static function validate_target( string $target, string $site_home = '' ): string|\WP_Error {
		$target    = esc_url_raw( trim( $target ) );
		$site_home = '' === $site_home ? home_url( '/' ) : esc_url_raw( $site_home );
		$parts     = wp_parse_url( $target );
		$home      = wp_parse_url( $site_home );

		if ( '' === $target || ! is_array( $parts ) || ! is_array( $home ) ) {
			return new \WP_Error( 'uccm_scan_invalid_target', __( 'A scan target is not a valid public URL.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$scheme      = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host        = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
		$home_host   = strtolower( rtrim( (string) ( $home['host'] ?? '' ), '.' ) );
		$port        = (int) ( $parts['port'] ?? self::default_port( $scheme ) );
		$home_port   = (int) ( $home['port'] ?? self::default_port( $home_scheme ) );

		if (
			! in_array( $scheme, array( 'http', 'https' ), true ) ||
			$scheme !== $home_scheme ||
			'' === $host ||
			$host !== $home_host ||
			$port !== $home_port ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['fragment'] )
		) {
			return new \WP_Error( 'uccm_scan_disallowed_target', __( 'Scan targets must be same-origin public URLs without credentials or fragments.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! self::is_public_ip( $host ) ) {
			return new \WP_Error( 'uccm_scan_private_target', __( 'Private and reserved network targets are not allowed.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		if ( false === wp_http_validate_url( $target ) ) {
			return new \WP_Error( 'uccm_scan_unsafe_target', __( 'The target failed WordPress safe-HTTP validation.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		return $target;
	}

	/**
	 * Return the validated homepage and configured public targets.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function targets(): array|\WP_Error {
		$settings   = Settings::current();
		$configured = is_array( $settings['scan_urls'] ?? null ) ? $settings['scan_urls'] : array();
		$candidates = array_slice( array_merge( array( home_url( '/' ) ), $configured ), 0, self::MAX_TARGETS );
		$targets    = array();

		foreach ( array_unique( $candidates ) as $candidate ) {
			$validated = self::validate_target( (string) $candidate );

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$targets[] = $validated;
		}

		return $targets;
	}

	/**
	 * Run a bounded hybrid scan.
	 *
	 * @param bool                                      $require_capability Whether to enforce the scan capability.
	 * @param string[]|null                             $targets            Optional explicit targets for testing.
	 * @param callable(string, array<string, mixed>): mixed|null $fetcher   Optional safe-HTTP replacement.
	 * @param array<string, mixed>|null                 $browser_payload    Optional authenticated runner payload.
	 * @return int|\WP_Error Scan run ID or error.
	 */
	public static function run(
		bool $require_capability = true,
		?array $targets = null,
		?callable $fetcher = null,
		?array $browser_payload = null
	): int|\WP_Error {
		global $wpdb;

		if ( $require_capability && ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to run cookie scans.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		if ( get_transient( 'uccm_scan_lock' ) ) {
			return new \WP_Error( 'uccm_scan_busy', __( 'A cookie scan is already running.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		$targets = null === $targets ? self::targets() : self::validate_targets( $targets );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		set_transient( 'uccm_scan_lock', '1', 5 * MINUTE_IN_SECONDS );

		try {
			$tables  = Database::table_names();
			$now     = gmdate( 'Y-m-d H:i:s' );
			$methods = array( 'same-origin-set-cookie', 'authenticated-browser-observations' );
			$created = $wpdb->insert(
				$tables['scan_runs'],
				array(
					'status'        => 'running',
					'methods'       => wp_json_encode( $methods ),
					'coverage'      => wp_json_encode( array( 'target_count' => count( $targets ) ) ),
					'pages_visited' => wp_json_encode( array() ),
					'summary'       => wp_json_encode( array( 'findings' => 0, 'warnings' => array() ) ),
					'error_code'    => '',
					'started_at'    => $now,
					'completed_at'  => null,
					'created_at'    => $now,
				)
			);

			if ( false === $created ) {
				return new \WP_Error( 'uccm_scan_not_created', __( 'The scan run could not be created.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) );
			}

			$run_id       = (int) $wpdb->insert_id;
			$observations = array();
			$visited      = array();
			$warnings     = array();
			$fetcher      = $fetcher ?? array( self::class, 'safe_fetch' );

			foreach ( $targets as $target ) {
				$response = $fetcher( $target, self::request_arguments() );

				if ( is_wp_error( $response ) ) {
					$warnings[] = array(
						'url'  => $target,
						'code' => sanitize_key( $response->get_error_code() ),
					);
					$visited[] = array( 'url' => $target, 'status' => 0 );
					continue;
				}

				$status    = wp_remote_retrieve_response_code( $response );
				$visited[] = array( 'url' => $target, 'status' => $status );

				if ( 200 > $status || 399 < $status ) {
					$warnings[] = array( 'url' => $target, 'code' => 'http_' . $status );
					continue;
				}

				foreach ( self::set_cookie_headers( $response ) as $header ) {
					$cookie = self::parse_set_cookie( $header, $target );

					if ( null !== $cookie ) {
						$observations[] = $cookie;
					}
				}
			}

			$browser_payload = $browser_payload ?? self::browser_payload( $targets );
			$browser         = self::accept_browser_observations(
				$browser_payload,
				(string) ( $browser_payload['token'] ?? '' )
			);

			if ( is_wp_error( $browser ) ) {
				$warnings[] = array( 'url' => '', 'code' => $browser->get_error_code() );
			} else {
				$observations = array_merge( $observations, $browser );
			}

			$findings = self::store_findings( $run_id, $observations, $tables['scan_findings'] );
			$summary  = array(
				'findings'    => $findings,
				'warnings'    => $warnings,
				'limitations' => array(
					'The scan covers only the configured public same-origin pages.',
					'Browser observations depend on the connected authenticated runner.',
					'Authenticated, personalised and geographically varied journeys are not exhaustive.',
				),
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finalises the plugin-owned scan run.
			$wpdb->update(
				$tables['scan_runs'],
				array(
					'status'        => 'completed',
					'coverage'      => wp_json_encode(
						array(
							'target_count' => count( $targets ),
							'visited_count' => count( $visited ),
							'methods'       => $methods,
						)
					),
					'pages_visited' => wp_json_encode( $visited ),
					'summary'       => wp_json_encode( $summary ),
					'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $run_id )
			);

			return $run_id;
		} finally {
			delete_transient( 'uccm_scan_lock' );
		}
	}

	/**
	 * Return recent scan summaries for the administration screen.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function recent_runs( int $limit = 20 ): array|\WP_Error {
		global $wpdb;

		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to view scan runs.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$table = Database::table_names()['scan_runs'];
		$limit = max( 1, min( 100, $limit ) );
		$sql   = "SELECT id, status, methods, coverage, pages_visited, summary, error_code, started_at, completed_at FROM {$table} ORDER BY id DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		$query = $wpdb->prepare( $sql, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded administration listing.
		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Safe WordPress HTTP request adapter.
	 *
	 * @param string               $url       Validated target.
	 * @param array<string, mixed> $arguments Request arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function safe_fetch( string $url, array $arguments ): array|\WP_Error {
		return wp_safe_remote_get( $url, $arguments );
	}

	/**
	 * Return bounded safe-HTTP arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function request_arguments(): array {
		return array(
			'timeout'             => 10,
			'redirection'         => 2,
			'limit_response_size' => 1024 * 1024,
			'user-agent'          => 'UCCM/' . UCCM_VERSION . '; ' . home_url( '/' ),
			'reject_unsafe_urls'  => true,
		);
	}

	/**
	 * Return an authenticated in-process browser-runner payload.
	 *
	 * @param string[] $targets Validated targets.
	 * @return array<string, mixed>
	 */
	private static function browser_payload( array $targets ): array {
		$empty = array(
			'token'        => self::runner_token(),
			'observations' => array(),
		);

		/**
		 * Supplies authenticated browser observations without consent records.
		 *
		 * @param array<string, mixed> $payload Runner payload.
		 * @param array<string, mixed> $request Bounded request metadata.
		 */
		$payload = apply_filters(
			'uccm_browser_scan_payload',
			$empty,
			array(
				'targets'      => $targets,
				'max_findings' => self::MAX_FINDINGS,
				'methods'      => array( 'cookie', 'local_storage', 'script', 'iframe', 'pixel' ),
			)
		);

		return is_array( $payload ) ? $payload : $empty;
	}

	/**
	 * Validate an explicit target list.
	 *
	 * @param string[] $targets Candidate targets.
	 * @return string[]|\WP_Error
	 */
	private static function validate_targets( array $targets ): array|\WP_Error {
		$validated_targets = array();

		foreach ( array_slice( array_unique( $targets ), 0, self::MAX_TARGETS ) as $target ) {
			$validated = self::validate_target( (string) $target );

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$validated_targets[] = $validated;
		}

		return array() === $validated_targets
			? new \WP_Error( 'uccm_scan_no_targets', __( 'At least one valid public scan target is required.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) )
			: $validated_targets;
	}

	/**
	 * Store deduplicated, bounded observations as pending review findings.
	 *
	 * @param int                              $run_id       Scan run ID.
	 * @param array<int, array<string, string>> $observations Normalised observations.
	 * @param string                           $table        Findings table.
	 */
	private static function store_findings( int $run_id, array $observations, string $table ): int {
		global $wpdb;

		$count = 0;
		$seen  = array();
		$now   = gmdate( 'Y-m-d H:i:s' );

		foreach ( array_slice( $observations, 0, self::MAX_FINDINGS ) as $observation ) {
			$fingerprint = hash( 'sha256', strtolower( $observation['type'] . '|' . $observation['storage_key'] . '|' . $observation['domain'] . '|' . $observation['source_url'] ) );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$after_data           = wp_json_encode( $observation );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bounded insert into the plugin-owned findings table.
			$inserted = $wpdb->insert(
				$table,
				array(
					'scan_run_id'  => $run_id,
					'inventory_id' => null,
					'finding_type' => 'observed',
					'storage_key'  => $observation['storage_key'],
					'domain'      => $observation['domain'],
					'before_data' => '{}',
					'after_data'  => false === $after_data ? '{}' : $after_data,
					'fingerprint' => $fingerprint,
					'status'      => 'pending',
					'created_at'  => $now,
					'reviewed_at' => null,
				)
			);

			if ( false !== $inserted ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Normalise one browser observation.
	 *
	 * @param array<string, mixed> $observation Untrusted observation.
	 * @return array<string, string>|null
	 */
	private static function normalize_observation( array $observation ): ?array {
		$type        = sanitize_key( (string) ( $observation['type'] ?? '' ) );
		$storage_key = substr( sanitize_text_field( (string) ( $observation['storage_key'] ?? $observation['name'] ?? '' ) ), 0, 191 );
		$domain      = strtolower( substr( sanitize_text_field( (string) ( $observation['domain'] ?? '' ) ), 0, 191 ) );
		$source_url  = esc_url_raw( (string) ( $observation['source_url'] ?? '' ) );
		$duration    = substr( sanitize_text_field( (string) ( $observation['duration'] ?? '' ) ), 0, 100 );

		if ( ! in_array( $type, array( 'cookie', 'local_storage', 'script', 'iframe', 'pixel' ), true ) || '' === $storage_key ) {
			return null;
		}

		return array(
			'type'         => $type,
			'storage_key'  => $storage_key,
			'domain'       => $domain,
			'source_url'   => $source_url,
			'duration'     => $duration,
			'storage_type' => in_array( $type, array( 'cookie', 'local_storage' ), true ) ? $type : 'other',
			'method'       => 'browser',
		);
	}

	/**
	 * Return all Set-Cookie header values from a WordPress response.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return string[]
	 */
	private static function set_cookie_headers( mixed $response ): array {
		$header = wp_remote_retrieve_header( $response, 'set-cookie' );

		if ( is_array( $header ) ) {
			return array_values( array_filter( array_map( 'strval', $header ) ) );
		}

		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return array();
		}

		$values = preg_split( '/\r?\n|,(?=\s*[^;,=\s]+=)/', $header );
		return is_array( $values ) ? array_values( array_filter( array_map( 'trim', $values ) ) ) : array();
	}

	/**
	 * Convert a Set-Cookie header into a normalised observation.
	 *
	 * @param string $header     Set-Cookie header.
	 * @param string $source_url Response URL.
	 * @return array<string, string>|null
	 */
	private static function parse_set_cookie( string $header, string $source_url ): ?array {
		$segments = array_map( 'trim', explode( ';', $header ) );
		$pair     = array_shift( $segments );

		if ( null === $pair || ! str_contains( $pair, '=' ) ) {
			return null;
		}

		[ $name ] = explode( '=', $pair, 2 );
		$name     = substr( sanitize_text_field( trim( $name ) ), 0, 191 );
		$parts    = wp_parse_url( $source_url );
		$domain   = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		$duration = 'session';

		foreach ( $segments as $segment ) {
			if ( str_starts_with( strtolower( $segment ), 'domain=' ) ) {
				$domain = ltrim( strtolower( substr( $segment, 7 ) ), '.' );
			} elseif ( str_starts_with( strtolower( $segment ), 'max-age=' ) ) {
				$duration = 'max-age=' . max( 0, (int) substr( $segment, 8 ) );
			} elseif ( str_starts_with( strtolower( $segment ), 'expires=' ) ) {
				$duration = substr( sanitize_text_field( $segment ), 0, 100 );
			}
		}

		if ( '' === $name ) {
			return null;
		}

		return array(
			'type'         => 'cookie',
			'storage_key'  => $name,
			'domain'       => substr( sanitize_text_field( $domain ), 0, 191 ),
			'source_url'   => $source_url,
			'duration'     => $duration,
			'storage_type' => 'cookie',
			'method'       => 'set-cookie',
		);
	}

	/**
	 * Return the default port for an HTTP scheme.
	 *
	 * @param string $scheme URL scheme.
	 */
	private static function default_port( string $scheme ): int {
		return 'https' === $scheme ? 443 : 80;
	}

	/**
	 * Determine whether an IP literal is globally routable.
	 *
	 * @param string $ip IP address.
	 */
	private static function is_public_ip( string $ip ): bool {
		return false !== filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
