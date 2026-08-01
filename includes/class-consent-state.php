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
				'label'       => __( 'Necessary', 'rushleigh-cookie-choices' ),
				'description' => __( 'Required for the website to function and cannot be switched off.', 'rushleigh-cookie-choices' ),
				'required'    => true,
			),
			'functional' => array(
				'label'       => __( 'Functional', 'rushleigh-cookie-choices' ),
				'description' => __( 'Remember choices and provide enhanced website features.', 'rushleigh-cookie-choices' ),
				'required'    => false,
			),
			'analytics'  => array(
				'label'       => __( 'Analytics', 'rushleigh-cookie-choices' ),
				'description' => __( 'Help the site owner understand how the website is used.', 'rushleigh-cookie-choices' ),
				'required'    => false,
			),
			'marketing'  => array(
				'label'       => __( 'Marketing', 'rushleigh-cookie-choices' ),
				'description' => __( 'Support advertising and measurement across websites.', 'rushleigh-cookie-choices' ),
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
		$settings = Settings::current();

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

		$language = Language_Content::resolve();

		return array(
			'cookieName'      => self::COOKIE_NAME,
			'cookiePath'      => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			'policyVersion'   => $policy_version,
			'pluginVersion'   => UCCM_VERSION,
			'lifetimeDays'    => $lifetime_days,
			'receiptEndpoint' => rest_url( 'uccm/v1/consents' ),
			'categories'      => self::categories(),
			'locale'          => $language['locale'],
			'requestedLocale' => $language['requestedLocale'],
			'direction'       => $language['direction'],
			'wordingVersion'  => (string) $language['content']['wording_version'],
			'languageContent' => Language_Content::catalog(),
			'defaultLocale'   => (string) ( $settings['default_content_locale'] ?? 'en_GB' ),
			'messages'        => array(
				'available' => __( 'Cookie choices are available.', 'rushleigh-cookie-choices' ),
				'saved'     => __( 'Your cookie choices have been saved.', 'rushleigh-cookie-choices' ),
				'withdrawn' => __( 'Optional cookie consent has been withdrawn.', 'rushleigh-cookie-choices' ),
			),
		);
	}
}
