<?php
/**
 * Validated plugin settings.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the Release 1 settings contract used by administration screens.
 */
final class Settings {

	/**
	 * WordPress option name.
	 */
	public const OPTION_NAME = 'uccm_settings';

	/**
	 * Per-site option listing settings which override a network default.
	 */
	public const OVERRIDES_OPTION = 'uccm_network_overrides';

	/**
	 * Default repeat-email suppression period in minutes.
	 */
	public const DEFAULT_ERROR_EMAIL_SUPPRESSION_MINUTES = 360;

	/**
	 * Maximum repeat-email suppression period in minutes.
	 */
	public const MAX_ERROR_EMAIL_SUPPRESSION_MINUTES = 1440;

	/**
	 * Return privacy-preserving defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'consent_lifetime_days'           => 180,
			'consent_policy_version'          => Consent_State::POLICY_VERSION,
			'default_content_locale'          => 'en_GB',
			'language_content'                => array(),
			'banner_surface_color'            => '#ffffff',
			'banner_text_color'               => '#172033',
			'banner_muted_color'              => '#536079',
			'banner_button_color'             => '#174ea6',
			'banner_button_text_color'        => '#ffffff',
			'banner_font'                     => 'system',
			'banner_corner_radius'            => 12,
			'banner_position'                 => 'bottom',
			'icon_position'                   => 'right',
			'retention_days'                  => 365,
			'store_full_ip'                   => false,
			'trust_proxy_headers'             => false,
			'trusted_proxy_ips'               => array(),
			'scan_urls'                       => array(),
			'scan_excluded_paths'             => Crawler::DEFAULT_EXCLUDED_PATHS,
			'scan_page_limit'                 => Scanner::MAX_TARGETS,
			'scan_batch_size'                 => Scanner::DEFAULT_BATCH_SIZE,
			'scan_protected_content_enabled'  => false,
			'error_email_enabled'             => false,
			'error_email_suppression_minutes' => self::DEFAULT_ERROR_EMAIL_SUPPRESSION_MINUTES,
		);
	}

	/**
	 * Return settings stored for a newly installed site.
	 *
	 * Network-managed sites omit inheritable operational settings so that the
	 * network default can take effect without replacing site-specific choices.
	 *
	 * @param bool $network_managed Whether the site belongs to a network activation.
	 * @return array<string, mixed>
	 */
	public static function installation_defaults( bool $network_managed ): array {
		$defaults = self::defaults();

		if ( ! $network_managed ) {
			return $defaults;
		}

		return array_diff_key( $defaults, array_fill_keys( Multisite::manageable_settings(), true ) );
	}

