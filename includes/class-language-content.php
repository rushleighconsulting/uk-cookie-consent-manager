<?php
/**
 * Language-aware public consent content.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves locally authored consent content without external translation calls.
 */
final class Language_Content {

	/**
	 * Fields administrators may override for each language.
	 */
	private const TEXT_FIELDS = array(
		'banner_title',
		'banner_copy',
		'preferences_title',
		'preferences_intro',
		'cookie_copy',
		'accept_all',
		'reject_optional',
		'manage_preferences',
		'save_choices',
		'withdraw_consent',
		'close_preferences',
		'settings_label',
		'policy_link_label',
	);

	/**
	 * Normalise a WordPress locale or HTML language tag.
	 */
	public static function normalise_locale( string $locale ): string {
		$locale = str_replace( '-', '_', trim( $locale ) );
		$locale = (string) preg_replace( '/[^A-Za-z0-9_]/', '', $locale );

		if ( '' === $locale ) {
			return '';
		}

		$parts = array_values( array_filter( explode( '_', $locale ) ) );

		if ( array() === $parts ) {
			return '';
		}

		$parts[0] = strtolower( $parts[0] );

		if ( isset( $parts[1] ) ) {
			$parts[1] = 2 === strlen( $parts[1] ) ? strtoupper( $parts[1] ) : ucfirst( strtolower( $parts[1] ) );
		}

		return implode( '_', $parts );
	}

	/**
	 * Resolve the language of the current public page.
	 *
	 * The uccm_public_locale filter is the stable integration point. WPML and
	 * Polylang are consulted when present, without making either a dependency.
	 */
	public static function page_locale(): string {
		$locale = function_exists( 'determine_locale' )
			? (string) determine_locale()
			: ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_GB' );

		$wpml_locale = apply_filters( 'wpml_current_language', null );

		if ( is_string( $wpml_locale ) && '' !== trim( $wpml_locale ) ) {
			$locale = $wpml_locale;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$polylang_locale = pll_current_language( 'locale' );

			if ( is_string( $polylang_locale ) && '' !== trim( $polylang_locale ) ) {
				$locale = $polylang_locale;
			}
		}

		/**
		 * Filters the language used for public consent content.
		 *
		 * @param string $locale WordPress, WPML or Polylang locale.
		 */
		$locale = (string) apply_filters( 'uccm_public_locale', $locale );
		$locale = self::normalise_locale( $locale );

		return '' === $locale ? 'en_GB' : $locale;
	}

