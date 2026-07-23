<?php
/**
 * Main plugin coordinator.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin services.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Prevent direct construction outside the singleton.
	 */
	private function __construct() {
	}

	/**
	 * Get the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register runtime hooks and apply versioned upgrades.
	 */
	public function boot(): void {
		Database::maybe_upgrade();
		Capabilities::maybe_upgrade();
		Consent_Receipts::register();
		Scanner::register();
		Resource_Rules::register();
		Consent_Interface::register();

		if ( is_admin() ) {
			Admin::register();
		}

		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( Activator::class, 'initialize_site' ), 100 );
		}

		/**
		 * Fires after the UK Cookie Consent Manager foundation has loaded.
		 *
		 * @param Plugin $plugin Main plugin coordinator.
		 */
		do_action( 'uccm_loaded', $this );
	}
}
