<?php
/**
 * Public consent interface.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the visitor consent controls.
 */
final class Consent_Interface {

	/**
	 * Register front-end hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( self::class, 'render' ) );
	}

	/**
	 * Enqueue dependency-free public assets.
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$plugin_url = plugin_dir_url( UCCM_PLUGIN_FILE );

		wp_enqueue_style(
			'uccm-consent',
			$plugin_url . 'assets/css/consent.css',
			array(),
			UCCM_VERSION
		);
		wp_enqueue_script(
			'uccm-consent',
			$plugin_url . 'assets/js/consent.js',
			array(),
			UCCM_VERSION,
			true
		);
		wp_localize_script( 'uccm-consent', 'uccmConsentConfig', Consent_State::configuration() );
	}

	/**
	 * Render cache-safe controls. Browser state decides whether the banner opens.
	 */
	public static function render(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<div id="uccm-consent-root" class="uccm-consent" data-uccm-state="unknown">
			<section id="uccm-banner" class="uccm-banner" aria-labelledby="uccm-banner-title" hidden>
				<div class="uccm-banner__content">
					<h2 id="uccm-banner-title" class="uccm-title"><?php esc_html_e( 'Your cookie choices', 'uk-cookie-consent-manager' ); ?></h2>
					<p class="uccm-copy"><?php esc_html_e( 'We use necessary cookies to make this website work. With your permission, we may also use optional cookies for functionality, analytics and marketing.', 'uk-cookie-consent-manager' ); ?></p>
				</div>
				<div class="uccm-actions uccm-actions--primary">
					<button type="button" class="uccm-button" data-uccm-action="accept-all"><?php esc_html_e( 'Accept all', 'uk-cookie-consent-manager' ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="reject-optional"><?php esc_html_e( 'Reject non-essential', 'uk-cookie-consent-manager' ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="manage"><?php esc_html_e( 'Manage preferences', 'uk-cookie-consent-manager' ); ?></button>
				</div>
			</section>

			<button type="button" class="uccm-settings" data-uccm-action="manage" aria-haspopup="dialog" hidden>
				<?php esc_html_e( 'Cookie settings', 'uk-cookie-consent-manager' ); ?>
			</button>

			<dialog id="uccm-preferences" class="uccm-dialog" aria-labelledby="uccm-preferences-title">
				<div class="uccm-dialog__inner">
					<div class="uccm-dialog__header">
						<h2 id="uccm-preferences-title" class="uccm-title" tabindex="-1"><?php esc_html_e( 'Cookie preferences', 'uk-cookie-consent-manager' ); ?></h2>
						<button type="button" class="uccm-icon-button" data-uccm-action="close" aria-label="<?php esc_attr_e( 'Close cookie preferences', 'uk-cookie-consent-manager' ); ?>">&times;</button>
					</div>
					<p class="uccm-copy"><?php esc_html_e( 'Choose which optional cookie categories this website may use. Necessary cookies are always active.', 'uk-cookie-consent-manager' ); ?></p>

					<div class="uccm-categories">
						<label class="uccm-category">
							<span>
								<strong><?php esc_html_e( 'Necessary', 'uk-cookie-consent-manager' ); ?></strong>
								<span><?php esc_html_e( 'Required for the website to function and cannot be switched off.', 'uk-cookie-consent-manager' ); ?></span>
							</span>
							<input type="checkbox" name="necessary" checked disabled aria-disabled="true">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php esc_html_e( 'Functional', 'uk-cookie-consent-manager' ); ?></strong>
								<span><?php esc_html_e( 'Remember choices and provide enhanced website features.', 'uk-cookie-consent-manager' ); ?></span>
							</span>
							<input type="checkbox" name="functional">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php esc_html_e( 'Analytics', 'uk-cookie-consent-manager' ); ?></strong>
								<span><?php esc_html_e( 'Help the site owner understand how the website is used.', 'uk-cookie-consent-manager' ); ?></span>
							</span>
							<input type="checkbox" name="analytics">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php esc_html_e( 'Marketing', 'uk-cookie-consent-manager' ); ?></strong>
								<span><?php esc_html_e( 'Support advertising and measurement across websites.', 'uk-cookie-consent-manager' ); ?></span>
							</span>
							<input type="checkbox" name="marketing">
						</label>
					</div>

					<div class="uccm-actions">
						<button type="button" class="uccm-button" data-uccm-action="save"><?php esc_html_e( 'Save choices', 'uk-cookie-consent-manager' ); ?></button>
						<button type="button" class="uccm-button uccm-button--secondary" data-uccm-action="withdraw"><?php esc_html_e( 'Withdraw optional consent', 'uk-cookie-consent-manager' ); ?></button>
					</div>
				</div>
			</dialog>

			<p class="uccm-status" data-uccm-status role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}
