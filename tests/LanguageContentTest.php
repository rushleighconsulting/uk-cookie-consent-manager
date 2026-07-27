<?php
/**
 * Language-aware consent content tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Language_Content;
use UCCM\Settings;

/**
 * Verifies locale resolution, local-only fallbacks and RTL behaviour.
 */
final class LanguageContentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options'] = array();
		$GLOBALS['uccm_test_filters'] = array();
		$GLOBALS['uccm_test_polylang_locale'] = '';
	}

	public function test_catalogue_is_sanitised_and_language_fallback_is_explicit(): void {
		$settings = Settings::sanitize(
			array(
				'default_content_locale' => 'en-gb',
				'language_content'       => array(
					array(
						'locale'          => 'cy-GB',
						'wording_version' => 'welsh 2<script>',
						'banner_title'    => '<b>Eich dewisiadau cwcis</b>',
						'banner_copy'     => 'Cofio am {days} diwrnod.',
						'policy_url'      => 'https://example.test/cy/polisi-cwcis',
						'categories'      => array(
							'analytics' => array(
								'label'       => 'Dadansoddeg',
								'description' => 'Deall sut y defnyddir y safle.',
							),
						),
					),
				),
			)
		);
		$GLOBALS['uccm_test_options']['uccm_settings'] = $settings;

		$resolved = Language_Content::resolve( 'cy' );

		self::assertSame( 'en_GB', $settings['default_content_locale'] );
		self::assertSame( 'cy_GB', $resolved['locale'] );
		self::assertTrue( $resolved['fallback'] );
		self::assertSame( 'Eich dewisiadau cwcis', $resolved['content']['banner_title'] );
		self::assertSame( 'Cofio am 180 diwrnod.', $resolved['content']['banner_copy'] );
		self::assertSame( 'welsh2script', $resolved['content']['wording_version'] );
		self::assertSame( 'Dadansoddeg', $resolved['content']['categories']['analytics']['label'] );
	}

	public function test_rtl_locale_gets_rtl_direction_and_default_content_fallback(): void {
		$resolved = Language_Content::resolve( 'ar' );

		self::assertSame( 'en_GB', $resolved['locale'] );
		self::assertTrue( $resolved['fallback'] );
		self::assertSame( 'ltr', $resolved['direction'] );

		$GLOBALS['uccm_test_options']['uccm_settings'] = array(
			'language_content' => array(
				'ar' => array(
					'locale'           => 'ar',
					'wording_version'  => '1',
					'direction'        => 'auto',
					'banner_title'     => 'خيارات ملفات تعريف الارتباط',
					'banner_copy'      => 'اختر إعدادات ملفات تعريف الارتباط.',
				),
			),
		);
		$resolved = Language_Content::resolve( 'ar-EG' );

		self::assertSame( 'ar', $resolved['locale'] );
		self::assertSame( 'rtl', $resolved['direction'] );
		self::assertSame( 'خيارات ملفات تعريف الارتباط', $resolved['content']['banner_title'] );
	}

	public function test_public_locale_filter_is_the_documented_integration_point(): void {
		add_filter(
			'uccm_public_locale',
			static fn (): string => 'cy_GB'
		);

		self::assertSame( 'cy_GB', Language_Content::page_locale() );
	}

	public function test_wpml_and_polylang_languages_are_resolved_without_dependencies(): void {
		add_filter(
			'wpml_current_language',
			static fn (): string => 'cy'
		);

		self::assertSame( 'cy', Language_Content::page_locale() );

		$GLOBALS['uccm_test_polylang_locale'] = 'ar_EG';
		self::assertSame( 'ar_EG', Language_Content::page_locale() );
	}
}
