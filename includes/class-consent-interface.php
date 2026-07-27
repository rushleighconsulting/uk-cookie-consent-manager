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

		$configuration = Consent_State::configuration();
		$lifetime_days = (int) ( $configuration['lifetimeDays'] ?? 180 );
		$banner_copy   = sprintf(
			/* translators: %d: configured consent lifetime. */
			_n(
				'We use one necessary cookie to remember your choice for %d day. It is set whether you accept or reject optional cookies, so we do not ask you again. With your permission, we may also use optional cookies for functionality, analytics and marketing. You may change your choice at any time by clicking the little cookie logo.',
				'We use one necessary cookie to remember your choice for %d days. It is set whether you accept or reject optional cookies, so we do not ask you again. With your permission, we may also use optional cookies for functionality, analytics and marketing. You may change your choice at any time by clicking the little cookie logo.',
				$lifetime_days,
				'uk-cookie-consent-manager'
			),
			$lifetime_days
		);
		$cookie_copy = sprintf(
			/* translators: %d: configured consent lifetime. */
			_n(
				'We set one necessary cookie. This cookie remembers your cookie choices for %d day, and is set when you accept, reject, or change your cookie options. You may reject any other cookies.',
				'We set one necessary cookie. This cookie remembers your cookie choices for %d days, and is set when you accept, reject, or change your cookie options. You may reject any other cookies.',
				$lifetime_days,
				'uk-cookie-consent-manager'
			),
			$lifetime_days
		);
		?>
		<div id="uccm-consent-root" class="uccm-consent" data-uccm-state="unknown">
			<section id="uccm-banner" class="uccm-banner" aria-labelledby="uccm-banner-title" hidden>
				<div class="uccm-banner__content">
					<h2 id="uccm-banner-title" class="uccm-title"><?php esc_html_e( 'Your cookie choices', 'uk-cookie-consent-manager' ); ?></h2>
					<p class="uccm-copy"><?php echo esc_html( $banner_copy ); ?></p>
				</div>
				<div class="uccm-actions uccm-actions--primary">
					<button type="button" class="uccm-button" data-uccm-action="accept-all"><?php esc_html_e( 'Accept all', 'uk-cookie-consent-manager' ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="reject-optional"><?php esc_html_e( 'Reject non-essential', 'uk-cookie-consent-manager' ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="manage"><?php esc_html_e( 'Manage preferences', 'uk-cookie-consent-manager' ); ?></button>
				</div>
			</section>

			<button type="button" class="uccm-settings" data-uccm-action="manage" aria-haspopup="dialog" aria-label="<?php esc_attr_e( 'Cookie settings', 'uk-cookie-consent-manager' ); ?>" data-uccm-label="<?php esc_attr_e( 'Cookie settings', 'uk-cookie-consent-manager' ); ?>" hidden>
				<svg class="uccm-settings__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-3.87A4 4 0 0 1 12.13 3 4 4 0 0 1 12 2Z"></path>
					<path d="M8.5 8.5h.01M16 15.5h.01M10.5 16.5h.01"></path>
				</svg>
			</button>

			<dialog id="uccm-preferences" class="uccm-dialog" aria-labelledby="uccm-preferences-title">
				<div class="uccm-dialog__inner">
					<div class="uccm-dialog__header">
						<h2 id="uccm-preferences-title" class="uccm-title" tabindex="-1"><?php esc_html_e( 'Cookie preferences', 'uk-cookie-consent-manager' ); ?></h2>
						<button type="button" class="uccm-icon-button" data-uccm-action="close" aria-label="<?php esc_attr_e( 'Close cookie preferences', 'uk-cookie-consent-manager' ); ?>">&times;</button>
					</div>
					<p class="uccm-copy"><?php esc_html_e( 'Choose which optional cookie categories this website may use. Necessary cookies are always active.', 'uk-cookie-consent-manager' ); ?></p>
					<p class="uccm-copy"><?php echo esc_html( $cookie_copy ); ?></p>

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
