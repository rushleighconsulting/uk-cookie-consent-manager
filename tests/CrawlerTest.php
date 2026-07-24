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
		$GLOBALS['uccm_test_posts']          = array();
		$GLOBALS['uccm_test_url_post_ids']   = array();
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

	public function test_inspection_ignores_media_archives_attachments_and_url_variants(): void {
		$GLOBALS['uccm_test_posts'][99] = (object) array( 'post_type' => 'attachment' );
		$GLOBALS['uccm_test_url_post_ids']['https://example.test/media-item'] = 99;
		$html = <<<'HTML'
<a href="/about/">About</a>
<a href="/about?utm_source=newsletter">Tracking variant</a>
<a href="/wp-content/uploads/photo.jpg">Full-size image</a>
<a href="/category/news/">Category</a>
<a href="/page/2/">Pagination</a>
<a href="/attachment/photo/">Attachment route</a>
<a href="/media-item">Attachment record</a>
<a href="/product?colour=red">Red product</a>
<a href="/product?colour=blue">Blue product</a>
HTML;

		$inspection = Crawler::inspect( $html, 'https://example.test/' );

		self::assertSame(
			array(
				'https://example.test/about',
				'https://example.test/product?colour=red',
				'https://example.test/product?colour=blue',
			),
			$inspection['accepted']
		);
		self::assertSame( 1, $inspection['ignored']['media'] );
		self::assertSame( 2, $inspection['ignored']['archive'] );
		self::assertSame( 2, $inspection['ignored']['attachment'] );
		self::assertSame( 1, $inspection['ignored']['variant'] );
	}

	public function test_canonicalisation_removes_tracking_only_queries_but_preserves_material_values(): void {
		self::assertSame(
			'https://example.test/product?colour=red&size=large',
			Crawler::canonicalize(
				'/product/?utm_campaign=sale&size=large&colour=red&gclid=123',
				'https://example.test/'
			)
		);
	}
}
