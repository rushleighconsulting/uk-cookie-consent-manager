<?php
/**
 * Hybrid scanner tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Activator;
use UCCM\Scanner;
use UCCM\Settings;

final class ScannerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                         = new wpdb();
		$GLOBALS['uccm_test_options']            = array();
		$GLOBALS['uccm_test_scheduled_hooks']    = array();
		$GLOBALS['uccm_test_schedule_events']    = array();
		$GLOBALS['uccm_test_spawn_cron_calls']   = array();
		$GLOBALS['uccm_test_cleared_hooks']      = array();
		$GLOBALS['uccm_test_capabilities']       = array();
		$GLOBALS['uccm_test_transients']         = array();
		$GLOBALS['uccm_test_http_validity']      = true;
		$GLOBALS['uccm_test_remote_responses']   = array();
		$GLOBALS['uccm_test_db_rows']            = array();
		$GLOBALS['uccm_test_db_row_queue']       = array();
		$GLOBALS['uccm_test_posts']              = array();
		$GLOBALS['uccm_test_url_post_ids']       = array();
		$GLOBALS['uccm_test_get_posts_arguments'] = array();
	}

	public function test_monthly_schedule_is_idempotent_and_deactivation_clears_it(): void {
		Scanner::schedule();
		Scanner::schedule();

		self::assertCount( 1, $GLOBALS['uccm_test_schedule_events'] );
		self::assertSame( Scanner::RECURRENCE, $GLOBALS['uccm_test_schedule_events'][0]['recurrence'] );

		Activator::deactivate();

		self::assertContains( Scanner::HOOK, $GLOBALS['uccm_test_cleared_hooks'] );
	}

	public function test_monthly_recurrence_is_declared(): void {
		$schedules = Scanner::cron_schedules( array() );

		self::assertSame( 30 * DAY_IN_SECONDS, $schedules[ Scanner::RECURRENCE ]['interval'] );
		self::assertSame( 'Every 30 days', $schedules[ Scanner::RECURRENCE ]['display'] );
	}

	public function test_target_validation_rejects_cross_origin_and_private_networks(): void {
		self::assertSame(
			'https://example.test/privacy',
			Scanner::validate_target( 'https://example.test/privacy', 'https://example.test/' )
		);
		self::assertSame(
			'uccm_scan_disallowed_target',
			Scanner::validate_target( 'https://tracker.test/', 'https://example.test/' )->get_error_code()
		);
		self::assertSame(
			'uccm_scan_private_target',
			Scanner::validate_target( 'http://192.168.1.10/', 'http://192.168.1.10/' )->get_error_code()
		);
		self::assertSame(
			'uccm_scan_disallowed_target',
			Scanner::validate_target( 'https://user:secret@example.test/', 'https://example.test/' )->get_error_code()
		);
	}

	public function test_browser_observations_require_authentication_and_are_allowlisted(): void {
		$payload = array(
			'observations' => array(
				array(
					'type'        => 'local_storage',
					'storage_key' => 'preferences',
					'domain'      => 'example.test',
					'source_url'  => 'https://example.test/app.js',
				),
				array(
					'type'        => 'form_value',
					'storage_key' => 'email',
				),
			),
		);

		$denied = Scanner::accept_browser_observations( $payload, 'wrong-token' );
		$valid  = Scanner::accept_browser_observations( $payload, Scanner::runner_token() );

		self::assertSame( 'uccm_runner_unauthorised', $denied->get_error_code() );
		self::assertCount( 1, $valid );
		self::assertSame( 'local_storage', $valid[0]['storage_type'] );
	}

	public function test_manual_scan_creates_bounded_run_and_hybrid_findings(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$fetcher = static fn (): array => array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'set-cookie' => 'server_id=abc; Path=/; HttpOnly; Max-Age=3600',
			),
		);
		$payload = array(
			'token'        => Scanner::runner_token(),
			'observations' => array(
				array(
					'type'        => 'local_storage',
					'storage_key' => 'preferences',
					'domain'      => 'example.test',
					'source_url'  => 'https://example.test/app.js',
				),
				array(
					'type'        => 'script',
					'storage_key' => 'analytics-loader',
					'domain'      => 'analytics.example',
					'source_url'  => 'https://analytics.example/tracker.js',
				),
			),
		);

		$run_id = Scanner::run( true, array( 'https://example.test/' ), $fetcher, $payload );

		self::assertSame( 1, $run_id );
		self::assertCount( 4, $GLOBALS['wpdb']->inserts );
		self::assertSame( 'wp_uccm_scan_runs', $GLOBALS['wpdb']->inserts[0]['table'] );
		self::assertSame( 'wp_uccm_scan_findings', $GLOBALS['wpdb']->inserts[1]['table'] );
		self::assertCount( 1, $GLOBALS['wpdb']->updates );

		$summary = json_decode( (string) $GLOBALS['wpdb']->updates[0]['data']['summary'], true );
		$pages   = json_decode( (string) $GLOBALS['wpdb']->updates[0]['data']['pages_visited'], true );

		self::assertSame( 3, $summary['findings'] );
		self::assertCount( 3, $summary['limitations'] );
		self::assertSame( 'https://example.test/', $pages[0]['url'] );
		self::assertSame( 200, $pages[0]['status'] );
		self::assertArrayNotHasKey( 'consents', $payload );
	}

	public function test_invalid_target_is_rejected_and_recorded_as_a_failed_scan(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;

		$result = Scanner::run( true, array( 'http://127.0.0.1/' ) );

		self::assertSame( 'uccm_scan_disallowed_target', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		self::assertSame( 'wp_uccm_scan_runs', $GLOBALS['wpdb']->inserts[0]['table'] );
		self::assertSame( 'failed', $GLOBALS['wpdb']->inserts[0]['data']['status'] );
		self::assertSame( 'uccm_scan_disallowed_target', $GLOBALS['wpdb']->inserts[0]['data']['error_code'] );

		$summary = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['summary'], true );
		self::assertSame( 'http://127.0.0.1/', $summary['warnings'][0]['url'] );
	}

	public function test_scheduled_scan_records_invalid_legacy_configuration(): void {
		$GLOBALS['uccm_test_options'][ Settings::OPTION_NAME ] = Settings::sanitize(
			array( 'scan_urls' => array( 'https://tracker.test/pixel' ) ),
			array()
		);

		Scanner::run_scheduled();

		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		self::assertSame( 'failed', $GLOBALS['wpdb']->inserts[0]['data']['status'] );
		self::assertSame( 'uccm_scan_disallowed_target', $GLOBALS['wpdb']->inserts[0]['data']['error_code'] );

		$summary = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['summary'], true );
		self::assertSame( 'https://tracker.test/pixel', $summary['warnings'][0]['url'] );
	}

	public function test_asynchronous_start_persists_frontier_and_queues_first_batch(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;

		$run_id = Scanner::start();

		self::assertSame( 1, $run_id );
		self::assertSame( 'queued', $GLOBALS['wpdb']->inserts[0]['data']['status'] );

		$coverage = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['coverage'], true );
		self::assertSame( array( 'https://example.test/' ), $coverage['frontier'] );
		self::assertSame( 1024, $coverage['max_pages'] );
		self::assertSame( 5, $coverage['batch_size'] );
		self::assertSame( Scanner::BATCH_HOOK, $GLOBALS['uccm_test_schedule_events'][0]['hook'] );
		self::assertSame( array( 1 ), $GLOBALS['uccm_test_schedule_events'][0]['arguments'] );
		self::assertLessThanOrEqual( time(), $GLOBALS['uccm_test_schedule_events'][0]['timestamp'] );
		self::assertCount( 1, $GLOBALS['uccm_test_spawn_cron_calls'] );
	}

	public function test_start_seeds_only_eligible_published_wordpress_content(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$GLOBALS['uccm_test_posts'] = array(
			11 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'page',
				'post_password' => '',
				'permalink'     => 'https://example.test/unlinked-noindex/',
				'robots'        => 'noindex,nofollow',
			),
			12 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => '',
				'permalink'     => 'https://example.test/news/story/',
			),
			13 => (object) array(
				'post_status'   => 'draft',
				'post_type'     => 'page',
				'post_password' => '',
				'permalink'     => 'https://example.test/draft/',
			),
			14 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'page',
				'post_password' => 'secret',
				'permalink'     => 'https://example.test/protected/',
			),
			15 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'attachment',
				'post_password' => '',
				'permalink'     => 'https://example.test/media/photo/',
			),
			16 => (object) array(
				'post_status'   => 'private',
				'post_type'     => 'post',
				'post_password' => '',
				'permalink'     => 'https://example.test/private/',
			),
			17 => (object) array(
				'post_status'   => 'pending',
				'post_type'     => 'post',
				'post_password' => '',
				'permalink'     => 'https://example.test/pending/',
			),
			18 => (object) array(
				'post_status'   => 'auto-draft',
				'post_type'     => 'page',
				'post_password' => '',
				'permalink'     => 'https://example.test/auto-draft/',
			),
			19 => (object) array(
				'post_status'   => 'future',
				'post_type'     => 'post',
				'post_password' => '',
				'permalink'     => 'https://example.test/scheduled/',
			),
			20 => (object) array(
				'post_status'   => 'trash',
				'post_type'     => 'page',
				'post_password' => '',
				'permalink'     => 'https://example.test/trashed/',
			),
		);
		$GLOBALS['uccm_test_options'][ Settings::OPTION_NAME ] = Settings::sanitize(
			array(
				'scan_page_limit' => 10,
				'scan_urls'       => array( 'https://example.test/category/news/' ),
			),
			array()
		);

		Scanner::start();

		$coverage = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['coverage'], true );
		self::assertSame(
			array(
				'https://example.test/',
				'https://example.test/unlinked-noindex',
				'https://example.test/news/story',
				'https://example.test/category/news',
			),
			$coverage['frontier']
		);
		self::assertSame( 2, $coverage['wordpress_content_count'] );
		self::assertSame( 0, $coverage['accepted_link_count'] );
		self::assertSame( 'publish', $GLOBALS['uccm_test_get_posts_arguments'][0]['post_status'] );
		self::assertFalse( $GLOBALS['uccm_test_get_posts_arguments'][0]['has_password'] );
	}

	public function test_background_batch_discovers_links_and_persists_resumable_progress(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$run_id = Scanner::start();
		$run     = array_merge( array( 'id' => $run_id ), $GLOBALS['wpdb']->inserts[0]['data'] );
		$GLOBALS['uccm_test_db_rows'] = array( $run );
		$GLOBALS['uccm_test_scheduled_hooks'] = array();
		$fetcher = static function ( string $url, array $arguments ): array {
			unset( $arguments );
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html' ),
				'body'     => 'https://example.test/' === $url
					? '<a href="/about">About</a><a href="/#top">Home</a>'
					: '<a href="/">Home</a>',
			);
		};

		$events_before = count( $GLOBALS['uccm_test_schedule_events'] );
		self::assertTrue( Scanner::process_batch( $run_id, $fetcher, false ) );
		self::assertCount( $events_before, $GLOBALS['uccm_test_schedule_events'] );
		$first_update = $GLOBALS['wpdb']->updates[0]['data'];
		$coverage     = json_decode( (string) $first_update['coverage'], true );
		self::assertSame( 'running', $first_update['status'] );
		self::assertSame( 1, $coverage['visited_count'] );
		self::assertSame( 2, $coverage['discovered_count'] );
		self::assertSame( 1, $coverage['accepted_link_count'] );
		self::assertSame( 1, $coverage['ignored_counts']['variant'] );
		self::assertSame( array( 'https://example.test/about' ), $coverage['frontier'] );

		$GLOBALS['uccm_test_db_rows'] = array( array_merge( $run, $first_update ) );
		$GLOBALS['uccm_test_scheduled_hooks'] = array();
		self::assertTrue( Scanner::process_batch( $run_id, $fetcher ) );
		$second_update = $GLOBALS['wpdb']->updates[1]['data'];
		$coverage      = json_decode( (string) $second_update['coverage'], true );
		self::assertSame( 'completed', $second_update['status'] );
		self::assertSame( 2, $coverage['visited_count'] );
		self::assertSame( 0, $coverage['remaining_count'] );
	}

	public function test_browser_observations_are_attached_to_a_completed_run(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$coverage = array(
			'browser_status' => 'not-run',
			'visited_count'  => 1,
		);
		$summary = array(
			'findings'       => 0,
			'finding_counts' => array( 'actionable' => 0, 'new' => 0, 'changed' => 0, 'duplicates' => 0, 'unchanged' => 0 ),
			'warnings'       => array(),
		);
		$run = array(
			'id'            => 7,
			'status'        => 'completed',
			'coverage'      => wp_json_encode( $coverage ),
			'pages_visited' => wp_json_encode( array() ),
			'summary'       => wp_json_encode( $summary ),
		);
		$GLOBALS['uccm_test_db_row_queue'] = array( $run, null );
		$payload = array(
			'status'         => 'completed',
			'target_count'   => 2,
			'scenario_count' => 6,
			'observations'   => array(
				array(
					'type'           => 'local_storage',
					'storage_key'    => 'preferences',
					'domain'         => 'example.test',
					'source_url'     => 'https://example.test/one',
					'source_urls'    => array( 'https://example.test/one' ),
					'consent_states' => array( 'reject', 'accept-all' ),
				),
				array(
					'type'           => 'local_storage',
					'storage_key'    => 'preferences',
					'domain'         => 'example.test',
					'source_url'     => 'https://example.test/two',
					'source_urls'    => array( 'https://example.test/two' ),
					'consent_states' => array( 'analytics' ),
				),
			),
		);

		$counts = Scanner::record_browser_observations( 7, $payload );

		self::assertSame( 1, $counts['actionable'] );
		$last_update = $GLOBALS['wpdb']->updates[ count( $GLOBALS['wpdb']->updates ) - 1 ]['data'];
		$updated_coverage = json_decode( (string) $last_update['coverage'], true );
		self::assertSame( 'completed', $updated_coverage['browser_status'] );
		self::assertSame( 1, $updated_coverage['browser_observation_count'] );
		self::assertSame( 2, $updated_coverage['browser_target_count'] );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		$after = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['after_data'], true );
		self::assertSame( 2, $after['source_count'] );
		self::assertSame( array( 'reject', 'accept-all', 'analytics' ), $after['consent_states'] );
	}


	public function test_browser_targets_include_only_successful_html_pages(): void {
		$targets = Scanner::browser_targets(
			array(
				array( 'url' => 'https://example.test/page', 'status' => 200, 'content_type' => 'text/html; charset=UTF-8' ),
				array( 'url' => 'https://example.test/gallery/photo.jpg', 'status' => 200, 'content_type' => 'image/jpeg' ),
				array( 'url' => 'https://example.test/manual.pdf', 'status' => 200 ),
				array( 'url' => 'https://example.test/missing', 'status' => 404, 'content_type' => 'text/html' ),
			)
		);

		self::assertSame( array( 'https://example.test/page' ), $targets );
	}

	public function test_browser_running_status_is_persisted_without_findings(): void {
		$GLOBALS['uccm_test_capabilities']['run_uccm_scans'] = true;
		$run = array(
			'id'            => 8,
			'status'        => 'completed',
			'coverage'      => wp_json_encode( array( 'browser_status' => 'not-run' ) ),
			'pages_visited' => wp_json_encode( array() ),
			'summary'       => wp_json_encode( array( 'findings' => 0, 'finding_counts' => array() ) ),
		);
		$GLOBALS['uccm_test_db_row_queue'] = array( $run );

		$counts = Scanner::record_browser_observations(
			8,
			array(
				'status'         => 'running',
				'target_count'   => 2,
				'scenario_count' => 6,
				'observations'   => array(),
			)
		);

		self::assertSame( 0, $counts['actionable'] );
		self::assertCount( 0, $GLOBALS['wpdb']->inserts );
		$coverage = json_decode( (string) $GLOBALS['wpdb']->updates[0]['data']['coverage'], true );
		self::assertSame( 'running', $coverage['browser_status'] );
		self::assertSame( 6, $coverage['browser_scenario_count'] );
	}

public function test_configured_targets_are_sanitised_to_the_temporary_ceiling(): void {
		$urls = array();

		self::assertSame( 1024, Scanner::MAX_TARGETS );

		for ( $index = 0; $index < Scanner::MAX_TARGETS + 10; ++$index ) {
			$urls[] = 'https://example.test/page-' . $index;
		}

		$settings = Settings::sanitize( array( 'scan_urls' => implode( "\n", $urls ) ), array() );

		self::assertCount( Scanner::MAX_TARGETS - 1, $settings['scan_urls'] );
		self::assertSame( 'https://example.test/page-0', $settings['scan_urls'][0] );
	}
}
