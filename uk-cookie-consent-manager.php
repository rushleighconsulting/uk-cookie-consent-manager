<?php
/**
 * Plugin Name: UK Cookie Consent Manager
 * Plugin URI:  https://github.com/rushleighconsulting/uk-cookie-consent-manager
 * Description: Privacy-by-design cookie consent and management for UK WordPress sites.
 * Version:     0.1.0-rc.5
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author:      Rushleigh Consulting
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: uk-cookie-consent-manager
 * Domain Path: /languages
 *
 * @package UCCM
 */

defined( 'ABSPATH' ) || exit;

define( 'UCCM_VERSION', '0.1.0-rc.5' );
define( 'UCCM_PLUGIN_FILE', __FILE__ );
define( 'UCCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UCCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once UCCM_PLUGIN_DIR . 'includes/class-database.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-ip-privacy.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-consent-receipts.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-activator.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-consent-state.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-crawler.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-settings.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-post-password-access.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-operational-alerts.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-resource-rules.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-cookie-inventory.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-scan-findings.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-scanner.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-consent-interface.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-secure-updater.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-privacy.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-admin.php';
require_once UCCM_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( UCCM_PLUGIN_FILE, array( 'UCCM\\Activator', 'activate' ) );
register_deactivation_hook( UCCM_PLUGIN_FILE, array( 'UCCM\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		UCCM\Plugin::instance()->boot();
	}
);
