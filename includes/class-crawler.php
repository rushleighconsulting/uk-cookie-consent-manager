<?php
/**
 * Safe same-origin URL discovery for asynchronous scans.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts, canonicalises and filters crawl targets without fetching them.
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
	 * Discover unique eligible links in one HTML response.
	 *
	 * @param string   $html              Bounded response body.
	 * @param string   $base_url          URL that supplied the response.
	 * @param string[] $excluded_patterns Administrator-configured path patterns.
	 * @return string[]
	 */
	public static function discover( string $html, string $base_url, array $excluded_patterns = array() ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$matches = array();
		preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches );
		$links = array();

		foreach ( array_slice( $matches[2], 0, Scanner::MAX_TARGETS * 2 ) as $href ) {
			$target = self::canonicalize( (string) $href, $base_url );

			if ( is_wp_error( $target ) || self::is_excluded( $target, $excluded_patterns ) ) {
				continue;
			}

			$links[] = $target;
		}

		return array_values( array_unique( $links ) );
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

			if ( isset( $relative['query'] ) && '' !== (string) $relative['query'] ) {
				$candidate .= '?' . $relative['query'];
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

		$url .= $path;

		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		return Scanner::validate_target( $url );
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
	 * Collapse dot segments and repeated separators in a URL path.
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

		$normalized = '/' . implode( '/', $clean );

		if ( '/' !== $path && str_ends_with( $path, '/' ) ) {
			$normalized .= '/';
		}

		return $normalized;
	}
}
