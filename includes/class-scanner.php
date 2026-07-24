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
	 * Resumable crawl batch hook.
	 */
	public const BATCH_HOOK = 'uccm_scan_batch';

	/**
	 * Custom monthly recurrence.
	 */
	public const RECURRENCE = 'uccm_monthly';

	/**
	 * Temporary hard ceiling for targets per scan.
	 *
	 * Each asynchronous run persists its frontier and never exceeds this ceiling.
	 */
	public const MAX_TARGETS = 1024;

	/**
	 * Maximum findings stored per scan.
	 */
	public const MAX_FINDINGS = 500;

	/**
	 * Default pages processed in one background request.
	 */
	public const DEFAULT_BATCH_SIZE = 5;

	/**
	 * Maximum persisted batches processed by one background request.
	 */
	private const MAX_BATCHES_PER_REQUEST = 4;

	/**
	 * Soft runtime budget for one background request.
	 */
	private const MAX_BATCH_RUNTIME_SECONDS = 20;

	/**
	 * Maximum pages inspected by one administrator browser session.
	 */
	public const BROWSER_MAX_TARGETS = 100;

	/**
	 * Maximum authenticated runner submissions per minute.
	 */
	private const RUNNER_RATE_LIMIT = 30;

	/**
	 * Register scheduling and execution hooks.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		add_action( self::BATCH_HOOK, array( self::class, 'run_batch' ), 10, 1 );
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
		self::start( false );
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

		$rate_key = 'uccm_runner_rate_' . substr( hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ), 0, 32 );
		$count    = (int) get_transient( $rate_key );

		if ( self::RUNNER_RATE_LIMIT <= $count ) {
			return new \WP_Error( 'uccm_runner_rate_limited', __( 'The browser runner request limit has been reached.', 'uk-cookie-consent-manager' ), array( 'status' => 429 ) );
		}

		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );
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

			if ( null === $normalized ) {
				continue;
			}

			$identity = hash( 'sha256', strtolower( $normalized['type'] . '|' . $normalized['storage_key'] . '|' . $normalized['domain'] ) );

			if ( ! isset( $accepted[ $identity ] ) ) {
				$accepted[ $identity ] = $normalized;
				continue;
			}

			$existing_urls   = is_array( $accepted[ $identity ]['source_urls'] ?? null ) ? $accepted[ $identity ]['source_urls'] : array();
			$incoming_urls   = is_array( $normalized['source_urls'] ?? null ) ? $normalized['source_urls'] : array();
			$existing_states = is_array( $accepted[ $identity ]['consent_states'] ?? null ) ? $accepted[ $identity ]['consent_states'] : array();
			$incoming_states = is_array( $normalized['consent_states'] ?? null ) ? $normalized['consent_states'] : array();
			$all_urls        = array_values( array_unique( array_merge( $existing_urls, $incoming_urls ) ) );

			$accepted[ $identity ]['source_urls']    = array_slice( $all_urls, 0, 20 );
			$accepted[ $identity ]['source_url']     = $accepted[ $identity ]['source_urls'][0] ?? '';
			$accepted[ $identity ]['source_count']   = min(
				self::BROWSER_MAX_TARGETS,
				max(
					count( $all_urls ),
					(int) ( $accepted[ $identity ]['source_count'] ?? 0 ),
					(int) ( $normalized['source_count'] ?? 0 )
				)
			);
			$accepted[ $identity ]['consent_states'] = array_values( array_unique( array_merge( $existing_states, $incoming_states ) ) );
		}

		return array_values( $accepted );
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
	 * Return deterministic validated scan targets.
	 *
	 * Ordering is homepage, published WordPress pages/posts by type and ID, then
	 * administrator-configured URLs. The current site's query supplies the
	 * multisite boundary.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function targets(): array|\WP_Error {
		$plan = self::target_plan( self::MAX_TARGETS );
		return is_wp_error( $plan ) ? $plan : $plan['targets'];
	}

	/**
	 * Build a bounded target plan and its coverage metadata.
	 *
	 * @param int $limit Maximum target count.
	 * @return array{targets: string[], wordpress_content_count: int}|\WP_Error
	 */
	private static function target_plan( int $limit ): array|\WP_Error {
		$settings   = Settings::current();
		$configured = is_array( $settings['scan_urls'] ?? null ) ? $settings['scan_urls'] : array();
		$excluded   = is_array( $settings['scan_excluded_paths'] ?? null ) ? $settings['scan_excluded_paths'] : Crawler::DEFAULT_EXCLUDED_PATHS;
		$wordpress  = self::eligible_wordpress_targets( $excluded );

		$candidates = array_merge(
			array(
				array(
					'url'    => home_url( '/' ),
					'source' => 'homepage',
				),
			),
			array_map(
				static fn ( string $url ): array => array(
					'url'    => $url,
					'source' => 'content',
				),
				$wordpress
			),
			array_map(
				static fn ( mixed $url ): array => array(
					'url'    => (string) $url,
					'source' => 'configured',
				),
				$configured
			)
		);

		$targets          = array();
		$sources          = array();
		$wordpress_lookup = array_fill_keys( $wordpress, true );

		foreach ( $candidates as $candidate ) {
			$raw       = (string) $candidate['url'];
			$validated = Crawler::canonicalize( $raw, home_url( '/' ) );

			if ( is_wp_error( $validated ) ) {
				if ( 'configured' === $candidate['source'] || 'homepage' === $candidate['source'] ) {
					return self::target_error( $validated, $raw );
				}

				continue;
			}

			if ( isset( $sources[ $validated ] ) ) {
				if ( 'content' === $candidate['source'] ) {
					$wordpress_lookup[ $validated ] = true;
				}
				continue;
			}

			if ( count( $targets ) >= $limit ) {
				break;
			}

			$targets[]             = $validated;
			$sources[ $validated ] = (string) $candidate['source'];
		}

		$wordpress_count = 0;

		foreach ( $targets as $target ) {
			if ( isset( $wordpress_lookup[ $target ] ) ) {
				++$wordpress_count;
			}
		}

		return array(
			'targets'                 => $targets,
			'wordpress_content_count' => $wordpress_count,
		);
	}

	/**
	 * Return eligible published pages and posts for the current WordPress site.
	 *
	 * Search-engine robots metadata is deliberately not consulted.
	 *
	 * @param string[] $excluded Administrator-configured path exclusions.
	 * @return string[]
	 */
	private static function eligible_wordpress_targets( array $excluded ): array {
		$post_ids = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'has_password'           => false,
				'numberposts'            => self::MAX_TARGETS,
				'posts_per_page'         => self::MAX_TARGETS,
				'orderby'                => array(
					'post_type' => 'ASC',
					'ID'        => 'ASC',
				),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$records = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );

			if (
				null === $post
				|| 'publish' !== $post->post_status
				|| ! in_array( $post->post_type, array( 'page', 'post' ), true )
				|| '' !== $post->post_password
			) {
				continue;
			}

			$records[] = array(
				'id'   => (int) $post_id,
				'type' => (string) $post->post_type,
			);
		}

		usort(
			$records,
			static function ( array $left, array $right ): int {
				$type_order = strcmp( $left['type'], $right['type'] );
				return 0 !== $type_order ? $type_order : $left['id'] <=> $right['id'];
			}
		);

		$targets = array();

		foreach ( $records as $record ) {
			$permalink = get_permalink( $record['id'] );

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$target = Crawler::canonicalize( $permalink, home_url( '/' ) );

			if ( is_wp_error( $target ) || Crawler::is_excluded( $target, $excluded ) ) {
				continue;
			}

			$targets[ $target ] = true;
		}

		return array_keys( $targets );
	}

	/**
	 * Create a resumable crawl and queue its first bounded batch.
	 *
	 * @param bool $require_capability Whether to enforce the scan capability.
	 * @return int|\WP_Error Scan run ID or error.
	 */
	public static function start( bool $require_capability = true ): int|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( $require_capability && ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to run cookie scans.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$settings   = Settings::current();
		$max_pages  = max( 1, min( self::MAX_TARGETS, (int) ( $settings['scan_page_limit'] ?? self::MAX_TARGETS ) ) );
		$batch_size = max( 1, min( 25, (int) ( $settings['scan_batch_size'] ?? self::DEFAULT_BATCH_SIZE ) ) );
		$plan       = self::target_plan( $max_pages );

		if ( is_wp_error( $plan ) ) {
			self::record_failed_run( $plan );
			return $plan;
		}

		$targets  = $plan['targets'];
		$methods  = array( 'same-origin-set-cookie', 'administrator-browser-observations' );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$coverage = array(
			'target_count'              => count( $targets ),
			'discovered_count'          => count( $targets ),
			'wordpress_content_count'   => (int) $plan['wordpress_content_count'],
			'accepted_link_count'       => 0,
			'ignored_counts'            => Crawler::empty_ignored_counts(),
			'visited_count'             => 0,
			'remaining_count'           => count( $targets ),
			'max_pages'                 => $max_pages,
			'batch_size'                => $batch_size,
			'frontier'                  => $targets,
			'seen'                      => $targets,
			'browser_status'            => 'not-run',
			'browser_observation_count' => 0,
		);
		$summary  = array(
			'findings'       => 0,
			'finding_counts' => self::empty_finding_counts(),
			'warnings'       => array(),
			'limitations'    => array(
				'Published WordPress pages and posts are checked alongside eligible same-site links.',
				'Media files, attachment records, archives, pagination and tracking-only URL variants are ignored unless explicitly configured.',
				'Password-protected, unpublished, authenticated, personalised and geographically varied journeys are not included.',
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates a bounded plugin-owned scan run.
		$created = $wpdb->insert(
			Database::table_names()['scan_runs'],
			array(
				'status'        => 'queued',
				'methods'       => wp_json_encode( $methods ),
				'coverage'      => wp_json_encode( $coverage ),
				'pages_visited' => wp_json_encode( array() ),
				'summary'       => wp_json_encode( $summary ),
				'error_code'    => '',
				'started_at'    => $now,
				'completed_at'  => null,
				'created_at'    => $now,
			)
		);

		if ( false === $created ) {
			return new \WP_Error( 'uccm_scan_not_created', __( 'The scan run could not be created.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) );
		}

		$run_id = (int) $wpdb->insert_id;
		self::schedule_batch( $run_id, true );
		return $run_id;
	}


	/**
	 * Run one cron batch without returning a value to the action dispatcher.
	 *
	 * @param int $run_id Scan run identifier.
	 */
	public static function run_batch( int $run_id ): void {
		$started = microtime( true );

		for ( $batch = 0; $batch < self::MAX_BATCHES_PER_REQUEST; ++$batch ) {
			$result = self::process_batch( $run_id, null, false );

			if ( is_wp_error( $result ) ) {
				return;
			}

			$run = self::run_record( $run_id );

			if ( is_wp_error( $run ) || 'running' !== (string) $run['status'] ) {
				return;
			}

			if ( self::MAX_BATCH_RUNTIME_SECONDS <= microtime( true ) - $started ) {
				break;
			}
		}

		self::schedule_batch( $run_id, true );
	}

	/**
	 * Process one persisted crawl batch and queue the next when work remains.
	 *
	 * @param int                                                $run_id  Scan run identifier.
	 * @param callable(string, array<string, mixed>): mixed|null $fetcher       Optional safe-HTTP replacement.
	 * @param bool                                               $schedule_next Whether to queue the next request.
	 * @return bool|\WP_Error
	 */
	public static function process_batch( int $run_id, ?callable $fetcher = null, bool $schedule_next = true ): bool|\WP_Error {
		global $wpdb;

		$run = self::run_record( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! in_array( (string) $run['status'], array( 'queued', 'running' ), true ) ) {
			return true;
		}

		$lock_key = 'uccm_scan_batch_' . $run_id;

		if ( get_transient( $lock_key ) ) {
			return new \WP_Error( 'uccm_scan_batch_busy', __( 'This scan batch is already running.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		set_transient( $lock_key, '1', 2 * MINUTE_IN_SECONDS );

		try {
			$coverage     = self::decoded_array( $run['coverage'] ?? '' );
			$visited      = self::decoded_array( $run['pages_visited'] ?? '' );
			$summary      = self::decoded_array( $run['summary'] ?? '' );
			$frontier     = is_array( $coverage['frontier'] ?? null ) ? array_values( $coverage['frontier'] ) : array();
			$seen_urls    = is_array( $coverage['seen'] ?? null ) ? array_values( $coverage['seen'] ) : array();
			$seen         = array_fill_keys( $seen_urls, true );
			$max_pages    = max( 1, min( self::MAX_TARGETS, (int) ( $coverage['max_pages'] ?? self::MAX_TARGETS ) ) );
			$batch_size   = max( 1, min( 25, (int) ( $coverage['batch_size'] ?? self::DEFAULT_BATCH_SIZE ) ) );
			$batch        = array_splice( $frontier, 0, $batch_size );
			$warnings     = is_array( $summary['warnings'] ?? null ) ? $summary['warnings'] : array();
			$fetcher      = $fetcher ?? array( self::class, 'safe_fetch' );
			$settings     = Settings::current();
			$excluded     = is_array( $settings['scan_excluded_paths'] ?? null ) ? $settings['scan_excluded_paths'] : Crawler::DEFAULT_EXCLUDED_PATHS;
			$observations = array();

			foreach ( $batch as $target ) {
				$validated = self::validate_target( (string) $target );

				if ( is_wp_error( $validated ) || Crawler::is_excluded( (string) $target, $excluded ) ) {
					$warnings[] = array(
						'url'  => (string) $target,
						'code' => is_wp_error( $validated ) ? sanitize_key( $validated->get_error_code() ) : 'uccm_scan_excluded_target',
					);
					continue;
				}

				$response = $fetcher( $validated, self::request_arguments() );

				if ( is_wp_error( $response ) ) {
					$warnings[] = array(
						'url'  => $validated,
						'code' => sanitize_key( $response->get_error_code() ),
					);
					$visited[]  = array(
						'url'    => $validated,
						'status' => 0,
						'method' => 'server',
					);
					continue;
				}

				$status       = wp_remote_retrieve_response_code( $response );
				$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
				$visited[]    = array(
					'url'          => $validated,
					'status'       => $status,
					'method'       => 'server',
					'content_type' => substr( sanitize_text_field( $content_type ), 0, 191 ),
				);

				if ( 200 > $status || 399 < $status ) {
					$warnings[] = array(
						'url'  => $validated,
						'code' => 'http_' . $status,
					);
					continue;
				}

				foreach ( self::set_cookie_headers( $response ) as $header ) {
					$cookie = self::parse_set_cookie( $header, $validated );

					if ( null !== $cookie ) {
						$observations[] = $cookie;
					}
				}

				if ( '' === $content_type || str_contains( $content_type, 'text/html' ) ) {
					$inspection = Crawler::inspect( wp_remote_retrieve_body( $response ), $validated, $excluded );
					$ignored    = is_array( $coverage['ignored_counts'] ?? null ) ? $coverage['ignored_counts'] : Crawler::empty_ignored_counts();

					foreach ( Crawler::empty_ignored_counts() as $class => $count ) {
						$ignored[ $class ] = (int) ( $ignored[ $class ] ?? 0 ) + (int) ( $inspection['ignored'][ $class ] ?? 0 );
					}

					foreach ( $inspection['accepted'] as $discovered ) {
						if ( isset( $seen[ $discovered ] ) ) {
							++$ignored['variant'];
							continue;
						}

						if ( count( $seen ) >= $max_pages ) {
							break;
						}

						$seen[ $discovered ] = true;
						$frontier[]          = $discovered;

						$coverage['accepted_link_count'] = (int) ( $coverage['accepted_link_count'] ?? 0 ) + 1;
					}

					$coverage['ignored_counts'] = $ignored;
				}
			}

			$counts                       = Scan_Findings::process( $run_id, $observations );
			$summary['finding_counts']    = self::merge_finding_counts( self::decoded_counts( $summary['finding_counts'] ?? array() ), $counts );
			$summary['findings']          = $summary['finding_counts']['actionable'];
			$summary['warnings']          = array_slice( $warnings, 0, self::MAX_FINDINGS );
			$coverage['frontier']         = $frontier;
			$coverage['seen']             = array_keys( $seen );
			$coverage['discovered_count'] = count( $seen );
			$coverage['visited_count']    = count( $visited );
			$coverage['remaining_count']  = count( $frontier );
			$status                       = array() === $frontier ? 'completed' : 'running';
			$completed_at                 = 'completed' === $status ? gmdate( 'Y-m-d H:i:s' ) : null;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Persists resumable plugin-owned scan state.
			$updated = $wpdb->update(
				Database::table_names()['scan_runs'],
				array(
					'status'        => $status,
					'coverage'      => wp_json_encode( $coverage ),
					'pages_visited' => wp_json_encode( array_slice( $visited, 0, self::MAX_TARGETS ) ),
					'summary'       => wp_json_encode( $summary ),
					'error_code'    => '',
					'completed_at'  => $completed_at,
				),
				array( 'id' => $run_id )
			);

			if ( false === $updated ) {
				self::fail_run( $run_id, 'uccm_scan_progress_not_saved' );
				return new \WP_Error( 'uccm_scan_progress_not_saved', __( 'The scan progress could not be saved and can be resumed after review.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) );
			}

			if ( 'running' === $status && $schedule_next ) {
				self::schedule_batch( $run_id );
			}

			return true;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			self::fail_run( $run_id, 'uccm_scan_batch_failed' );
			return new \WP_Error( 'uccm_scan_batch_failed', __( 'The scan batch failed and can be resumed after review.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Cancel a queued or running scan without deleting its evidence.
	 *
	 * @param int $run_id Scan run identifier.
	 */
	public static function cancel( int $run_id ): bool|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to cancel cookie scans.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$run = self::run_record( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! in_array( (string) $run['status'], array( 'queued', 'running', 'failed' ), true ) ) {
			return new \WP_Error( 'uccm_scan_not_cancellable', __( 'This scan is not cancellable.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates plugin-owned scan evidence.
		return false !== $wpdb->update(
			Database::table_names()['scan_runs'],
			array(
				'status'       => 'cancelled',
				'error_code'   => 'uccm_scan_cancelled',
				'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Requeue an interrupted scan from its persisted frontier.
	 *
	 * @param int $run_id Scan run identifier.
	 */
	public static function resume( int $run_id ): bool|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to resume cookie scans.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$run = self::run_record( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( ! in_array( (string) $run['status'], array( 'queued', 'running', 'failed' ), true ) ) {
			return new \WP_Error( 'uccm_scan_not_resumable', __( 'This scan is not resumable.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates plugin-owned scan evidence.
		$updated = $wpdb->update(
			Database::table_names()['scan_runs'],
			array(
				'status'       => 'queued',
				'error_code'   => '',
				'completed_at' => null,
			),
			array( 'id' => $run_id )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'uccm_scan_not_resumed', __( 'The scan could not be resumed.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) );
		}

		self::schedule_batch( $run_id );
		return true;
	}

	/**
	 * Attach observations from the packaged administrator browser runner.
	 *
	 * @param int                  $run_id  Completed scan run.
	 * @param array<string, mixed> $payload Browser observation payload.
	 * @return array<string, int>|\WP_Error
	 */
	public static function record_browser_observations( int $run_id, array $payload ): array|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to add browser observations.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$run = self::run_record( $run_id );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 'completed' !== (string) $run['status'] ) {
			return new \WP_Error( 'uccm_browser_scan_not_ready', __( 'The server crawl must complete before browser observations are added.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		$browser_status = sanitize_key( (string) ( $payload['status'] ?? 'completed' ) );

		if ( ! in_array( $browser_status, array( 'running', 'completed', 'partial', 'failed' ), true ) ) {
			return new \WP_Error( 'uccm_browser_scan_invalid_status', __( 'The browser check status is invalid.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) );
		}

		$coverage = self::decoded_array( $run['coverage'] ?? '' );
		$summary  = self::decoded_array( $run['summary'] ?? '' );
		$counts   = self::empty_finding_counts();
		$accepted = array();

		if ( 'running' !== $browser_status ) {
			$payload['token'] = self::runner_token();
			$accepted         = self::accept_browser_observations( $payload, self::runner_token() );

			if ( is_wp_error( $accepted ) ) {
				return $accepted;
			}

			if ( array() !== $accepted ) {
				$counts  = Scan_Findings::process( $run_id, $accepted );
				$current = self::decoded_counts( $summary['finding_counts'] ?? array() );
				$merged  = self::merge_finding_counts( $current, $counts );

				$summary['finding_counts'] = $merged;
				$summary['findings']       = $merged['actionable'];
			}
		}

		$coverage['browser_status']            = $browser_status;
		$coverage['browser_problem']           = substr( sanitize_key( (string) ( $payload['problem'] ?? '' ) ), 0, 100 );
		$coverage['browser_observation_count'] = count( $accepted );
		$coverage['browser_target_count']      = min( self::BROWSER_MAX_TARGETS, max( 0, (int) ( $payload['target_count'] ?? 0 ) ) );
		$coverage['browser_scenario_count']    = min( 10, max( 0, (int) ( $payload['scenario_count'] ?? 0 ) ) );
		$coverage['browser_completed_steps']   = min( self::BROWSER_MAX_TARGETS * 10, max( 0, (int) ( $payload['completed_steps'] ?? 0 ) ) );
		$coverage['browser_total_steps']       = min( self::BROWSER_MAX_TARGETS * 10, max( 0, (int) ( $payload['total_steps'] ?? 0 ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Adds bounded browser-check state and evidence to a plugin-owned run.
		$updated = $wpdb->update(
			Database::table_names()['scan_runs'],
			array(
				'coverage' => wp_json_encode( $coverage ),
				'summary'  => wp_json_encode( $summary ),
			),
			array( 'id' => $run_id )
		);

		return false === $updated
			? new \WP_Error( 'uccm_browser_scan_not_saved', __( 'The browser observations could not be saved.', 'uk-cookie-consent-manager' ), array( 'status' => 500 ) )
			: $counts;
	}

	/**
	 * Run a bounded hybrid scan.
	 *
	 * @param bool                                               $require_capability Whether to enforce the scan capability.
	 * @param string[]|null                                      $targets            Optional explicit targets for testing.
	 * @param callable(string, array<string, mixed>): mixed|null $fetcher            Optional safe-HTTP replacement.
	 * @param array<string, mixed>|null                          $browser_payload     Optional authenticated runner payload.
	 * @return int|\WP_Error Scan run ID or error.
	 */
	public static function run(
		bool $require_capability = true,
		?array $targets = null,
		?callable $fetcher = null,
		?array $browser_payload = null
	): int|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( $require_capability && ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to run cookie scans.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		if ( get_transient( 'uccm_scan_lock' ) ) {
			return new \WP_Error( 'uccm_scan_busy', __( 'A cookie scan is already running.', 'uk-cookie-consent-manager' ), array( 'status' => 409 ) );
		}

		$targets = null === $targets ? self::targets() : self::validate_targets( $targets );

		if ( is_wp_error( $targets ) ) {
			self::record_failed_run( $targets );
			return $targets;
		}

		set_transient( 'uccm_scan_lock', '1', 5 * MINUTE_IN_SECONDS );

		try {
			$tables  = Database::table_names();
			$now     = gmdate( 'Y-m-d H:i:s' );
			$methods = array( 'same-origin-set-cookie', 'authenticated-browser-observations' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates a bounded plugin-owned scan run.
			$created = $wpdb->insert(
				$tables['scan_runs'],
				array(
					'status'        => 'running',
					'methods'       => wp_json_encode( $methods ),
					'coverage'      => wp_json_encode( array( 'target_count' => count( $targets ) ) ),
					'pages_visited' => wp_json_encode( array() ),
					'summary'       => wp_json_encode(
						array(
							'findings' => 0,
							'warnings' => array(),
						)
					),
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
					$visited[]  = array(
						'url'    => $target,
						'status' => 0,
					);
					continue;
				}

				$status    = wp_remote_retrieve_response_code( $response );
				$visited[] = array(
					'url'    => $target,
					'status' => $status,
				);

				if ( 200 > $status || 399 < $status ) {
					$warnings[] = array(
						'url'  => $target,
						'code' => 'http_' . $status,
					);
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
				$warnings[] = array(
					'url'  => '',
					'code' => $browser->get_error_code(),
				);
			} else {
				$observations = array_merge( $observations, $browser );
			}

			$finding_counts = Scan_Findings::process( $run_id, $observations );
			$summary        = array(
				'findings'       => $finding_counts['actionable'],
				'finding_counts' => $finding_counts,
				'warnings'       => $warnings,
				'limitations'    => array(
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
							'target_count'  => count( $targets ),
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
	 * Attach the rejected target to a validation error for safe evidence and feedback.
	 *
	 * @param \WP_Error $error  Validation error.
	 * @param string    $target Rejected target.
	 */
	private static function target_error( \WP_Error $error, string $target ): \WP_Error {
		$data              = $error->get_error_data();
		$error_data        = is_array( $data ) ? $data : array();
		$error_data['url'] = substr( sanitize_text_field( $target ), 0, 2048 );

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $error_data );
	}

	/**
	 * Preserve evidence when target validation prevents a scan from starting.
	 *
	 * @param \WP_Error $error Validation error.
	 */
	private static function record_failed_run( \WP_Error $error ): void {
		global $wpdb;

		$data    = $error->get_error_data();
		$url     = is_array( $data ) ? (string) ( $data['url'] ?? '' ) : '';
		$now     = gmdate( 'Y-m-d H:i:s' );
		$methods = array( 'same-origin-set-cookie', 'authenticated-browser-observations' );
		$table   = Database::table_names()['scan_runs'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserves a bounded plugin-owned failed-run audit record.
		$wpdb->insert(
			$table,
			array(
				'status'        => 'failed',
				'methods'       => wp_json_encode( $methods ),
				'coverage'      => wp_json_encode( array( 'target_count' => 0 ) ),
				'pages_visited' => wp_json_encode( array() ),
				'summary'       => wp_json_encode(
					array(
						'findings' => 0,
						'warnings' => array(
							array(
								'url'  => $url,
								'code' => sanitize_key( $error->get_error_code() ),
							),
						),
					)
				),
				'error_code'    => sanitize_key( $error->get_error_code() ),
				'started_at'    => $now,
				'completed_at'  => $now,
				'created_at'    => $now,
			)
		);
	}

	/**
	 * Queue one unique near-term batch event.
	 *
	 * @param int  $run_id  Scan run identifier.
	 * @param bool $dispatch Whether to start the WordPress cron runner immediately.
	 */
	private static function schedule_batch( int $run_id, bool $dispatch = false ): void {
		$args = array( $run_id );

		if ( false === wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + ( $dispatch ? 0 : 5 ), self::BATCH_HOOK, $args );
		}

		if ( $dispatch && function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
	}

	/**
	 * Return one scan run for resumable operations.
	 *
	 * @param int $run_id Scan run identifier.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_record( int $run_id ): array|\WP_Error {
		global $wpdb;

		$table = Database::table_names()['scan_runs'];
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $run_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared plugin-owned lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row )
			? $row
			: new \WP_Error( 'uccm_scan_not_found', __( 'The scan run was not found.', 'uk-cookie-consent-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * Mark an interrupted batch as failed without discarding its frontier.
	 *
	 * @param int    $run_id     Scan run identifier.
	 * @param string $error_code Stable failure code.
	 */
	private static function fail_run( int $run_id, string $error_code ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserves plugin-owned failure evidence.
		$wpdb->update(
			Database::table_names()['scan_runs'],
			array(
				'status'       => 'failed',
				'error_code'   => sanitize_key( $error_code ),
				'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Decode one stored JSON object safely.
	 *
	 * @param mixed $value Stored JSON.
	 * @return array<string, mixed>
	 */
	private static function decoded_array( mixed $value ): array {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Return a complete zeroed finding counter.
	 *
	 * @return array<string, int>
	 */
	private static function empty_finding_counts(): array {
		return array(
			'actionable' => 0,
			'new'        => 0,
			'changed'    => 0,
			'duplicates' => 0,
			'unchanged'  => 0,
		);
	}

	/**
	 * Normalise stored finding counters.
	 *
	 * @param mixed $counts Candidate counters.
	 * @return array<string, int>
	 */
	private static function decoded_counts( mixed $counts ): array {
		$normalized = self::empty_finding_counts();

		if ( is_array( $counts ) ) {
			foreach ( $normalized as $key => $value ) {
				unset( $value );
				$normalized[ $key ] = max( 0, (int) ( $counts[ $key ] ?? 0 ) );
			}
		}

		return $normalized;
	}

	/**
	 * Add one batch's finding counters to the persisted totals.
	 *
	 * @param array<string, int> $current Persisted totals.
	 * @param array<string, int> $additional Batch totals.
	 * @return array<string, int>
	 */
	private static function merge_finding_counts( array $current, array $additional ): array {
		foreach ( self::empty_finding_counts() as $key => $value ) {
			unset( $value );
			$current[ $key ] = max( 0, (int) ( $current[ $key ] ?? 0 ) ) + max( 0, (int) ( $additional[ $key ] ?? 0 ) );
		}

		return $current;
	}

	/**
	 * Return recent scan summaries for the administration screen.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function recent_runs( int $limit = 20 ): array|\WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
		if ( ! current_user_can( 'run_uccm_scans' ) ) {
			return new \WP_Error( 'uccm_scan_forbidden', __( 'You are not allowed to view scan runs.', 'uk-cookie-consent-manager' ), array( 'status' => 403 ) );
		}

		$table = Database::table_names()['scan_runs'];
		$limit = max( 1, min( 100, $limit ) );
		$sql   = "SELECT id, status, methods, coverage, pages_visited, summary, error_code, started_at, completed_at FROM {$table} ORDER BY id DESC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is internal and the bounded limit uses a placeholder.
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

		return $payload;
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
				return self::target_error( $validated, (string) $target );
			}

			$validated_targets[] = $validated;
		}

		return array() === $validated_targets
			? new \WP_Error( 'uccm_scan_no_targets', __( 'At least one valid public scan target is required.', 'uk-cookie-consent-manager' ), array( 'status' => 400 ) )
			: $validated_targets;
	}

	/**
	 * Return eligible HTML pages for the isolated browser check.
	 *
	 * @param array<int, mixed> $visited Persisted server-crawl page evidence.
	 * @return string[]
	 */
	public static function browser_targets( array $visited ): array {
		$targets          = array();
		$media_extensions = '/\\.(?:avif|bmp|gif|ico|jpe?g|pdf|png|svg|tiff?|webp|mp[34]|m4[av]|mov|avi|webm|wav|ogg|zip)(?:$|[?#])/i';

		foreach ( $visited as $page ) {
			if ( count( $targets ) >= self::BROWSER_MAX_TARGETS || ! is_array( $page ) ) {
				break;
			}

			$url          = esc_url_raw( (string) ( $page['url'] ?? '' ) );
			$status       = (int) ( $page['status'] ?? 0 );
			$content_type = strtolower( trim( (string) ( $page['content_type'] ?? '' ) ) );

			if ( '' === $url || 200 > $status || 399 < $status ) {
				continue;
			}

			if ( '' !== $content_type && ! str_contains( $content_type, 'text/html' ) && ! str_contains( $content_type, 'application/xhtml+xml' ) ) {
				continue;
			}

			if ( '' === $content_type && preg_match( $media_extensions, $url ) ) {
				continue;
			}

			$targets[] = $url;
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Normalise one browser observation.
	 *
	 * @param array<string, mixed> $observation Untrusted observation.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_observation( array $observation ): ?array {
		$type               = sanitize_key( (string) ( $observation['type'] ?? '' ) );
		$storage_key        = substr( sanitize_text_field( (string) ( $observation['storage_key'] ?? $observation['name'] ?? '' ) ), 0, 191 );
		$domain             = strtolower( substr( sanitize_text_field( (string) ( $observation['domain'] ?? '' ) ), 0, 191 ) );
		$source_url         = esc_url_raw( (string) ( $observation['source_url'] ?? '' ) );
		$duration           = substr( sanitize_text_field( (string) ( $observation['duration'] ?? '' ) ), 0, 100 );
		$category_candidate = sanitize_key( (string) ( $observation['category_candidate'] ?? '' ) );
		$source_urls        = is_array( $observation['source_urls'] ?? null ) ? $observation['source_urls'] : array( $source_url );
		$consent_states     = is_array( $observation['consent_states'] ?? null ) ? $observation['consent_states'] : array();
		$allowed_states     = array( 'pre-consent', 'reject', 'accept-all', 'functional', 'analytics', 'marketing' );

		if ( ! in_array( $category_candidate, array( 'necessary', 'functional', 'analytics', 'marketing' ), true ) ) {
			$category_candidate = '';
		}

		if ( ! in_array( $type, array( 'cookie', 'local_storage', 'session_storage', 'script', 'iframe', 'pixel' ), true ) || '' === $storage_key ) {
			return null;
		}

		$source_urls    = array_values(
			array_slice(
				array_unique(
					array_filter(
						array_map(
							static fn ( mixed $url ): string => esc_url_raw( (string) $url ),
							$source_urls
						)
					)
				),
				0,
				20
			)
		);
		$consent_states = array_values(
			array_filter(
				array_unique( array_map( 'sanitize_key', $consent_states ) ),
				static fn ( string $state ): bool => in_array( $state, $allowed_states, true )
			)
		);

		return array(
			'type'               => $type,
			'storage_key'        => $storage_key,
			'domain'             => $domain,
			'source_url'         => $source_urls[0] ?? $source_url,
			'source_urls'        => $source_urls,
			'source_count'       => min( self::BROWSER_MAX_TARGETS, max( count( $source_urls ), (int) ( $observation['source_count'] ?? 0 ) ) ),
			'consent_states'     => $consent_states,
			'duration'           => $duration,
			'storage_type'       => in_array( $type, array( 'cookie', 'local_storage', 'session_storage' ), true ) ? $type : 'other',
			'method'             => 'browser',
			'category_candidate' => $category_candidate,
		);
	}

	/**
	 * Return all Set-Cookie header values from a WordPress response.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return string[]
	 */
	private static function set_cookie_headers( mixed $response ): array {
		$header = (string) wp_remote_retrieve_header( $response, 'set-cookie' );

		if ( '' === trim( $header ) ) {
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

		if ( ! str_contains( $pair, '=' ) ) {
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
