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
	 * Register runtime hooks.
	 */
	public function boot(): void {
		/**
		 * Fires after the UK Cookie Consent Manager scaffold has loaded.
		 *
		 * @param Plugin $plugin Main plugin coordinator.
		 */
		do_action( 'uccm_loaded', $this );
	}
}