	/**
	 * Return the complete default public wording.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults( int $lifetime_days ): array {
		$categories = Consent_State::categories();
		$banner     = sprintf(
			/* translators: %d: configured consent lifetime. */
			_n(
				'We use one necessary cookie to remember your choice for %d day. It is set whether you accept or reject optional cookies, so we do not ask you again. With your permission, we may also use optional cookies for functionality, analytics and marketing. You may change your choice at any time by clicking the little cookie logo.',
				'We use one necessary cookie to remember your choice for %d days. It is set whether you accept or reject optional cookies, so we do not ask you again. With your permission, we may also use optional cookies for functionality, analytics and marketing. You may change your choice at any time by clicking the little cookie logo.',
				$lifetime_days,
				'uk-cookie-consent-manager'
			),
			$lifetime_days
		);
		$cookie     = sprintf(
			/* translators: %d: configured consent lifetime. */
			_n(
				'We set one necessary cookie. This cookie remembers your cookie choices for %d day, and is set when you accept, reject, or change your cookie options. You may reject any other cookies.',
				'We set one necessary cookie. This cookie remembers your cookie choices for %d days, and is set when you accept, reject, or change your cookie options. You may reject any other cookies.',
				$lifetime_days,
				'uk-cookie-consent-manager'
			),
			$lifetime_days
		);

		return array(
			'wording_version'    => '1',
			'policy_url'         => '',
			'direction'          => 'auto',
			'banner_title'       => __( 'Your cookie choices', 'uk-cookie-consent-manager' ),
			'banner_copy'        => $banner,
			'preferences_title'  => __( 'Cookie preferences', 'uk-cookie-consent-manager' ),
			'preferences_intro'  => __( 'Choose which optional cookie categories this website may use. Necessary cookies are always active.', 'uk-cookie-consent-manager' ),
			'cookie_copy'        => $cookie,
			'accept_all'         => __( 'Accept all', 'uk-cookie-consent-manager' ),
			'reject_optional'    => __( 'Reject non-essential', 'uk-cookie-consent-manager' ),
			'manage_preferences' => __( 'Manage preferences', 'uk-cookie-consent-manager' ),
			'save_choices'       => __( 'Save choices', 'uk-cookie-consent-manager' ),
			'withdraw_consent'   => __( 'Withdraw optional consent', 'uk-cookie-consent-manager' ),
			'close_preferences'  => __( 'Close cookie preferences', 'uk-cookie-consent-manager' ),
			'settings_label'     => __( 'Cookie settings', 'uk-cookie-consent-manager' ),
			'policy_link_label'  => __( 'Cookie policy', 'uk-cookie-consent-manager' ),
			'categories'         => $categories,
		);
	}

	/**
	 * Sanitise a complete administrator-submitted language catalogue.
	 *
	 * @param mixed $input Untrusted catalogue.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitise_catalog( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$catalog = array();

		foreach ( array_slice( $input, 0, 20, true ) as $candidate_locale => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$locale = self::normalise_locale( (string) ( $candidate['locale'] ?? $candidate_locale ) );

			if ( '' === $locale ) {
				continue;
			}

			$content = array(
				'wording_version' => substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $candidate['wording_version'] ?? '1' ) ), 0, 40 ),
				'policy_url'      => esc_url_raw( (string) ( $candidate['policy_url'] ?? '' ) ),
				'direction'       => in_array( (string) ( $candidate['direction'] ?? 'auto' ), array( 'auto', 'ltr', 'rtl' ), true )
					? (string) $candidate['direction']
					: 'auto',
			);

			foreach ( self::TEXT_FIELDS as $field ) {
				$content[ $field ] = sanitize_textarea_field( (string) ( $candidate[ $field ] ?? '' ) );
			}

			$content['categories'] = array();

			foreach ( array( 'necessary', 'functional', 'analytics', 'marketing' ) as $category ) {
				$submitted_category               = is_array( $candidate['categories'][ $category ] ?? null ) ? $candidate['categories'][ $category ] : array();
				$content['categories'][ $category ] = array(
					'label'       => sanitize_text_field( (string) ( $submitted_category['label'] ?? '' ) ),
					'description' => sanitize_textarea_field( (string) ( $submitted_category['description'] ?? '' ) ),
					'required'    => 'necessary' === $category,
				);
			}

			$catalog[ $locale ] = $content;
		}

		return $catalog;
	}

	/**
	 * Return a cache-safe catalogue containing every locally configured language.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function catalog(): array {
		$settings       = Settings::current();
		$lifetime       = max( 1, min( 730, (int) ( $settings['consent_lifetime_days'] ?? 180 ) ) );
		$default_locale = self::normalise_locale( (string) ( $settings['default_content_locale'] ?? 'en_GB' ) );
		$default_locale = '' === $default_locale ? 'en_GB' : $default_locale;
		$base           = self::defaults( $lifetime );
		$stored         = self::sanitise_catalog( $settings['language_content'] ?? array() );
		$catalog        = array( $default_locale => $base );

		foreach ( $stored as $locale => $content ) {
			$catalog[ $locale ] = self::merge_content( $base, $content, $lifetime );
		}

		return $catalog;
	}

	/**
	 * Resolve content and fallback evidence for one locale.
	 *
	 * @return array{locale: string, requestedLocale: string, fallback: bool, direction: string, content: array<string, mixed>}
	 */
	public static function resolve( ?string $requested_locale = null ): array {
		$settings       = Settings::current();
		$catalog        = self::catalog();
		$requested      = self::normalise_locale( null === $requested_locale ? self::page_locale() : $requested_locale );
		$default_locale = self::normalise_locale( (string) ( $settings['default_content_locale'] ?? 'en_GB' ) );
		$default_locale = isset( $catalog[ $default_locale ] ) ? $default_locale : (string) array_key_first( $catalog );
		$locale         = self::matching_locale( $requested, array_keys( $catalog ) ) ?? $default_locale;
		$content        = $catalog[ $locale ];
		$direction      = (string) ( $content['direction'] ?? 'auto' );

		if ( 'auto' === $direction ) {
			$direction = self::is_rtl_locale( $locale ) ? 'rtl' : 'ltr';
		}

		return array(
			'locale'          => $locale,
			'requestedLocale' => $requested,
			'fallback'        => $locale !== $requested,
			'direction'       => $direction,
			'content'         => $content,
		);
	}

	/**
	 * Return administrator-facing completeness diagnostics.
	 *
	 * @return array<string, string[]>
	 */
	public static function diagnostics(): array {
		$settings = Settings::current();
		$stored   = self::sanitise_catalog( $settings['language_content'] ?? array() );
		$missing  = array();

		foreach ( $stored as $locale => $content ) {
			$fields = array();

			foreach ( array_merge( array( 'wording_version', 'banner_title', 'banner_copy', 'preferences_title', 'preferences_intro', 'cookie_copy' ), self::TEXT_FIELDS ) as $field ) {
				if ( '' === trim( (string) ( $content[ $field ] ?? '' ) ) ) {
					$fields[] = $field;
				}
			}

			foreach ( $content['categories'] as $category => $values ) {
				if ( '' === $values['label'] || '' === $values['description'] ) {
					$fields[] = 'categories.' . $category;
				}
			}

			if ( array() !== $fields ) {
				$missing[ $locale ] = array_values( array_unique( $fields ) );
			}
		}

		return $missing;
	}

	/**
	 * Merge non-empty authored values over defaults and replace {days}.
	 *
	 * @param array<string, mixed> $base     Default content.
	 * @param array<string, mixed> $authored Authored content.
	 * @return array<string, mixed>
	 */
	private static function merge_content( array $base, array $authored, int $lifetime ): array {
		foreach ( $authored as $field => $value ) {
			if ( 'categories' === $field && is_array( $value ) ) {
				foreach ( $value as $category => $category_values ) {
					foreach ( $category_values as $category_field => $category_value ) {
						if ( 'required' === $category_field || '' !== trim( (string) $category_value ) ) {
							$base['categories'][ $category ][ $category_field ] = $category_value;
						}
					}
				}
			} elseif ( '' !== trim( (string) $value ) ) {
				$base[ $field ] = $value;
			}
		}

		array_walk_recursive(
			$base,
			static function ( mixed &$value ) use ( $lifetime ): void {
				if ( is_string( $value ) ) {
					$value = str_replace( '{days}', (string) $lifetime, $value );
				}
			}
		);

		return $base;
	}

	/**
	 * Match an exact locale first, then its base language.
	 *
	 * @param string   $requested Requested locale.
	 * @param string[] $available Configured locales.
	 */
	private static function matching_locale( string $requested, array $available ): ?string {
		if ( in_array( $requested, $available, true ) ) {
			return $requested;
		}

		$language = strtolower( (string) strtok( $requested, '_' ) );

		foreach ( $available as $locale ) {
			if ( $language === strtolower( (string) strtok( $locale, '_' ) ) ) {
				return $locale;
			}
		}

		return null;
	}

	/**
	 * Identify common right-to-left language codes without a dependency.
	 */
	private static function is_rtl_locale( string $locale ): bool {
		$language = strtolower( (string) strtok( $locale, '_' ) );
		return in_array( $language, array( 'ar', 'fa', 'he', 'ps', 'ur' ), true );
	}
}
