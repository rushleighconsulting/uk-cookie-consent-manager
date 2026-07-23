<?php
/**
 * Prior resource blocking tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Resource_Rules;

/**
 * Verifies the explicit UCCM-4 blocking contract.
 */
final class ResourceRulesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']          = array();
		$GLOBALS['uccm_test_actions']          = array();
		$GLOBALS['uccm_test_filters']          = array();
		$GLOBALS['uccm_test_fired_actions']    = array();
		$GLOBALS['uccm_test_enqueued_scripts'] = array();
		$GLOBALS['uccm_test_is_admin']         = false;
	}

	public function test_configured_analytics_script_is_inert_before_consent(): void {
		$GLOBALS['uccm_test_options'][ Resource_Rules::OPTION_NAME ] = array(
			'analytics-test' => array(
				'type'     => 'script',
				'handle'   => 'analytics-test',
				'category' => 'analytics',
			),
		);

		$tag = Resource_Rules::filter_script_tag(
			'<script src="https://analytics.example.test/test.js" id="analytics-test-js"></script>',
			'analytics-test',
			'https://analytics.example.test/test.js'
		);

		self::assertStringContainsString( 'type="text/plain"', $tag );
		self::assertStringContainsString( 'data-uccm-blocked="script"', $tag );
		self::assertStringContainsString( 'data-uccm-category="analytics"', $tag );
		self::assertStringContainsString( 'src="https://analytics.example.test/test.js"', $tag );
		self::assertArrayHasKey( 'uccm_resource_blocked', $GLOBALS['uccm_test_fired_actions'] );
	}

	public function test_unconfigured_and_protected_scripts_are_not_rewritten(): void {
		$GLOBALS['uccm_test_options'][ Resource_Rules::OPTION_NAME ] = array(
			'commerce-rule' => array(
				'type'     => 'script',
				'handle'   => 'woocommerce',
				'category' => 'marketing',
			),
		);
		$original = '<script src="/shop.js"></script>';

		self::assertSame( $original, Resource_Rules::filter_script_tag( $original, 'unconfigured' ) );
		self::assertSame( $original, Resource_Rules::filter_script_tag( $original, 'woocommerce' ) );
	}

	public function test_explicit_iframe_and_pixel_placeholders_do_not_load_sources(): void {
		$GLOBALS['uccm_test_options'][ Resource_Rules::OPTION_NAME ] = array(
			'map'   => array(
				'type'     => 'iframe',
				'source'   => 'https://maps.example.test/embed',
				'category' => 'functional',
				'title'    => 'Map',
			),
			'pixel' => array(
				'type'     => 'pixel',
				'source'   => 'https://metrics.example.test/pixel.gif',
				'category' => 'analytics',
			),
		);

		$iframe = Resource_Rules::placeholder( 'map' );
		$pixel  = Resource_Rules::placeholder( 'pixel' );

		self::assertStringContainsString( 'data-uccm-src="https://maps.example.test/embed"', $iframe );
		self::assertStringNotContainsString( ' src="https://maps.example.test/embed"', $iframe );
		self::assertStringContainsString( 'data-uccm-blocked="pixel"', $pixel );
		self::assertStringNotContainsString( ' src="https://metrics.example.test/pixel.gif"', $pixel );
	}

	public function test_invalid_rules_are_reported_and_never_guessed(): void {
		$GLOBALS['uccm_test_options'][ Resource_Rules::OPTION_NAME ] = array(
			'unknown' => array(
				'type'     => 'script',
				'handle'   => 'mystery',
				'category' => 'unclassified',
			),
		);

		self::assertSame( array(), Resource_Rules::rules() );
		self::assertSame(
			'unsupported_category',
			$GLOBALS['uccm_test_fired_actions']['uccm_unknown_resource'][0][1]
		);
	}

	public function test_runtime_and_developer_hooks_are_registered(): void {
		Resource_Rules::register();
		Resource_Rules::enqueue_blocker();

		self::assertArrayHasKey( 'script_loader_tag', $GLOBALS['uccm_test_filters'] );
		self::assertArrayHasKey( 'uccm_render_resource', $GLOBALS['uccm_test_actions'] );
		self::assertArrayHasKey( 'uccm-resource-blocker', $GLOBALS['uccm_test_enqueued_scripts'] );
		self::assertFalse( $GLOBALS['uccm_test_enqueued_scripts']['uccm-resource-blocker']['arguments'] );
	}

	public function test_browser_runtime_reacts_to_consent_and_dynamic_resources(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/blocker.js' );

		self::assertIsString( $script );
		self::assertStringContainsString( "'uccm:consent-ready'", $script );
		self::assertStringContainsString( "'uccm:consent-changed'", $script );
		self::assertStringContainsString( 'new MutationObserver', $script );
		self::assertStringContainsString( "'uccm:resource-unknown'", $script );
		self::assertStringContainsString( 'resource.after( script )', $script );
	}
}
