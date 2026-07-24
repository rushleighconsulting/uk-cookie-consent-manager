<?php
/**
 * Safe same-origin URL discovery for asynchronous scans.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts, canonicalises, classifies and filters crawl targets without fetching them.
 */
final class Crawler {

	/**
	 * Paths excluded from crawling on a clean installation.
	 */
	public const DEFAULT_EXCLUDED_PATHS = array(
		'/wp-admin/*',
		'/wp-login.php',
		'/wp-json/*',
		'/feed/*',
		'*/feed/',
	);

	/**
	 * Empty ignored-link counters.
	 *
	 * @return array<string, int>
	 */
	public static function empty_ignored_counts(): array {
		return array(
			'media'      => 0,
			'attachment' => 0,
			'archive'    => 0,
			'variant'    => 0,
			'excluded'   => 0,
			'invalid'    => 0,
		);
	}

	/**
	 * Discover unique eligible links in one HTML response.
	 *
	 * @param string   $html              Bounded response body.
	 * @param string   $base_url          URL that supplied the response.
	 * @param string[] $excluded_patterns Administrator-configured path patterns.
	 * @return string[]
	 */
	public static function discover( string $html, string $base_url, array $excluded_patterns = array() ): array {
		$inspection = self::inspect( $html, $base_url, $excluded_patterns );
		return $inspection['accepted'];
	}

	/**
	 * Inspect links and return accepted URLs with non-sensitive classification counts.
	 *
	 * @param string   $html              Bounded response body.
	 * @param string   $base_url          URL that supplied the response.
	 * @param string[] $excluded_patterns Administrator-configured path patterns.
	 * @return array{accepted: string[], ignored: array<string, int>}
	 */
	public static function inspect( string $html, string $base_url, array $excluded_patterns = array() ): array {
		$result = array(
			'accepted' => array(),
			'ignored'  => self::empty_ignored_counts(),
		);

		if ( '' === trim( $html ) ) {
			return $result;
		}

		$matches = array();
		preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches );
		$accepted = array();

		foreach ( array_slice( $matches[2], 0, Scanner::MAX_TARGETS * 2 ) as $href ) {
			$target = self::canonicalize( (string) $href, $base_url );

			if ( is_wp_error( $target ) ) {
				++$result['ignored']['invalid'];
				continue;
			}

			if ( self::is_excluded( $target, $excluded_patterns ) ) {
				++$result['ignored']['excluded'];
				continue;
			}

			$class = self::classify( $target );

			if ( 'html' !== $class ) {
				++$result['ignored'][ $class ];
				continue;
			}

			if ( isset( $accepted[ $target ] ) ) {
				++$result['ignored']['variant'];
				continue;
			}

			$accepted[ $target ] = true;
		}

