<?php
/**
 * Same-origin crawler tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Crawler;

final class CrawlerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_http_validity'] = true;
	}

	public function test_discovery_resolves_relative_links_and_removes_duplicates_and_fragments(): void {
		$html = <<<'HTML'
<a href="/about#team">About</a>
<a href="https://EXAMPLE.test/about">Duplicate</a>
<a href="../contact">Contact</a>
<a href="mailto:hello@example.test">Email</a>
HTML;

		$links = Crawler::discover( $html, 'https://example.test/services/item/' );

		self::assertSame(
			array(
				'https://example.test/about',
				'https://example.test/services/contact',
			),
			$links
		);
	}

	public function test_discovery_rejects_cross_origin_and_excluded_paths(): void {
		$html = <<<'HTML'
<a href="https://tracker.test/pixel">External</a>
<a href="/wp-admin/options.php">Admin</a>
<a href="/members/private">Private section</a>
<a href="/public">Public</a>
HTML;

		$links = Crawler::discover( $html, 'https://example.test/', array( '/members/*' ) );

		self::assertSame( array( 'https://example.test/public' ), $links );
	}

	public function test_canonicalisation_normalises_dot_segments_and_default_ports(): void {
		self::assertSame(
			'https://example.test/a/c?view=1',
			Crawler::canonicalize( 'https://EXAMPLE.test:443/a/b/../c?view=1#section', 'https://example.test/' )
		);
	}
}