	/**
	 * Return current settings merged over safe defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! Multisite::is_network_active() ) {
			return array_merge( self::defaults(), $stored );
		}

		$configuration = Multisite::configuration();
		$manageable    = Multisite::manageable_settings();
		$overrides     = self::site_overrides( $stored );
		$effective     = array_merge( self::defaults(), $configuration['defaults'] );

		foreach ( $stored as $name => $value ) {
			if ( ! in_array( $name, $manageable, true ) || in_array( $name, $overrides, true ) ) {
				$effective[ $name ] = $value;
			}
		}

		foreach ( $configuration['locked'] as $name ) {
			$effective[ $name ] = $configuration['defaults'][ $name ];
		}

		return $effective;
	}

	/**
	 * Validate a partial settings update without dropping unrelated values.
	 *
	 * @param array<string, mixed>      $input   Untrusted partial settings.
	 * @param array<string, mixed>|null $current Existing settings for testing.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, ?array $current = null ): array {
		$settings = null === $current ? self::current() : array_merge( self::defaults(), $current );

		if ( array_key_exists( 'consent_lifetime_days', $input ) ) {
			$settings['consent_lifetime_days'] = max( 1, min( 730, (int) $input['consent_lifetime_days'] ) );
		}

		if ( array_key_exists( 'consent_policy_version', $input ) ) {
			$version                            = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $input['consent_policy_version'] );
			$settings['consent_policy_version'] = '' === $version ? Consent_State::POLICY_VERSION : substr( $version, 0, 40 );
		}

		if ( array_key_exists( 'default_content_locale', $input ) ) {
			$locale                             = Language_Content::normalise_locale( (string) $input['default_content_locale'] );
			$settings['default_content_locale'] = '' === $locale ? 'en_GB' : $locale;
		}

		if ( array_key_exists( 'language_content', $input ) ) {
			$settings['language_content'] = Language_Content::sanitise_catalog( $input['language_content'] );
		}

		$style_input = array_intersect_key( $input, array_fill_keys( self::banner_style_keys(), true ) );

		if ( array() !== $style_input ) {
			$validated_style = self::validate_banner_style( $style_input, $settings );

			if ( ! is_wp_error( $validated_style ) ) {
				$settings = array_merge( $settings, $validated_style );
			}
		}

		if ( array_key_exists( 'retention_days', $input ) ) {
			$settings['retention_days'] = max( 1, min( 3650, (int) $input['retention_days'] ) );
		}

		if ( array_key_exists( 'scan_page_limit', $input ) ) {
			$settings['scan_page_limit'] = max( 1, min( Scanner::MAX_TARGETS, (int) $input['scan_page_limit'] ) );
		}

		if ( array_key_exists( 'scan_batch_size', $input ) ) {
			$settings['scan_batch_size'] = max( 1, min( 25, (int) $input['scan_batch_size'] ) );
		}

		if ( array_key_exists( 'error_email_suppression_minutes', $input ) ) {
			$settings['error_email_suppression_minutes'] = max( 1, min( self::MAX_ERROR_EMAIL_SUPPRESSION_MINUTES, (int) $input['error_email_suppression_minutes'] ) );
		}

		foreach ( array( 'store_full_ip', 'trust_proxy_headers', 'scan_protected_content_enabled', 'error_email_enabled' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$settings[ $flag ] = self::boolean( $input[ $flag ] );
			}
		}

		if ( array_key_exists( 'trusted_proxy_ips', $input ) && ! empty( $settings['trust_proxy_headers'] ) ) {
			$settings['trusted_proxy_ips'] = self::trusted_proxy_ips( $input['trusted_proxy_ips'] );
		}

		if ( array_key_exists( 'scan_urls', $input ) ) {
			$settings['scan_urls'] = self::scan_urls( $input['scan_urls'] );
		}

		if ( array_key_exists( 'scan_excluded_paths', $input ) ) {
			$settings['scan_excluded_paths'] = self::scan_excluded_paths( $input['scan_excluded_paths'] );
		}

		return $settings;
	}

	/**
	 * Return the constrained banner-style defaults.
	 *
	 * @return array<string, string|int>
	 */
	public static function banner_style_defaults(): array {
		return array_intersect_key( self::defaults(), array_fill_keys( self::banner_style_keys(), true ) );
	}

	/**
	 * Validate a partial banner-style update and its resulting colour contrast.
	 *
	 * @param array<string, mixed>      $input   Untrusted style values.
	 * @param array<string, mixed>|null $current Existing effective settings.
	 * @return array<string, string|int>|\WP_Error
	 */
	public static function validate_banner_style( array $input, ?array $current = null ): array|\WP_Error {
		$base   = null === $current ? self::current() : array_merge( self::defaults(), $current );
		$style  = array_intersect_key( $base, array_fill_keys( self::banner_style_keys(), true ) );
		$colors = array(
			'banner_surface_color',
			'banner_text_color',
			'banner_muted_color',
			'banner_button_color',
			'banner_button_text_color',
		);

		foreach ( $colors as $name ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}

			$value = strtolower( trim( (string) $input[ $name ] ) );

			if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
				return new \WP_Error(
					'uccm_invalid_banner_colour',
					__( 'Choose colours in the six-digit format shown, for example #174ea6.', 'uk-cookie-consent-manager' )
				);
			}

