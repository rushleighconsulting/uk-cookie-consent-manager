<?php
/**
 * WordPress Multisite lifecycle and network administration.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates bounded network work without mixing site-owned data.
 */
final class Multisite {

	/**
	 * Network option containing defaults and locks.
	 */
	public const SETTINGS_OPTION = 'uccm_network_settings';

	/**
	 * Network option containing the current install or upgrade batch.
	 */
	public const STATE_OPTION = 'uccm_network_install_state';

	/**
	 * Network option recording the completely installed plugin version.
	 */
	public const VERSION_OPTION = 'uccm_network_version';

	/**
	 * Network option requiring explicit network-wide deletion approval.
	 */
	public const DELETE_OPTION = 'uccm_network_delete_data_on_uninstall';

	/**
	 * Hook used for resumable network install and upgrade batches.
	 */
	public const BATCH_HOOK = 'uccm_network_install_batch';

	/**
	 * Maximum sites handled by one resumable request.
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Short lock preventing concurrent batch workers.
	 */
	private const LOCK_TRANSIENT = 'uccm_network_install_lock';

	/**
	 * Register network lifecycle and administration hooks.
	 */
	public static function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 100 );
		add_action( self::BATCH_HOOK, array( self::class, 'process_install_batch' ) );
		add_action( 'network_admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_uccm_save_network_settings', array( self::class, 'save_settings' ) );

		if ( self::is_network_active() && is_main_site() ) {
			add_site_option(
				self::SETTINGS_OPTION,
				array(
					'defaults' => array(),
					'locked'   => array(),
				)
			);
			add_site_option( self::DELETE_OPTION, false );
			self::maybe_schedule_upgrade();
		}
	}

	/**
	 * Begin a bounded installation across the network.
	 */
	public static function activate_network(): void {
		add_site_option(
			self::SETTINGS_OPTION,
			array(
				'defaults' => array(),
				'locked'   => array(),
			)
		);
		add_site_option( self::DELETE_OPTION, false );
		self::start_install_batch();
		self::process_install_batch();
	}

	/**
	 * Clear scheduled work across sites without deleting retained data.
	 */
	public static function deactivate_network(): void {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		delete_site_transient( self::LOCK_TRANSIENT );

		$offset = 0;

		do {
			$site_ids = self::site_ids( $offset );

			foreach ( $site_ids as $site_id ) {
				self::with_site( $site_id, array( Activator::class, 'clear_scheduled_work' ) );
			}

			$site_count = count( $site_ids );
			$offset    += $site_count;
		} while ( self::BATCH_SIZE === $site_count );

		delete_site_option( self::STATE_OPTION );
	}

	/**
	 * Install or upgrade one bounded group of sites.
	 */
	public static function process_install_batch(): void {
		if ( get_site_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_site_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$state = self::install_state();

			if ( 'running' !== $state['status'] || UCCM_VERSION !== $state['version'] ) {
				return;
			}

			$site_ids = self::site_ids( $state['offset'] );

			foreach ( $site_ids as $site_id ) {
				self::with_site(
					$site_id,
					static function (): void {
						Activator::install_current_site( true );
					}
				);
			}

			$site_count          = count( $site_ids );
			$state['offset']    += $site_count;
			$state['processed'] += $site_count;
			$state['updated_at'] = current_time( 'mysql', true );
			$state['status']     = self::BATCH_SIZE === $site_count ? 'running' : 'completed';

			update_site_option( self::STATE_OPTION, $state );

			if ( 'running' === $state['status'] ) {
				self::schedule_batch();
			} else {
				update_site_option( self::VERSION_OPTION, UCCM_VERSION );
			}
		} finally {
			delete_site_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Install a newly created site when the plugin is network active.
	 *
	 * @param \WP_Site $site Newly initialized site.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}

		self::with_site(
			(int) $site->blog_id,
			static function (): void {
				Activator::install_current_site( true );
			}
		);
	}

	/**
	 * Return whether WordPress reports UCCM as network active.
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$active_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

		return isset( $active_plugins[ plugin_basename( UCCM_PLUGIN_FILE ) ] );
	}

	/**
	 * Return the allowlisted settings that may have network defaults.
	 *
	 * Site-specific policy versions, scan URLs, passwords, IP collection and
	 * proxy trust are deliberately excluded.
	 *
	 * @return string[]
	 */
	public static function manageable_settings(): array {
		return array(
			'consent_lifetime_days',
			'retention_days',
			'scan_page_limit',
			'scan_batch_size',
			'error_email_enabled',
			'error_email_suppression_minutes',
		);
	}

	/**
	 * Return validated network defaults and locks.
	 *
	 * @return array{defaults: array<string, mixed>, locked: string[]}
	 */
	public static function configuration(): array {
		$stored   = get_site_option( self::SETTINGS_OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = isset( $stored['defaults'] ) && is_array( $stored['defaults'] ) ? $stored['defaults'] : array();
		$locked   = isset( $stored['locked'] ) && is_array( $stored['locked'] ) ? $stored['locked'] : array();
		$allowed  = self::manageable_settings();

		$defaults = array_intersect_key( $defaults, array_fill_keys( $allowed, true ) );
		$defaults = array_intersect_key( Settings::sanitize( $defaults, Settings::defaults() ), $defaults );
		$locked   = array_values( array_intersect( array_map( 'strval', array_filter( $locked, 'is_scalar' ) ), array_keys( $defaults ) ) );

		return compact( 'defaults', 'locked' );
	}

	/**
	 * Return progress suitable for network diagnostics.
	 *
	 * @return array{offset: int, processed: int, status: string, version: string, updated_at: string}
	 */
	public static function install_state(): array {
		$stored = get_site_option( self::STATE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'offset'     => max( 0, (int) ( $stored['offset'] ?? 0 ) ),
			'processed'  => max( 0, (int) ( $stored['processed'] ?? 0 ) ),
			'status'     => in_array( (string) ( $stored['status'] ?? '' ), array( 'idle', 'running', 'completed' ), true ) ? (string) $stored['status'] : 'idle',
			'version'    => substr( sanitize_text_field( (string) ( $stored['version'] ?? '' ) ), 0, 40 ),
			'updated_at' => substr( sanitize_text_field( (string) ( $stored['updated_at'] ?? '' ) ), 0, 19 ),
		);
	}

	/**
	 * Register the Network Administrator screen.
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'UK Cookie Consent Manager Network', 'uk-cookie-consent-manager' ),
			__( 'Cookie Consent', 'uk-cookie-consent-manager' ),
			'manage_network_options',
			'uccm-network',
			array( self::class, 'render_page' ),
			'dashicons-shield-alt',
			81
		);
	}

	/**
	 * Persist allowlisted network defaults and explicit locks.
	 */
	public static function save_settings(): void {
		self::require_network_capability();
		check_admin_referer( 'uccm_save_network_settings' );
		$submitted = isset( $_POST['uccm_network'] ) && is_array( $_POST['uccm_network'] ) ? wp_unslash( $_POST['uccm_network'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Allowlisted field validation follows.
		$enabled   = isset( $submitted['enabled'] ) && is_array( $submitted['enabled'] ) ? $submitted['enabled'] : array();
		$locked    = isset( $submitted['locked'] ) && is_array( $submitted['locked'] ) ? $submitted['locked'] : array();
		$values    = isset( $submitted['values'] ) && is_array( $submitted['values'] ) ? $submitted['values'] : array();

		if ( ! array_key_exists( 'error_email_enabled', $values ) ) {
			$values['error_email_enabled'] = false;
		}

		$sanitized = Settings::sanitize( $values, Settings::defaults() );
		$defaults  = array();
		$locks     = array();

		foreach ( self::manageable_settings() as $name ) {
			if ( empty( $enabled[ $name ] ) ) {
				continue;
			}

			$defaults[ $name ] = $sanitized[ $name ];

			if ( ! empty( $locked[ $name ] ) ) {
				$locks[] = $name;
			}
		}

		update_site_option(
			self::SETTINGS_OPTION,
			array(
				'defaults' => $defaults,
				'locked'   => $locks,
			)
		);
		update_site_option( self::DELETE_OPTION, ! empty( $submitted['delete_data_on_uninstall'] ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'uccm-network',
					'uccm_notice' => 'saved',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render network progress, update controls and safe defaults.
	 */
	public static function render_page(): void {
		self::require_network_capability();
		$configuration = self::configuration();
		$state         = self::install_state();
		$status        = Secure_Updater::status();
		$site_count    = get_sites( array( 'count' => true ) );
		$site_count    = is_numeric( $site_count ) ? (int) $site_count : 0;
		$defaults      = Settings::defaults();
		$values        = array_merge( $defaults, $configuration['defaults'] );
		$labels        = self::setting_labels();
		$state_labels  = array(
			'idle'      => __( 'Not started', 'uk-cookie-consent-manager' ),
			'running'   => __( 'Running', 'uk-cookie-consent-manager' ),
			'completed' => __( 'Completed', 'uk-cookie-consent-manager' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded notice state.
		$notice = isset( $_GET['uccm_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['uccm_notice'] ) ) : '';

		echo '<div class="wrap"><h1>' . esc_html__( 'Cookie Consent Network', 'uk-cookie-consent-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage shared operational defaults while each site keeps its own consent evidence, cookie inventory, scans, policy and privacy-sensitive settings.', 'uk-cookie-consent-manager' ) . '</p>';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Network settings saved.', 'uk-cookie-consent-manager' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Network status', 'uk-cookie-consent-manager' ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Sites', 'uk-cookie-consent-manager' ) . '</th><td>' . esc_html( (string) $site_count ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Install or upgrade batch', 'uk-cookie-consent-manager' ) . '</th><td>' . esc_html(
			sprintf(
				/* translators: 1: Network batch status. 2: Number of processed sites. */
				__( '%1$s — %2$d sites processed', 'uk-cookie-consent-manager' ),
				$state_labels[ $state['status'] ],
				$state['processed']
			)
		) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Network schema version', 'uk-cookie-consent-manager' ) . '</th><td><code>' . esc_html( (string) get_site_option( self::VERSION_OPTION, '' ) ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Installed plugin version', 'uk-cookie-consent-manager' ) . '</th><td><code>' . esc_html( (string) $status['installed_version'] ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Latest authenticated version', 'uk-cookie-consent-manager' ) . '</th><td>' . esc_html( '' !== (string) $status['latest_version'] ? (string) $status['latest_version'] : __( 'Not checked yet', 'uk-cookie-consent-manager' ) ) . '</td></tr>';
		echo '</tbody></table><p><a class="button" href="' . esc_url( network_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Manage network plugins and updates', 'uk-cookie-consent-manager' ) . '</a></p>';

		echo '<h2>' . esc_html__( 'Network defaults', 'uk-cookie-consent-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enable a default to offer it to sites that inherit the setting. Lock only operational values that every site must use. Consent policy versions, scan URLs, passwords, complete-IP storage and trusted proxies always remain site-specific.', 'uk-cookie-consent-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="uccm_save_network_settings">';
		wp_nonce_field( 'uccm_save_network_settings' );
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Setting', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Use network default', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Value', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Lock for sites', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		foreach ( self::manageable_settings() as $name ) {
			$enabled = array_key_exists( $name, $configuration['defaults'] );
			$is_lock = in_array( $name, $configuration['locked'], true );
			echo '<tr><th scope="row">' . esc_html( $labels[ $name ] ) . '</th>';
			echo '<td><input type="checkbox" name="uccm_network[enabled][' . esc_attr( $name ) . ']" value="1" ' . checked( $enabled, true, false ) . '></td><td>';
			self::render_value_field( $name, $values[ $name ] );
			echo '</td><td><input type="checkbox" name="uccm_network[locked][' . esc_attr( $name ) . ']" value="1" ' . checked( $is_lock, true, false ) . '></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="uccm_network[delete_data_on_uninstall]" value="1" ' . checked( (bool) get_site_option( self::DELETE_OPTION, false ), true, false ) . '> <strong>' . esc_html__( 'Delete UCCM data from every site when the plugin is uninstalled', 'uk-cookie-consent-manager' ) . '</strong></label><br><span class="description">' . esc_html__( 'Destructive and disabled by default. A single site’s uninstall setting can never enable network-wide deletion.', 'uk-cookie-consent-manager' ) . '</span></p>';
		submit_button( __( 'Save network settings', 'uk-cookie-consent-manager' ) );
		echo '</form></div>';
	}

	/**
	 * Start a new network installation or upgrade state.
	 */
	private static function start_install_batch(): void {
		update_site_option(
			self::STATE_OPTION,
			array(
				'offset'     => 0,
				'processed'  => 0,
				'status'     => 'running',
				'version'    => UCCM_VERSION,
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Schedule network upgrades after a plugin version change.
	 */
	private static function maybe_schedule_upgrade(): void {
		if ( UCCM_VERSION === (string) get_site_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		$state = self::install_state();

		if ( 'running' !== $state['status'] || UCCM_VERSION !== $state['version'] ) {
			self::start_install_batch();
		}

		self::schedule_batch();
	}

	/**
	 * Queue one idempotent batch event on the main site.
	 */
	private static function schedule_batch(): void {
		if ( false === wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK );
		}
	}

	/**
	 * Return one deterministic site-ID page.
	 *
	 * @param int $offset Current result offset.
	 * @return int[]
	 */
	private static function site_ids( int $offset ): array {
		$site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => self::BATCH_SIZE,
				'offset'  => max( 0, $offset ),
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		return array_map( 'intval', is_array( $site_ids ) ? $site_ids : array() );
	}

	/**
	 * Run a site-owned operation and always restore the prior site.
	 *
	 * @param int      $site_id   Target site ID.
	 * @param callable $operation Operation to run.
	 */
	public static function with_site( int $site_id, callable $operation ): void {
		switch_to_blog( $site_id );

		try {
			$operation();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Enforce Network Administrator privilege.
	 */
	private static function require_network_capability(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Cookie Consent network settings.', 'uk-cookie-consent-manager' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return labels for the allowlisted defaults.
	 *
	 * @return array<string, string>
	 */
	private static function setting_labels(): array {
		return array(
			'consent_lifetime_days'           => __( 'Consent lifetime (days)', 'uk-cookie-consent-manager' ),
			'retention_days'                  => __( 'Consent retention (days)', 'uk-cookie-consent-manager' ),
			'scan_page_limit'                 => __( 'Maximum pages per scan', 'uk-cookie-consent-manager' ),
			'scan_batch_size'                 => __( 'Pages checked at a time', 'uk-cookie-consent-manager' ),
			'error_email_enabled'             => __( 'Email operational errors', 'uk-cookie-consent-manager' ),
			'error_email_suppression_minutes' => __( 'Repeat email suppression (minutes)', 'uk-cookie-consent-manager' ),
		);
	}

	/**
	 * Render a value input for one allowlisted setting.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Effective value.
	 */
	private static function render_value_field( string $name, mixed $value ): void {
		if ( 'error_email_enabled' === $name ) {
			echo '<label><input type="checkbox" name="uccm_network[values][' . esc_attr( $name ) . ']" value="1" ' . checked( ! empty( $value ), true, false ) . '> ' . esc_html__( 'Enabled', 'uk-cookie-consent-manager' ) . '</label>';
			return;
		}

		$limits = array(
			'consent_lifetime_days'           => array( 1, 730 ),
			'retention_days'                  => array( 1, 3650 ),
			'scan_page_limit'                 => array( 1, Scanner::MAX_TARGETS ),
			'scan_batch_size'                 => array( 1, 25 ),
			'error_email_suppression_minutes' => array( 1, Settings::MAX_ERROR_EMAIL_SUPPRESSION_MINUTES ),
		);
		$limit  = $limits[ $name ];

		echo '<input class="small-text" type="number" name="uccm_network[values][' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $limit[0] ) . '" max="' . esc_attr( (string) $limit[1] ) . '">';
	}
}
