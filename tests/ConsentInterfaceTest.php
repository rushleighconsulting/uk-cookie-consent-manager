<?php
/**
 * Consent interface tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Consent_Interface;
use UCCM\Consent_State;

/**
 * Verifies the UCCM-3 public consent contract.
 */
final class ConsentInterfaceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']          = array();
		$GLOBALS['uccm_test_actions']          = array();
		$GLOBALS['uccm_test_enqueued_styles']  = array();
		$GLOBALS['uccm_test_enqueued_scripts'] = array();
		$GLOBALS['uccm_test_localized']        = array();
		$GLOBALS['uccm_test_is_admin']         = false;
	}

	public function test_optional_categories_are_disabled_by_default(): void {
		$configuration = Consent_State::configuration();

		self::assertTrue( $configuration['categories']['necessary']['required'] );
		self::assertFalse( $configuration['categories']['functional']['required'] );
		self::assertFalse( $configuration['categories']['analytics']['required'] );
		self::assertFalse( $configuration['categories']['marketing']['required'] );
		self::assertSame( 180, $configuration['lifetimeDays'] );
		self::assertSame( '1', $configuration['policyVersion'] );
		self::assertSame( 'https://example.test/wp-json/uccm/v1/consents', $configuration['receiptEndpoint'] );
	}

	public function test_configuration_bounds_lifetime_and_sanitises_policy_version(): void {
		$GLOBALS['uccm_test_options']['uccm_settings'] = array(
			'consent_lifetime_days'  => 900,
			'consent_policy_version' => ' release 2<script> ',
		);

		$configuration = Consent_State::configuration();

		self::assertSame( 730, $configuration['lifetimeDays'] );
		self::assertSame( 'release2script', $configuration['policyVersion'] );
	}

	public function test_front_end_assets_are_dependency_free_and_localised(): void {
		Consent_Interface::enqueue_assets();

		self::assertArrayHasKey( 'uccm-consent', $GLOBALS['uccm_test_enqueued_styles'] );
		self::assertArrayHasKey( 'uccm-consent', $GLOBALS['uccm_test_enqueued_scripts'] );
		self::assertSame( array(), $GLOBALS['uccm_test_enqueued_scripts']['uccm-consent']['dependencies'] );
		self::assertSame(
			Consent_State::COOKIE_NAME,
			$GLOBALS['uccm_test_localized']['uccmConsentConfig']['cookieName']
		);
	}

	public function test_markup_exposes_equal_first_layer_actions_and_dialog_semantics(): void {
		ob_start();
		Consent_Interface::render();
		$markup = (string) ob_get_clean();

		self::assertStringContainsString( 'data-uccm-action="accept-all"', $markup );
		self::assertStringContainsString( 'data-uccm-action="reject-optional"', $markup );
		self::assertStringContainsString( 'data-uccm-action="manage"', $markup );
		self::assertStringContainsString( 'class="uccm-settings"', $markup );
		self::assertStringContainsString( 'aria-label="Cookie settings"', $markup );
		self::assertStringContainsString( 'data-uccm-label="Cookie settings"', $markup );
		self::assertStringContainsString( 'class="uccm-settings__icon"', $markup );
		self::assertStringContainsString( 'aria-hidden="true"', $markup );
		self::assertStringContainsString( '<dialog id="uccm-preferences"', $markup );
		self::assertStringContainsString( 'name="necessary" checked disabled', $markup );
		self::assertStringContainsString( 'name="functional"', $markup );
		self::assertStringContainsString( 'name="analytics"', $markup );
		self::assertStringContainsString( 'name="marketing"', $markup );
		self::assertStringContainsString( 'aria-live="polite"', $markup );
		self::assertStringContainsString( 'whether you accept or reject optional cookies', $markup );
		self::assertStringContainsString( 'You may change your choice at any time by clicking the little cookie logo.', $markup );
		self::assertStringContainsString( 'We set one necessary cookie.', $markup );
		self::assertStringContainsString( 'You may reject any other cookies.', $markup );
		self::assertSame( 2, substr_count( $markup, '180 days' ) );
		self::assertStringNotContainsString( 'uccm_consent', $markup );
	}

	public function test_markup_uses_the_configured_lifetime_in_both_messages(): void {
		$GLOBALS['uccm_test_options']['uccm_settings'] = array(
			'consent_lifetime_days' => 365,
		);

		ob_start();
		Consent_Interface::render();
		$markup = (string) ob_get_clean();

		self::assertSame( 2, substr_count( $markup, '365 days' ) );
		self::assertStringContainsString( 'remember your choice for 365 days', $markup );
		self::assertStringContainsString( 'remembers your cookie choices for 365 days', $markup );
	}

	public function test_markup_uses_singular_day_wording_for_one_day_lifetime(): void {
		$GLOBALS['uccm_test_options']['uccm_settings'] = array(
			'consent_lifetime_days' => 1,
		);

		ob_start();
		Consent_Interface::render();
		$markup = (string) ob_get_clean();

		self::assertSame( 2, substr_count( $markup, '1 day' ) );
		self::assertStringNotContainsString( '1 days', $markup );
	}

	public function test_browser_script_persists_versioned_state_and_publishes_changes(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/consent.js' );

		self::assertIsString( $script );
		self::assertStringContainsString( 'SameSite=Lax', $script );
		self::assertStringContainsString( "'uccm:consent-changed'", $script );
		self::assertStringContainsString( 'dialog.showModal()', $script );
		self::assertStringContainsString( "'withdraw'", $script );
	}
}