			$style[ $name ] = $value;
		}

		if ( array_key_exists( 'banner_font', $input ) ) {
			$font                 = sanitize_key( (string) $input['banner_font'] );
			$style['banner_font'] = in_array( $font, array( 'system', 'theme' ), true ) ? $font : 'system';
		}

		if ( array_key_exists( 'banner_corner_radius', $input ) ) {
			$style['banner_corner_radius'] = max( 0, min( 24, (int) $input['banner_corner_radius'] ) );
		}

		if ( array_key_exists( 'banner_position', $input ) ) {
			$position                 = sanitize_key( (string) $input['banner_position'] );
			$style['banner_position'] = in_array( $position, array( 'top', 'bottom' ), true ) ? $position : 'bottom';
		}

		if ( array_key_exists( 'icon_position', $input ) ) {
			$position               = sanitize_key( (string) $input['icon_position'] );
			$style['icon_position'] = in_array( $position, array( 'left', 'right' ), true ) ? $position : 'right';
		}

		$checks = array(
			array( 'banner_text_color', 'banner_surface_color', 4.5, __( 'Banner text must have at least 4.5:1 contrast against the banner background.', 'uk-cookie-consent-manager' ) ),
			array( 'banner_muted_color', 'banner_surface_color', 4.5, __( 'Banner supporting text must have at least 4.5:1 contrast against the banner background.', 'uk-cookie-consent-manager' ) ),
			array( 'banner_button_text_color', 'banner_button_color', 4.5, __( 'Button text must have at least 4.5:1 contrast against the button colour.', 'uk-cookie-consent-manager' ) ),
			array( 'banner_button_color', 'banner_surface_color', 3.0, __( 'Buttons must have at least 3:1 contrast against the banner background.', 'uk-cookie-consent-manager' ) ),
		);

		foreach ( $checks as $check ) {
			if ( self::contrast_ratio( (string) $style[ $check[0] ], (string) $style[ $check[1] ] ) < (float) $check[2] ) {
				return new \WP_Error( 'uccm_inaccessible_banner_colours', (string) $check[3] );
			}
		}

		/**
		 * Validated constrained style values.
		 *
		 * @var array<string, string|int> $style
		 */
		return $style;
	}

	/**
	 * Return the names of supported banner-style settings.
	 *
	 * @return string[]
	 */
	private static function banner_style_keys(): array {
		return array(
			'banner_surface_color',
			'banner_text_color',
			'banner_muted_color',
			'banner_button_color',
			'banner_button_text_color',
			'banner_font',
			'banner_corner_radius',
			'banner_position',
			'icon_position',
		);
	}

	/**
	 * Calculate the WCAG contrast ratio between two six-digit colours.
	 *
	 * @param string $first  First six-digit hexadecimal colour.
	 * @param string $second Second six-digit hexadecimal colour.
	 */
	private static function contrast_ratio( string $first, string $second ): float {
		$values = array();

		foreach ( array( $first, $second ) as $colour ) {
			$channels = array(
				hexdec( substr( $colour, 1, 2 ) ) / 255,
				hexdec( substr( $colour, 3, 2 ) ) / 255,
				hexdec( substr( $colour, 5, 2 ) ) / 255,
			);
			$channels = array_map(
				static fn ( float $channel ): float => $channel <= 0.04045
					? $channel / 12.92
					: ( ( $channel + 0.055 ) / 1.055 ) ** 2.4,
				$channels
			);
			$values[] = ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
		}

		return ( max( $values ) + 0.05 ) / ( min( $values ) + 0.05 );
	}

	/**
	 * Persist a validated partial settings update.
	 *
	 * @param array<string, mixed> $input   Untrusted partial settings.
	 * @param string[]             $inherit Network-manageable settings to inherit.
	 * @return array<string, mixed>
	 */
	public static function update( array $input, array $inherit = array() ): array {
		if ( ! Multisite::is_network_active() ) {
			$settings = self::sanitize( $input );
			update_option( self::OPTION_NAME, $settings, false );
			return $settings;
		}

		$stored        = get_option( self::OPTION_NAME, array() );
		$stored        = is_array( $stored ) ? $stored : array();
		$configuration = Multisite::configuration();
		$manageable    = Multisite::manageable_settings();
		$overrides     = self::site_overrides( $stored );
		$inherit       = array_values( array_intersect( array_map( 'strval', array_filter( $inherit, 'is_scalar' ) ), $manageable ) );

		foreach ( $configuration['locked'] as $locked ) {
			unset( $input[ $locked ] );
		}

		$sanitized = self::sanitize( $input, self::current() );

		foreach ( $inherit as $name ) {
			unset( $stored[ $name ] );
			$overrides = array_values( array_diff( $overrides, array( $name ) ) );
		}

		foreach ( array_keys( $input ) as $name ) {
			if ( ! array_key_exists( $name, $sanitized ) || in_array( $name, $inherit, true ) ) {
				continue;
			}

			$stored[ $name ] = $sanitized[ $name ];

			if ( in_array( $name, $manageable, true ) && ! in_array( $name, $overrides, true ) ) {
				$overrides[] = $name;
			}
		}

		update_option( self::OPTION_NAME, $stored, false );
		update_option( self::OVERRIDES_OPTION, array_values( array_intersect( $overrides, $manageable ) ), false );

		return self::current();
	}

	/**
	 * Return per-site overrides, preserving legacy site values on first upgrade.
	 *
	 * @param array<string, mixed>|null $stored_settings Optional raw site settings.
	 * @return string[]
	 */
	public static function site_overrides( ?array $stored_settings = null ): array {
		$stored_settings = null === $stored_settings ? get_option( self::OPTION_NAME, array() ) : $stored_settings;
		$stored_settings = is_array( $stored_settings ) ? $stored_settings : array();
		$stored          = get_option( self::OVERRIDES_OPTION, null );

		if ( ! is_array( $stored ) ) {
			return array_values( array_intersect( array_keys( $stored_settings ), Multisite::manageable_settings() ) );
		}

		return array_values( array_intersect( array_map( 'strval', array_filter( $stored, 'is_scalar' ) ), Multisite::manageable_settings() ) );
	}

	/**
	 * Return whether a network administrator has locked one setting.
	 *
	 * @param string $name Setting name.
	 */
	public static function is_network_locked( string $name ): bool {
		return Multisite::is_network_active() && in_array( $name, Multisite::configuration()['locked'], true );
	}

	/**
	 * Return whether a site inherits a network-manageable setting.
	 *
	 * @param string $name Setting name.
	 */
	public static function is_network_inherited( string $name ): bool {
		return Multisite::is_network_active()
			&& in_array( $name, Multisite::manageable_settings(), true )
			&& ! in_array( $name, self::site_overrides(), true );
	}

	/**
	 * Interpret checkbox-like values without accepting arbitrary truthy strings.
	 *
	 * @param mixed $value Candidate flag.
	 */
	private static function boolean( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}

	/**
	 * Strictly validate administrator-supplied scan URLs.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]|\WP_Error
	 */
	public static function validate_scan_urls( mixed $value ): array|\WP_Error {
		$values = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		$values = is_array( $values ) ? $values : array();
		$urls   = array();

		foreach ( array_slice( $values, 0, Scanner::MAX_TARGETS - 1 ) as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$validated = Scanner::validate_target( $candidate );

			if ( is_wp_error( $validated ) ) {
				$data              = $validated->get_error_data();
				$error_data        = is_array( $data ) ? $data : array();
				$error_data['url'] = substr( sanitize_text_field( $candidate ), 0, 2048 );

				return new \WP_Error(
					$validated->get_error_code(),
					$validated->get_error_message(),
					$error_data
				);
			}

			$urls[] = $validated;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Return unique, bounded scan URLs for the internal settings contract.
	 *
	 * The administration save path calls validate_scan_urls() first. This
	 * sanitiser remains tolerant so legacy settings can be loaded and rejected
	 * by the scanner's independent runtime validation.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]
	 */
	private static function scan_urls( mixed $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		$values = is_array( $values ) ? $values : array();
		$urls   = array();

		foreach ( array_slice( $values, 0, Scanner::MAX_TARGETS - 1 ) as $candidate ) {
			$url = esc_url_raw( trim( (string) $candidate ) );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Return unique, bounded crawl-exclusion path patterns.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]
	 */
	private static function scan_excluded_paths( mixed $value ): array {
		$values   = is_array( $value ) ? $value : preg_split( '/[\\r\\n]+/', (string) $value );
		$values   = is_array( $values ) ? $values : array();
		$patterns = array();

		foreach ( array_slice( $values, 0, 50 ) as $candidate ) {
			$pattern = substr( sanitize_text_field( trim( (string) $candidate ) ), 0, 100 );

			if ( '' !== $pattern && ( '/' === $pattern[0] || '*' === $pattern[0] ) ) {
				$patterns[] = $pattern;
			}
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * Return unique, canonical proxy IP addresses.
	 *
	 * @param mixed $value Newline-delimited string or value list.
	 * @return string[]
	 */
	private static function trusted_proxy_ips( mixed $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$values = is_array( $values ) ? $values : array();
		$valid  = array();

		foreach ( $values as $candidate ) {
			$ip = filter_var( trim( (string) $candidate ), FILTER_VALIDATE_IP );

			if ( false !== $ip ) {
				$valid[] = (string) $ip;
			}
		}

		return array_values( array_unique( $valid ) );
	}
}
