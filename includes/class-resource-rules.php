<?php
/**
 * Explicit resource rules and prior blocking.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Holds configured optional resources until their category is granted.
 */
final class Resource_Rules {

	/**
	 * WordPress option containing administrator-managed blocking rules.
	 */
	public const OPTION_NAME = 'uccm_blocking_rules';

	/**
	 * Optional consent categories that may own a blocking rule.
	 *
	 * @var string[]
	 */
	private const OPTIONAL_CATEGORIES = array( 'functional', 'analytics', 'marketing' );

	/**
	 * Supported explicit resource types.
	 *
	 * @var string[]
	 */
	private const RESOURCE_TYPES = array( 'script', 'iframe', 'embed', 'pixel' );

	/**
	 * Register blocking hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_blocker' ), 0 );
		add_filter( 'script_loader_tag', array( self::class, 'filter_script_tag' ), 10, 3 );
		add_action( 'uccm_render_resource', array( self::class, 'render_resource' ), 10, 2 );
	}

	/**
	 * Load the small activation runtime in the document head.
	 */
	public static function enqueue_blocker(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'uccm-resource-blocker',
			plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/blocker.js',
			array(),
			UCCM_VERSION,
			false
		);
	}

	/**
	 * Return validated, explicit rules.
	 *
	 * The uccm_blocking_rules filter may add rules supplied by compatible plugins.
	 * Invalid or incomplete rules are reported through uccm_unknown_resource.
	 *
	 * @return array<string, array{id: string, type: string, category: string, handle: string, source: string, title: string}>
	 */
	public static function rules(): array {
		$rules = get_option( self::OPTION_NAME, array() );

		/**
		 * Filters the explicit resource rules before validation.
		 *
		 * @param mixed $rules Stored administrator rules.
		 */
		$rules = apply_filters( 'uccm_blocking_rules', $rules );

		if ( ! is_array( $rules ) ) {
			self::report_unknown( '', 'rules_not_array', $rules );
			return array();
		}

		$validated = array();

		foreach ( $rules as $rule_id => $rule ) {
			$id = self::sanitize_identifier( (string) $rule_id );

			if ( '' === $id || ! is_array( $rule ) ) {
				self::report_unknown( $id, 'invalid_rule', $rule );
				continue;
			}

			$type     = isset( $rule['type'] ) ? self::sanitize_identifier( (string) $rule['type'] ) : '';
			$category = isset( $rule['category'] ) ? self::sanitize_identifier( (string) $rule['category'] ) : '';
			$handle   = isset( $rule['handle'] ) ? self::sanitize_identifier( (string) $rule['handle'] ) : '';
			$source   = isset( $rule['source'] ) ? esc_url_raw( (string) $rule['source'] ) : '';
			$title    = isset( $rule['title'] ) ? sanitize_text_field( (string) $rule['title'] ) : '';

			if ( ! in_array( $type, self::RESOURCE_TYPES, true ) ) {
				self::report_unknown( $id, 'unsupported_type', $rule );
				continue;
			}

			if ( ! in_array( $category, self::OPTIONAL_CATEGORIES, true ) ) {
				self::report_unknown( $id, 'unsupported_category', $rule );
				continue;
			}

			if ( ( 'script' === $type && '' === $handle && '' === $source ) || ( 'script' !== $type && '' === $source ) ) {
				self::report_unknown( $id, 'missing_resource', $rule );
				continue;
			}

			$validated[ $id ] = array(
				'id'       => $id,
				'type'     => $type,
				'category' => $category,
				'handle'   => $handle,
				'source'   => $source,
				'title'    => $title,
			);
		}

		return $validated;
	}

	/**
	 * Convert an explicitly mapped WordPress script into inert markup.
	 *
	 * @param string $tag    Original script tag.
	 * @param string $handle WordPress script handle.
	 * @param string $src    Script source URL when supplied by WordPress.
	 */
	public static function filter_script_tag( string $tag, string $handle, string $src = '' ): string {
		if ( is_admin() || self::is_protected_handle( $handle ) ) {
			return $tag;
		}

		$rule = self::rule_for_handle( $handle );

		if ( null === $rule ) {
			return $tag;
		}

		$original_type = 'text/javascript';

		if ( preg_match( '/\stype=(["\'])(.*?)\1/i', $tag, $matches ) ) {
			$original_type = (string) $matches[2];
			$tag           = (string) preg_replace( '/\stype=(["\'])(.*?)\1/i', '', $tag, 1 );
		}

		$attributes = sprintf(
			' type="text/plain" data-uccm-blocked="script" data-uccm-rule="%1$s" data-uccm-category="%2$s" data-uccm-handle="%3$s" data-uccm-original-type="%4$s"',
			esc_attr( $rule['id'] ),
			esc_attr( $rule['category'] ),
			esc_attr( $handle ),
			esc_attr( $original_type )
		);
		$blocked    = (string) preg_replace( '/<script\b/i', '<script' . $attributes, $tag, 1 );

		/**
		 * Fires after an explicitly configured WordPress script is made inert.
		 *
		 * @param array<string, string> $rule Validated rule.
		 * @param string                $src  Script URL.
		 */
		do_action( 'uccm_resource_blocked', $rule, $src );

		return $blocked;
	}

	/**
	 * Build an inert placeholder for an explicit iframe, embed, pixel or script rule.
	 *
	 * @param string               $rule_id    Rule identifier.
	 * @param array<string, mixed> $attributes Safe presentation attributes.
	 */
	public static function placeholder( string $rule_id, array $attributes = array() ): string {
		$rules = self::rules();
		$id    = self::sanitize_identifier( $rule_id );

		if ( ! isset( $rules[ $id ] ) ) {
			self::report_unknown( $id, 'rule_not_found', $rule_id );
			return '';
		}

		$rule = $rules[ $id ];

		if ( 'script' === $rule['type'] ) {
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Deliberately inert until explicit consent.
			$placeholder = sprintf(
				'<script type="text/plain" data-uccm-blocked="script" data-uccm-rule="%1$s" data-uccm-category="%2$s" data-uccm-src="%3$s"></script>',
				esc_attr( $rule['id'] ),
				esc_attr( $rule['category'] ),
				esc_url( $rule['source'] )
			);
			// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			return $placeholder;
		}

		if ( 'pixel' === $rule['type'] ) {
			return sprintf(
				'<img data-uccm-blocked="pixel" data-uccm-rule="%1$s" data-uccm-category="%2$s" data-uccm-src="%3$s" alt="" width="1" height="1">',
				esc_attr( $rule['id'] ),
				esc_attr( $rule['category'] ),
				esc_url( $rule['source'] )
			);
		}

		$title = isset( $attributes['title'] ) ? sanitize_text_field( (string) $attributes['title'] ) : $rule['title'];
		$title = '' !== $title ? $title : __( 'Optional embedded content', 'uk-cookie-consent-manager' );

		return sprintf(
			'<iframe data-uccm-blocked="%1$s" data-uccm-rule="%2$s" data-uccm-category="%3$s" data-uccm-src="%4$s" title="%5$s" loading="lazy"></iframe>',
			esc_attr( $rule['type'] ),
			esc_attr( $rule['id'] ),
			esc_attr( $rule['category'] ),
			esc_url( $rule['source'] ),
			esc_attr( $title )
		);
	}

	/**
	 * Echo a configured inert placeholder through the uccm_render_resource action.
	 *
	 * @param string               $rule_id    Rule identifier.
	 * @param array<string, mixed> $attributes Safe presentation attributes.
	 */
	public static function render_resource( string $rule_id, array $attributes = array() ): void {
		echo self::placeholder( $rule_id, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Placeholder escapes every dynamic value.
	}

	/**
	 * Find a validated script rule by WordPress handle.
	 *
	 * @param string $handle WordPress script handle.
	 * @return array{id: string, type: string, category: string, handle: string, source: string, title: string}|null
	 */
	private static function rule_for_handle( string $handle ): ?array {
		foreach ( self::rules() as $rule ) {
			if ( 'script' === $rule['type'] && $handle === $rule['handle'] ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Protect common WordPress, security and commerce dependencies by default.
	 *
	 * @param string $handle WordPress script handle.
	 */
	private static function is_protected_handle( string $handle ): bool {
		$protected = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'wp-hooks',
			'wp-i18n',
			'wp-api-fetch',
			'wp-polyfill',
			'heartbeat',
			'comment-reply',
			'woocommerce',
			'wc-add-to-cart',
			'wc-cart-fragments',
		);

		/**
		 * Filters handles that UCCM must never block automatically.
		 *
		 * @param string[] $protected Protected handles.
		 */
		$protected = apply_filters( 'uccm_protected_script_handles', $protected );
		$protected = array_map( 'strval', $protected );

		return in_array( $handle, $protected, true );
	}

	/**
	 * Report invalid or unknown resources without guessing their category.
	 *
	 * @param string $id          Sanitised rule identifier.
	 * @param string $reason      Stable diagnostic reason.
	 * @param mixed  $declaration Original resource declaration.
	 */
	private static function report_unknown( string $id, string $reason, mixed $declaration ): void {
		/**
		 * Fires when a blocking declaration cannot be safely classified.
		 *
		 * @param string $id       Sanitised rule identifier.
		 * @param string $reason   Stable diagnostic reason.
		 * @param mixed  $resource Original declaration.
		 */
		do_action( 'uccm_unknown_resource', $id, $reason, $declaration );
	}

	/**
	 * Sanitise a stable identifier without relying on display text.
	 *
	 * @param string $value Untrusted identifier.
	 */
	private static function sanitize_identifier( string $value ): string {
		$value = strtolower( $value );
		return (string) preg_replace( '/[^a-z0-9_-]/', '', $value );
	}
}
