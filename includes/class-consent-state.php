<?php
/**
 * Public consent-state configuration.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the browser consent-state contract.
 */
final class Consent_State {

	/**
	 * Version of the public consent policy.
	 */
	public const POLICY_VERSION = '1';

	/**
	 * First-party cookie containing the visitor's current decision.
	 */
	public const COOKIE_NAME = 'uccm_consent';

	/**
	 * Default consent lifetime in days.
	 */
	private const DEFAULT_LIFETIME_DAYS = 180;

	/**
	 * Return the supported consent categories.
	 *
	 * @return array<string, array{label: string, description: string, required: bool}>
	 */
	public static function categories(): array {
		return array(
			'necessary'  => array(
				'label'       => __( 'Necessary', 'uk-cookie-consent-manager' ),
				'description' => __( 'Required for the website to function and cannot be switched off.', 'uk-cookie-consent-manager' ),
				'required'    => true,
			),
			'functional' => array(
				'label'       => __( 'Functional', 'uk-cookie-consent-manager' ),
				'description' => __( 'Remember choices and provide enhanced website features.', 'uk-cookie-consent-manager' ),
				'required'    => false,
			),
			'analytics'  => array(
				'label'       => __( 'Analytics', 'uk-cookie-consent-manager' ),
				'description' => __( 'Help the site owner understand how the website is used.', 'uk-cookie-consent-manager' ),
				'required'    => false,
			),
			'marketing'  => array(
				'label'       => __( 'Marketing', 'uk-cookie-consent-manager' ),
				'description' => __( 'Support advertising and measurement across websites.', 'uk-cookie-consent-manager' ),
				'required'    => false,
			),
		);
	}

	/**
	 * Build the cache-safe configuration passed to the browser.
	 *
	 * @return array<string, mixed>
	 */
	public static function configuration(): array {
		$settings = get_option( 'uccm_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$lifetime_days = isset( $settings['consent_lifetime_days'] )
			? (int) $settings['consent_lifetime_days']
			: self::DEFAULT_LIFETIME_DAYS;
		$lifetime_days = max( 1, min( 730, $lifetime_days ) );

		$policy_version = isset( $settings['consent_policy_version'] )
			? (string) $settings['consent_policy_version']
			: self::POLICY_VERSION;
		$policy_version = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $policy_version );

		if ( '' === $policy_version ) {
			$policy_version = self::POLICY_VERSION;
		}

		return array(
			'cookieName'    => self::COOKIE_NAME,
			'cookiePath'    => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			'policyVersion' => $policy_version,
			'pluginVersion' => UCCM_VERSION,
			'lifetimeDays'  => $lifetime_days,
			'categories'    => self::categories(),
			'messages'      => array(
				'saved'     => __( 'Your cookie choices have been saved.', 'uk-cookie-consent-manager' ),
				'withdrawn' => __( 'Optional cookie consent has been withdrawn.', 'uk-cookie-consent-manager' ),
			),
		);
	}
}
