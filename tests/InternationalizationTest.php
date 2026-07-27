<?php
/**
 * Internationalisation and localisation tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Admin;
use UCCM\Consent_Interface;
use UCCM\Plugin;

require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

/**
 * Verifies the native WordPress translation contract.
 */
final class InternationalizationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_actions']             = array();
		$GLOBALS['uccm_test_options']             = array();
		$GLOBALS['uccm_test_enqueued_scripts']    = array();
		$GLOBALS['uccm_test_localized']           = array();
		$GLOBALS['uccm_test_script_translations'] = array();
		$GLOBALS['uccm_test_loaded_textdomains']  = array();
		$GLOBALS['uccm_test_translations']        = array();
		$GLOBALS['uccm_test_is_admin']            = false;
	}

	public function test_plugin_registers_native_language_pack_loading(): void {
		Plugin::instance()->boot();

		self::assertArrayHasKey( 'init', $GLOBALS['uccm_test_actions'] );
		do_action( 'init' );
		self::assertSame(
			'uk-cookie-consent-manager/languages',
			$GLOBALS['uccm_test_loaded_textdomains']['uk-cookie-consent-manager']
		);
	}

	public function test_translatable_javascript_uses_wordpress_i18n_runtime(): void {
		Admin::enqueue_scan_progress(
			array(
				array(
					'id'     => 7,
					'status' => 'running',
				),
			)
		);

		self::assertContains( 'wp-i18n', $GLOBALS['uccm_test_enqueued_scripts']['uccm-scan-progress']['dependencies'] );
		self::assertSame(
			array(
				'domain' => 'uk-cookie-consent-manager',
				'path'   => UCCM_PLUGIN_DIR . 'languages',
			),
			$GLOBALS['uccm_test_script_translations']['uccm-scan-progress']
		);

		$runner = file_get_contents( dirname( __DIR__ ) . '/assets/js/scan-runner.js' );
		self::assertIsString( $runner );
		self::assertStringContainsString( "__( 'Checking page %1\$d of %2\$d (%3\$s)…', 'uk-cookie-consent-manager' )", $runner );
	}

	public function test_welsh_translation_renders_without_changing_consent_semantics(): void {
		$GLOBALS['uccm_test_translations']['uk-cookie-consent-manager'] = array(
			'Your cookie choices'  => 'Eich dewisiadau cwcis',
			'Accept all'           => 'Derbyn pob un',
			'Reject non-essential' => 'Gwrthod cwcis nad ydynt yn hanfodol',
			'Manage preferences'   => 'Rheoli dewisiadau',
		);

		ob_start();
		Consent_Interface::render();
		$markup = (string) ob_get_clean();

		self::assertStringContainsString( 'Eich dewisiadau cwcis', $markup );
		self::assertStringContainsString( 'Derbyn pob un', $markup );
		self::assertStringContainsString( 'Gwrthod cwcis nad ydynt yn hanfodol', $markup );
		self::assertSame( 1, substr_count( $markup, 'data-uccm-action="accept-all"' ) );
		self::assertSame( 1, substr_count( $markup, 'data-uccm-action="reject-optional"' ) );
	}

	public function test_translated_markup_is_escaped_before_output(): void {
		$GLOBALS['uccm_test_translations']['uk-cookie-consent-manager'] = array(
			'Your cookie choices' => '<script>window.compromised=true</script>',
			'Cookie settings'     => '" onclick="window.compromised=true',
		);

		ob_start();
		Consent_Interface::render();
		$markup = (string) ob_get_clean();

		self::assertStringNotContainsString( '<script>', $markup );
		self::assertStringNotContainsString( ' onclick="window.compromised=true"', $markup );
		self::assertStringContainsString( '&lt;script&gt;', $markup );
		self::assertStringContainsString( '&quot; onclick=&quot;window.compromised=true', $markup );
	}

	public function test_pot_template_contains_php_and_javascript_messages(): void {
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/uk-cookie-consent-manager.pot' );

		self::assertIsString( $pot );
		self::assertStringContainsString( 'msgid "Your cookie choices"', $pot );
		self::assertStringContainsString( 'msgid "Checking page %1$d of %2$d (%3$s)…"', $pot );
		self::assertStringContainsString( 'msgid_plural "%d reviewed items"', $pot );
	}
}
