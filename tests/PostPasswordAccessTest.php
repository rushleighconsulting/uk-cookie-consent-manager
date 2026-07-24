<?php
/**
 * Protected WordPress content access tests.
 *
 * @package UCCM
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UCCM\Post_Password_Access;
use UCCM\Scanner;
use UCCM\Settings;

final class PostPasswordAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['uccm_test_options']             = array();
		$GLOBALS['uccm_test_transients']          = array();
		$GLOBALS['uccm_test_posts']               = array();
		$GLOBALS['uccm_test_url_post_ids']        = array();
		$GLOBALS['uccm_test_get_posts_arguments'] = array();
		$GLOBALS['uccm_test_salt']                = 'test-site-secret';
	}

	protected function tearDown(): void {
		$GLOBALS['uccm_test_salt'] = 'test-site-secret';
	}

	public function test_access_is_disabled_by_default_and_password_is_encrypted(): void {
		$result = Post_Password_Access::save_password( 'gallery-access-phrase' );

		self::assertTrue( $result );
		self::assertArrayHasKey( Post_Password_Access::PASSWORD_OPTION, $GLOBALS['uccm_test_options'] );
		self::assertStringNotContainsString( 'gallery-access-phrase', $GLOBALS['uccm_test_options'][ Post_Password_Access::PASSWORD_OPTION ] );
		self::assertArrayNotHasKey( 'post_password', Settings::current() );
		self::assertTrue( Post_Password_Access::has_password() );
		self::assertFalse( Post_Password_Access::enabled() );
		self::assertFalse( Post_Password_Access::matches( 'gallery-access-phrase' ) );
	}

	public function test_only_matching_published_protected_posts_become_scan_targets(): void {
		self::assertTrue( Post_Password_Access::save_password( 'shared-view-key' ) );
		Settings::update( array( 'scan_protected_content_enabled' => true ) );
		$GLOBALS['uccm_test_posts'] = array(
			1 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'page',
				'post_password' => '',
				'permalink'     => 'https://example.test/public/',
			),
			2 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'page',
				'post_password' => 'shared-view-key',
				'permalink'     => 'https://example.test/protected-match/',
			),
			3 => (object) array(
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => 'different-key',
				'permalink'     => 'https://example.test/protected-other/',
			),
			4 => (object) array(
				'post_status'   => 'draft',
				'post_type'     => 'page',
				'post_password' => 'shared-view-key',
				'permalink'     => 'https://example.test/draft-protected/',
			),
		);
		$GLOBALS['uccm_test_url_post_ids']['https://example.test/protected-match/'] = 2;

		$targets = Scanner::targets();

		self::assertIsArray( $targets );
		self::assertContains( 'https://example.test/public/', $targets );
		self::assertContains( 'https://example.test/protected-match/', $targets );
		self::assertNotContains( 'https://example.test/protected-other/', $targets );
		self::assertNotContains( 'https://example.test/draft-protected/', $targets );
		self::assertArrayNotHasKey( 'has_password', $GLOBALS['uccm_test_get_posts_arguments'][0] );
		self::assertTrue( Post_Password_Access::target_is_unlocked( 'https://example.test/protected-match/' ) );
	}

	public function test_native_cookie_header_contains_only_a_hash_and_salt_rotation_fails_closed(): void {
		self::assertTrue( Post_Password_Access::save_password( 'rotation-sensitive-key' ) );
		Settings::update( array( 'scan_protected_content_enabled' => true ) );

		$header = Post_Password_Access::cookie_header(
			static fn ( string $password ): string => '$P$B' . hash( 'sha256', $password )
		);

		self::assertStringStartsWith( 'wp-postpass_', $header );
		self::assertStringNotContainsString( 'rotation-sensitive-key', $header );
		self::assertStringContainsString( '%24P%24B', $header );

		$GLOBALS['uccm_test_salt'] = 'rotated-site-secret';

		self::assertFalse( Post_Password_Access::has_password() );
		self::assertFalse( Post_Password_Access::enabled() );
		self::assertSame( '', Post_Password_Access::cookie_header() );
	}

	public function test_browser_token_is_bounded_to_site_run_and_exact_target(): void {
		self::assertTrue( Post_Password_Access::save_password( 'browser-view-key' ) );
		Settings::update( array( 'scan_protected_content_enabled' => true ) );
		$GLOBALS['uccm_test_posts'][7] = (object) array(
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_password' => 'browser-view-key',
			'permalink'     => 'https://example.test/private-browser/',
		);
		$GLOBALS['uccm_test_url_post_ids']['https://example.test/private-browser/'] = 7;

		$token = Post_Password_Access::issue_browser_token( 42, array( 'https://example.test/private-browser/' ) );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		self::assertTrue( Post_Password_Access::browser_token_allows( $token, 42, 'https://example.test/private-browser/' ) );
		self::assertFalse( Post_Password_Access::browser_token_allows( $token, 43, 'https://example.test/private-browser/' ) );
		self::assertFalse( Post_Password_Access::browser_token_allows( $token, 42, 'https://example.test/' ) );

		$GLOBALS['uccm_test_transients'][ 'uccm_post_password_browser_' . $token ]['site'] = 'https://other.test/';
		self::assertFalse( Post_Password_Access::browser_token_allows( $token, 42, 'https://example.test/private-browser/' ) );
	}

	public function test_removal_is_explicit_and_immediate(): void {
		self::assertTrue( Post_Password_Access::save_password( 'temporary-view-key' ) );
		Settings::update( array( 'scan_protected_content_enabled' => true ) );
		self::assertTrue( Post_Password_Access::enabled() );

		Post_Password_Access::clear_password();

		self::assertFalse( Post_Password_Access::has_password() );
		self::assertFalse( Post_Password_Access::enabled() );
		self::assertArrayNotHasKey( Post_Password_Access::PASSWORD_OPTION, $GLOBALS['uccm_test_options'] );
	}
}
