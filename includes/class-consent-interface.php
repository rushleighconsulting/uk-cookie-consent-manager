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
		$settings      = Settings::current();
		$language      = Language_Content::resolve();
		$content       = $language['content'];
		$categories    = $content['categories'];
		$font_family   = 'theme' === (string) $settings['banner_font']
			? 'inherit'
			: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		$style         = sprintf(
			'--uccm-surface:%1$s;--uccm-ink:%2$s;--uccm-muted:%3$s;--uccm-accent:%4$s;--uccm-button-text:%5$s;--uccm-radius:%6$dpx;--uccm-font-family:%7$s',
			(string) $settings['banner_surface_color'],
			(string) $settings['banner_text_color'],
			(string) $settings['banner_muted_color'],
			(string) $settings['banner_button_color'],
			(string) $settings['banner_button_text_color'],
			(int) $settings['banner_corner_radius'],
			$font_family
		);
		?>
		<div id="uccm-consent-root" class="uccm-consent" lang="<?php echo esc_attr( str_replace( '_', '-', (string) $language['locale'] ) ); ?>" dir="<?php echo esc_attr( (string) $language['direction'] ); ?>" data-uccm-locale="<?php echo esc_attr( (string) $language['locale'] ); ?>" data-uccm-wording-version="<?php echo esc_attr( (string) $content['wording_version'] ); ?>" data-uccm-state="unknown" data-uccm-banner-position="<?php echo esc_attr( (string) $settings['banner_position'] ); ?>" data-uccm-icon-position="<?php echo esc_attr( (string) $settings['icon_position'] ); ?>" style="<?php echo esc_attr( $style ); ?>">
			<section id="uccm-banner" class="uccm-banner" role="region" aria-live="polite" aria-atomic="true" aria-labelledby="uccm-banner-title" aria-describedby="uccm-banner-copy" hidden>
				<div class="uccm-banner__content">
					<h2 id="uccm-banner-title" class="uccm-title"><?php echo esc_html( (string) $content['banner_title'] ); ?></h2>
					<p id="uccm-banner-copy" class="uccm-copy"><?php echo esc_html( (string) $content['banner_copy'] ); ?></p>
					<a class="uccm-policy-link" data-uccm-policy-link href="<?php echo esc_url( (string) $content['policy_url'] ); ?>"<?php echo '' === (string) $content['policy_url'] ? ' hidden' : ''; ?>><?php echo esc_html( (string) $content['policy_link_label'] ); ?></a>
				</div>
				<div class="uccm-actions uccm-actions--primary">
					<button type="button" class="uccm-button" data-uccm-action="accept-all"><?php echo esc_html( (string) $content['accept_all'] ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="reject-optional"><?php echo esc_html( (string) $content['reject_optional'] ); ?></button>
					<button type="button" class="uccm-button" data-uccm-action="manage"><?php echo esc_html( (string) $content['manage_preferences'] ); ?></button>
				</div>
			</section>

			<button type="button" class="uccm-settings" data-uccm-action="manage" aria-haspopup="dialog" aria-label="<?php echo esc_attr( (string) $content['settings_label'] ); ?>" data-uccm-label="<?php echo esc_attr( (string) $content['settings_label'] ); ?>" hidden>
				<svg class="uccm-settings__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-3.87A4 4 0 0 1 12.13 3 4 4 0 0 1 12 2Z"></path>
					<path d="M8.5 8.5h.01M16 15.5h.01M10.5 16.5h.01"></path>
				</svg>
			</button>

			<dialog id="uccm-preferences" class="uccm-dialog" aria-modal="true" aria-labelledby="uccm-preferences-title" aria-describedby="uccm-preferences-intro uccm-preferences-cookie">
				<div class="uccm-dialog__inner">
					<div class="uccm-dialog__header">
						<h2 id="uccm-preferences-title" class="uccm-title" tabindex="-1"><?php echo esc_html( (string) $content['preferences_title'] ); ?></h2>
						<button type="button" class="uccm-icon-button" data-uccm-action="close" aria-label="<?php echo esc_attr( (string) $content['close_preferences'] ); ?>">&times;</button>
					</div>
					<p id="uccm-preferences-intro" class="uccm-copy"><?php echo esc_html( (string) $content['preferences_intro'] ); ?></p>
					<p id="uccm-preferences-cookie" class="uccm-copy"><?php echo esc_html( (string) $content['cookie_copy'] ); ?></p>
					<p><a class="uccm-policy-link" data-uccm-policy-link href="<?php echo esc_url( (string) $content['policy_url'] ); ?>"<?php echo '' === (string) $content['policy_url'] ? ' hidden' : ''; ?>><?php echo esc_html( (string) $content['policy_link_label'] ); ?></a></p>

					<div class="uccm-categories">
						<label class="uccm-category">
							<span>
								<strong><?php echo esc_html( (string) $categories['necessary']['label'] ); ?></strong>
								<span><?php echo esc_html( (string) $categories['necessary']['description'] ); ?></span>
							</span>
							<input type="checkbox" name="necessary" checked disabled aria-disabled="true">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php echo esc_html( (string) $categories['functional']['label'] ); ?></strong>
								<span><?php echo esc_html( (string) $categories['functional']['description'] ); ?></span>
							</span>
							<input type="checkbox" name="functional">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php echo esc_html( (string) $categories['analytics']['label'] ); ?></strong>
								<span><?php echo esc_html( (string) $categories['analytics']['description'] ); ?></span>
							</span>
							<input type="checkbox" name="analytics">
						</label>
						<label class="uccm-category">
							<span>
								<strong><?php echo esc_html( (string) $categories['marketing']['label'] ); ?></strong>
								<span><?php echo esc_html( (string) $categories['marketing']['description'] ); ?></span>
							</span>
							<input type="checkbox" name="marketing">
						</label>
					</div>

					<div class="uccm-actions">
						<button type="button" class="uccm-button" data-uccm-action="save"><?php echo esc_html( (string) $content['save_choices'] ); ?></button>
						<button type="button" class="uccm-button uccm-button--secondary" data-uccm-action="withdraw"><?php echo esc_html( (string) $content['withdraw_consent'] ); ?></button>
					</div>
				</div>
			</dialog>

			<p class="uccm-status" data-uccm-status role="status" aria-live="polite"></p>
		</div>
		<?php
	}
}