		$result['accepted'] = array_keys( $accepted );
		return $result;
	}

	/**
	 * Resolve and canonicalise one discovered href.
	 *
	 * @param string $href     Raw href attribute.
	 * @param string $base_url URL that contained the link.
	 * @return string|\WP_Error
	 */
	public static function canonicalize( string $href, string $base_url ): string|\WP_Error {
		$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( '' === $href || '#' === $href[0] || preg_match( '#^(?:mailto|tel|javascript|data):#i', $href ) ) {
			return new \WP_Error( 'uccm_crawl_unsupported_link', __( 'The discovered link is not crawlable.', 'uk-cookie-consent-manager' ) );
		}

		$base = wp_parse_url( $base_url );

		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return new \WP_Error( 'uccm_crawl_invalid_base', __( 'The crawl base URL is invalid.', 'uk-cookie-consent-manager' ) );
		}

		if ( str_starts_with( $href, '//' ) ) {
			$candidate = strtolower( (string) $base['scheme'] ) . ':' . $href;
		} elseif ( preg_match( '#^https?://#i', $href ) ) {
			$candidate = $href;
		} else {
			$relative = wp_parse_url( $href );

			if ( false === $relative ) {
				return new \WP_Error( 'uccm_crawl_invalid_link', __( 'The discovered link is invalid.', 'uk-cookie-consent-manager' ) );
			}

			$base_path = (string) ( $base['path'] ?? '/' );
			$path      = (string) ( $relative['path'] ?? '' );

			if ( '' === $path ) {
				$path = $base_path;
			} elseif ( '/' !== $path[0] ) {
				$directory = preg_replace( '#/[^/]*$#', '/', $base_path );
				$path      = ( is_string( $directory ) ? $directory : '/' ) . $path;
			}

			$authority = strtolower( (string) $base['scheme'] ) . '://' . strtolower( rtrim( (string) $base['host'], '.' ) );
			$port      = (int) ( $base['port'] ?? 0 );

			if ( 0 < $port && ! ( 80 === $port && 'http' === strtolower( (string) $base['scheme'] ) ) && ! ( 443 === $port && 'https' === strtolower( (string) $base['scheme'] ) ) ) {
				$authority .= ':' . $port;
			}

			$candidate = $authority . self::normalize_path( $path );
			$query     = self::normalize_query( (string) ( $relative['query'] ?? '' ) );

			if ( '' !== $query ) {
				$candidate .= '?' . $query;
			}
		}

		$parts = wp_parse_url( $candidate );

		if ( ! is_array( $parts ) ) {
			return new \WP_Error( 'uccm_crawl_invalid_link', __( 'The discovered link is invalid.', 'uk-cookie-consent-manager' ) );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$path   = self::normalize_path( (string) ( $parts['path'] ?? '/' ) );
		$port   = (int) ( $parts['port'] ?? 0 );
		$url    = $scheme . '://' . $host;

		if ( 0 < $port && ! ( 80 === $port && 'http' === $scheme ) && ! ( 443 === $port && 'https' === $scheme ) ) {
			$url .= ':' . $port;
		}

		$url  .= $path;
		$query = self::normalize_query( (string) ( $parts['query'] ?? '' ) );

		if ( '' !== $query ) {
			$url .= '?' . $query;
		}

		return Scanner::validate_target( $url );
	}

	/**
	 * Classify an accepted same-origin URL.
	 *
	 * Explicit administrator targets bypass this discovery-only classification.
	 *
	 * @param string $url Canonical URL.
	 * @return string html, media, attachment or archive.
	 */
	public static function classify( string $url ): string {
		$path  = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$query = strtolower( (string) wp_parse_url( $url, PHP_URL_QUERY ) );

		if ( preg_match( '/\.(?:avif|bmp|css|csv|docx?|eot|gif|ico|jpe?g|js|json|m4a|mov|mp3|mp4|mpeg|ogg|ogv|pdf|png|pptx?|svg|tiff?|ttf|wav|webm|webp|woff2?|xlsx?|xml|zip)$/i', $path ) ) {
			return 'media';
		}

		if ( preg_match( '#(?:^|/)attachment(?:/|$)#i', $path ) || preg_match( '/(?:^|&)attachment_id=\d+(?:&|$)/i', $query ) ) {
			return 'attachment';
		}

		if ( function_exists( 'url_to_postid' ) && function_exists( 'get_post_type' ) ) {
			$post_id = (int) url_to_postid( $url );

			if ( 0 < $post_id && 'attachment' === get_post_type( $post_id ) ) {
				return 'attachment';
			}
		}

		if (
			preg_match( '#/(?:category|tag|author|search)/#i', $path )
			|| preg_match( '#/\d{4}(?:/\d{1,2})?(?:/\d{1,2})?/?$#', $path )
			|| preg_match( '#/page/\d+/?$#i', $path )
			|| preg_match( '/(?:^|&)(?:s|paged|cat|tag|author|m|year|monthnum|day)=/i', $query )
		) {
			return 'archive';
		}

		return 'html';
	}

	/**
	 * Whether a canonical URL matches an excluded path pattern.
	 *
	 * @param string   $url      Canonical URL.
	 * @param string[] $patterns Path patterns supporting * wildcards.
	 */
	public static function is_excluded( string $url, array $patterns ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		foreach ( array_unique( array_merge( self::DEFAULT_EXCLUDED_PATHS, $patterns ) ) as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			$expression = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

			if ( 1 === preg_match( $expression, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collapse dot segments, repeated separators and harmless trailing slashes.
	 *
	 * @param string $path URL path.
	 */
	private static function normalize_path( string $path ): string {
		$segments = explode( '/', '/' . ltrim( $path, '/' ) );
		$clean    = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $clean );
				continue;
			}

			$clean[] = $segment;
		}

		return '/' . implode( '/', $clean );
	}

	/**
	 * Remove known tracking parameters while preserving material query values.
	 *
	 * @param string $query Raw query string.
	 */
	private static function normalize_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$pairs = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$key = explode( '=', $pair, 2 )[0];
			$key = strtolower( rawurldecode( str_replace( '+', ' ', $key ) ) );

			if ( preg_match( '/^(?:utm_[a-z0-9_]+|gclid|dclid|fbclid|msclkid|mc_cid|mc_eid|_ga)$/', $key ) ) {
				continue;
			}

			$pairs[] = $pair;
		}

		sort( $pairs, SORT_STRING );
		return implode( '&', $pairs );
	}
}
