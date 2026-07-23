<?php
/**
 * WordPress administration experience.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Release 1 settings, inventory and evidence screens.
 */
final class Admin {

	/**
	 * Top-level menu slug.
	 */
	private const MENU_SLUG = 'uccm';

	/**
	 * Register administration hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_uccm_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_uccm_save_blocking_rules', array( self::class, 'save_blocking_rules' ) );
		add_action( 'admin_post_uccm_save_inventory', array( self::class, 'save_inventory' ) );
		add_action( 'admin_post_uccm_export_inventory', array( self::class, 'export_inventory' ) );
	}

	/**
	 * Return the nine-screen administration contract.
	 *
	 * @return array<string, array{title: string, capability: string, callback: callable}>
	 */
	public static function screens(): array {
		return array(
			self::MENU_SLUG       => array( 'title' => __( 'Overview', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_overview' ) ),
			'uccm-banner'         => array( 'title' => __( 'Banner', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_banner' ) ),
			'uccm-categories'     => array( 'title' => __( 'Categories', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_categories' ) ),
			'uccm-blocking'       => array( 'title' => __( 'Script Blocking', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_blocking' ) ),
			'uccm-inventory'      => array( 'title' => __( 'Cookie Inventory', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_inventory', 'callback' => array( self::class, 'render_inventory' ) ),
			'uccm-scans'          => array( 'title' => __( 'Scans', 'uk-cookie-consent-manager' ), 'capability' => 'run_uccm_scans', 'callback' => array( self::class, 'render_scans' ) ),
			'uccm-consents'       => array( 'title' => __( 'Consent Records', 'uk-cookie-consent-manager' ), 'capability' => 'view_uccm_consents', 'callback' => array( self::class, 'render_consents' ) ),
			'uccm-privacy'        => array( 'title' => __( 'Privacy', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_privacy' ) ),
			'uccm-advanced'       => array( 'title' => __( 'Advanced', 'uk-cookie-consent-manager' ), 'capability' => 'manage_uccm_settings', 'callback' => array( self::class, 'render_advanced' ) ),
		);
	}

	/**
	 * Register the top-level menu and all screens.
	 */
	public static function register_menu(): void {
		$screens  = self::screens();
		$overview = $screens[ self::MENU_SLUG ];
		add_menu_page(
			__( 'UK Cookie Consent Manager', 'uk-cookie-consent-manager' ),
			__( 'Cookie Consent', 'uk-cookie-consent-manager' ),
			$overview['capability'],
			self::MENU_SLUG,
			$overview['callback'],
			'dashicons-shield-alt',
			81
		);

		foreach ( $screens as $slug => $screen ) {
			add_submenu_page(
				self::MENU_SLUG,
				$screen['title'],
				$screen['title'],
				$screen['capability'],
				$slug,
				$screen['callback']
			);
		}
	}

	/**
	 * Persist banner, privacy or advanced settings.
	 */
	public static function save_settings(): void {
		self::require_capability( 'manage_uccm_settings' );
		$section = self::request_value( $_POST, 'section' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		check_admin_referer( 'uccm_save_' . $section );
		$submitted = isset( $_POST['uccm'] ) && is_array( $_POST['uccm'] ) ? wp_unslash( $_POST['uccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Field-level validation follows.
		$submitted = is_array( $submitted ) ? $submitted : array();

		if ( 'banner' === $section ) {
			Settings::update(
				array(
					'consent_lifetime_days'  => $submitted['consent_lifetime_days'] ?? 180,
					'consent_policy_version' => $submitted['consent_policy_version'] ?? Consent_State::POLICY_VERSION,
				)
			);
		} elseif ( 'privacy' === $section ) {
			Settings::update(
				array(
					'retention_days'      => $submitted['retention_days'] ?? 365,
					'store_full_ip'       => $submitted['store_full_ip'] ?? false,
					'trust_proxy_headers' => $submitted['trust_proxy_headers'] ?? false,
					'trusted_proxy_ips'   => $submitted['trusted_proxy_ips'] ?? '',
				)
			);
		} elseif ( 'advanced' === $section ) {
			update_option( 'uccm_delete_data_on_uninstall', ! empty( $submitted['delete_data_on_uninstall'] ), false );
		} else {
			wp_die( esc_html__( 'The settings section is invalid.', 'uk-cookie-consent-manager' ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-' . $section, 'saved' );
	}

	/**
	 * Persist explicitly declared resource rules from JSON.
	 */
	public static function save_blocking_rules(): void {
		self::require_capability( 'manage_uccm_settings' );
		check_admin_referer( 'uccm_save_blocking' );
		$json  = self::request_value( $_POST, 'rules' );
		$rules = self::sanitize_blocking_rules( $json );

		if ( is_wp_error( $rules ) ) {
			wp_die( esc_html( $rules->get_error_message() ), '', array( 'response' => 400 ) );
		}

		update_option( Resource_Rules::OPTION_NAME, $rules, false );
		self::redirect( 'uccm-blocking', 'saved' );
	}

	/**
	 * Validate explicit resource rules submitted through administration.
	 *
	 * @param string $json JSON rule map.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	public static function sanitize_blocking_rules( string $json ): array|\WP_Error {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'uccm_invalid_rules_json', __( 'Blocking rules must be a JSON object.', 'uk-cookie-consent-manager' ) );
		}

		$rules = array();

		foreach ( $decoded as $rule_id => $rule ) {
			$id       = sanitize_key( (string) $rule_id );
			$type     = is_array( $rule ) ? sanitize_key( (string) ( $rule['type'] ?? '' ) ) : '';
			$category = is_array( $rule ) ? sanitize_key( (string) ( $rule['category'] ?? '' ) ) : '';
			$handle   = is_array( $rule ) ? sanitize_key( (string) ( $rule['handle'] ?? '' ) ) : '';
			$source   = is_array( $rule ) ? esc_url_raw( (string) ( $rule['source'] ?? '' ) ) : '';
			$title    = is_array( $rule ) ? sanitize_text_field( (string) ( $rule['title'] ?? '' ) ) : '';

			if ( '' === $id || ! in_array( $type, array( 'script', 'iframe', 'embed', 'pixel' ), true ) || ! in_array( $category, array( 'functional', 'analytics', 'marketing' ), true ) ) {
				return new \WP_Error( 'uccm_invalid_blocking_rule', __( 'Each blocking rule needs a valid ID, type and optional category.', 'uk-cookie-consent-manager' ) );
			}

			if ( ( 'script' === $type && '' === $handle && '' === $source ) || ( 'script' !== $type && '' === $source ) ) {
				return new \WP_Error( 'uccm_invalid_blocking_source', __( 'Each blocking rule needs an applicable handle or HTTPS source.', 'uk-cookie-consent-manager' ) );
			}

			$rules[ $id ] = compact( 'type', 'category', 'handle', 'source', 'title' );
		}

		return $rules;
	}

	/**
	 * Persist one capability-gated inventory edit.
	 */
	public static function save_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		check_admin_referer( 'uccm_save_inventory' );
		$submitted = isset( $_POST['uccm'] ) && is_array( $_POST['uccm'] ) ? wp_unslash( $_POST['uccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Inventory service validates each field.
		$result    = Cookie_Inventory::save( is_array( $submitted ) ? $submitted : array() );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-inventory', 'saved' );
	}

	/**
	 * Send a filtered, capability-gated CSV export.
	 */
	public static function export_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		check_admin_referer( 'uccm_export_inventory' );
		$records = Cookie_Inventory::export_records( self::inventory_filters() );

		if ( is_wp_error( $records ) ) {
			wp_die( esc_html( $records->get_error_message() ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="uccm-cookie-inventory.csv"' );
		echo Cookie_Inventory::csv( $records ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate CSV download with formula-neutralised cells.
		exit;
	}

	/**
	 * Render the administration overview.
	 */
	public static function render_overview(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'Cookie Consent Overview', 'uk-cookie-consent-manager' ) );
		echo '<p>' . esc_html__( 'Configure consent, review observed storage and inspect privacy-preserving evidence from this menu.', 'uk-cookie-consent-manager' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Scanning is delivered separately in UCCM-7. Until then, inventory entries are curated manually.', 'uk-cookie-consent-manager' ) . '</p></div>';
		self::close_page();
	}

	/**
	 * Render banner settings.
	 */
	public static function render_banner(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();
		self::open_page( __( 'Banner', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_banner' );
		echo '<input type="hidden" name="section" value="banner">';
		self::number_field( 'consent_lifetime_days', __( 'Consent lifetime (days)', 'uk-cookie-consent-manager' ), (int) $settings['consent_lifetime_days'], 1, 730 );
		self::text_field( 'consent_policy_version', __( 'Consent policy version', 'uk-cookie-consent-manager' ), (string) $settings['consent_policy_version'] );
		submit_button( __( 'Save banner settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render the category contract.
	 */
	public static function render_categories(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'Categories', 'uk-cookie-consent-manager' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Category', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Purpose', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Required', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		foreach ( Consent_State::categories() as $category ) {
			echo '<tr><td>' . esc_html( $category['label'] ) . '</td><td>' . esc_html( $category['description'] ) . '</td><td>' . esc_html( $category['required'] ? __( 'Yes', 'uk-cookie-consent-manager' ) : __( 'No', 'uk-cookie-consent-manager' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render explicit blocking rule management.
	 */
	public static function render_blocking(): void {
		self::require_capability( 'manage_uccm_settings' );
		$rules   = get_option( Resource_Rules::OPTION_NAME, array() );
		$encoded = wp_json_encode( is_array( $rules ) ? $rules : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		self::open_page( __( 'Script Blocking', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		echo '<p>' . esc_html__( 'Declare only known optional resources. Invalid or incomplete JSON is rejected.', 'uk-cookie-consent-manager' ) . '</p>';
		self::form_open( 'uccm_save_blocking_rules', 'uccm_save_blocking' );
		echo '<textarea class="large-text code" rows="18" name="rules">' . esc_textarea( false === $encoded ? '{}' : $encoded ) . '</textarea>';
		submit_button( __( 'Save blocking rules', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render filtered, paginated inventory and its edit form.
	 */
	public static function render_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		$page    = max( 1, (int) self::request_value( $_GET, 'paged' ) );
		$filters = self::inventory_filters();
		$records = Cookie_Inventory::records( $filters, $page, 20 );
		self::open_page( __( 'Cookie Inventory', 'uk-cookie-consent-manager' ) );
		self::saved_notice();

		if ( is_wp_error( $records ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $records->get_error_message() ) . '</p></div>';
			self::close_page();
			return;
		}

		self::render_inventory_filters( $filters );
		echo '<p><strong>' . esc_html( sprintf( _n( '%d reviewed item', '%d reviewed items', $records['total'], 'uk-cookie-consent-manager' ), $records['total'] ) ) . '</strong></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Provider / domain', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Type', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Purpose', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Category', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Duration', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Review', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		if ( array() === $records['items'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No inventory items match the current filters.', 'uk-cookie-consent-manager' ) . '</td></tr>';
		}

		foreach ( $records['items'] as $item ) {
			echo '<tr><td><strong>' . esc_html( (string) $item['storage_key'] ) . '</strong><br><small>' . esc_html( (string) $item['party'] ) . '</small></td><td>' . esc_html( (string) $item['provider'] ) . '<br><small>' . esc_html( (string) $item['domain'] ) . '</small></td><td>' . esc_html( (string) $item['storage_type'] ) . '</td><td>' . esc_html( (string) $item['purpose'] ) . '</td><td>' . esc_html( (string) $item['category'] ) . '</td><td>' . esc_html( (string) $item['duration'] ) . '</td><td>' . esc_html( (string) $item['status'] ) . '<br><small>' . esc_html( (string) $item['last_reviewed_at'] ) . '</small></td></tr>';
		}

		echo '</tbody></table>';
		self::render_pagination( $records['page'], $records['pages'], $filters );
		self::render_inventory_form();
		self::close_page();
	}

	/**
	 * Render scan empty state before UCCM-7.
	 */
	public static function render_scans(): void {
		self::require_capability( 'run_uccm_scans' );
		self::open_page( __( 'Scans', 'uk-cookie-consent-manager' ) );
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No scan runner is installed yet. Manual and monthly hybrid scanning is delivered in UCCM-7.', 'uk-cookie-consent-manager' ) . '</p></div>';
		self::close_page();
	}

	/**
	 * Render masked consent receipt evidence.
	 */
	public static function render_consents(): void {
		self::require_capability( 'view_uccm_consents' );
		$records = Consent_Receipts::records( 'view_uccm_consents', 50, false );
		self::open_page( __( 'Consent Records', 'uk-cookie-consent-manager' ) );

		if ( is_wp_error( $records ) || array() === $records ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No consent records are available to display.', 'uk-cookie-consent-manager' ) . '</p></div>';
			self::close_page();
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Receipt', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Time (UTC)', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Action', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Policy', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Masked IP', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		foreach ( $records as $record ) {
			echo '<tr><td><code>' . esc_html( (string) $record['receipt_id'] ) . '</code></td><td>' . esc_html( (string) $record['occurred_at'] ) . '</td><td>' . esc_html( (string) $record['action'] ) . '</td><td>' . esc_html( (string) $record['policy_version'] ) . '</td><td>' . esc_html( (string) $record['ip_masked'] ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render privacy and retention settings.
	 */
	public static function render_privacy(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();
		self::open_page( __( 'Privacy', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_privacy' );
		echo '<input type="hidden" name="section" value="privacy">';
		self::number_field( 'retention_days', __( 'Consent retention (days)', 'uk-cookie-consent-manager' ), (int) $settings['retention_days'], 1, 3650 );
		self::checkbox_field( 'store_full_ip', __( 'Store encrypted complete IP addresses', 'uk-cookie-consent-manager' ), ! empty( $settings['store_full_ip'] ), __( 'This increases privacy risk and is not required for normal consent evidence.', 'uk-cookie-consent-manager' ) );
		self::checkbox_field( 'trust_proxy_headers', __( 'Trust forwarded IP headers', 'uk-cookie-consent-manager' ), ! empty( $settings['trust_proxy_headers'] ), __( 'Enable only when every trusted reverse proxy is listed below.', 'uk-cookie-consent-manager' ) );
		$proxies = is_array( $settings['trusted_proxy_ips'] ) ? implode( "\n", $settings['trusted_proxy_ips'] ) : '';
		echo '<p><label for="uccm-trusted-proxies"><strong>' . esc_html__( 'Trusted proxy IPs', 'uk-cookie-consent-manager' ) . '</strong></label><br><textarea id="uccm-trusted-proxies" class="large-text code" rows="5" name="uccm[trusted_proxy_ips]">' . esc_textarea( $proxies ) . '</textarea></p>';
		submit_button( __( 'Save privacy settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render destructive-data lifecycle settings.
	 */
	public static function render_advanced(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'Advanced', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_advanced' );
		echo '<input type="hidden" name="section" value="advanced">';
		self::checkbox_field( 'delete_data_on_uninstall', __( 'Delete all UCCM data when the plugin is uninstalled', 'uk-cookie-consent-manager' ), true === get_option( 'uccm_delete_data_on_uninstall', false ), __( 'Leave disabled to retain settings and evidence by default.', 'uk-cookie-consent-manager' ) );
		submit_button( __( 'Save advanced settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render inventory filters and export control.
	 *
	 * @param array<string, string> $filters Current filters.
	 */
	private static function render_inventory_filters( array $filters ): void {
		echo '<form method="get"><input type="hidden" name="page" value="uccm-inventory"><label class="screen-reader-text" for="uccm-search">' . esc_html__( 'Search inventory', 'uk-cookie-consent-manager' ) . '</label><input id="uccm-search" type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search inventory', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<select name="uccm_category"><option value="">' . esc_html__( 'All categories', 'uk-cookie-consent-manager' ) . '</option>';
		self::options( array( 'necessary', 'functional', 'analytics', 'marketing' ), $filters['category'] );
		echo '</select> <select name="uccm_status"><option value="">' . esc_html__( 'All statuses', 'uk-cookie-consent-manager' ) . '</option>';
		self::options( array( 'known', 'new', 'changed', 'ignored', 'resolved' ), $filters['status'] );
		echo '</select> ';
		submit_button( __( 'Filter', 'uk-cookie-consent-manager' ), 'secondary', '', false );
		echo '</form>';
		$export_url = wp_nonce_url( add_query_arg( array( 'action' => 'uccm_export_inventory', 'uccm_category' => $filters['category'], 'uccm_status' => $filters['status'], 's' => $filters['search'] ), admin_url( 'admin-post.php' ) ), 'uccm_export_inventory' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export current filters as CSV', 'uk-cookie-consent-manager' ) . '</a></p>';
	}

	/**
	 * Render the create/update inventory form.
	 */
	private static function render_inventory_form(): void {
		echo '<hr><h2>' . esc_html__( 'Add or update an inventory item', 'uk-cookie-consent-manager' ) . '</h2>';
		self::form_open( 'uccm_save_inventory', 'uccm_save_inventory' );
		echo '<input type="number" min="0" name="uccm[id]" value="0" aria-label="' . esc_attr__( 'Existing database ID, or zero for a new item', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input required maxlength="191" name="uccm[storage_key]" placeholder="' . esc_attr__( 'Cookie or storage key', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input maxlength="191" name="uccm[provider]" placeholder="' . esc_attr__( 'Provider', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input maxlength="191" name="uccm[domain]" placeholder="' . esc_attr__( 'Domain', 'uk-cookie-consent-manager' ) . '"><br><br>';
		echo '<select name="uccm[party]">'; self::options( array( 'first-party', 'third-party' ), 'first-party' ); echo '</select> ';
		echo '<select name="uccm[storage_type]">'; self::options( array( 'cookie', 'local_storage', 'session_storage', 'other' ), 'cookie' ); echo '</select> ';
		echo '<select name="uccm[category]">'; self::options( array( 'necessary', 'functional', 'analytics', 'marketing' ), 'necessary' ); echo '</select> ';
		echo '<select name="uccm[status]">'; self::options( array( 'known', 'new', 'changed', 'ignored', 'resolved' ), 'known' ); echo '</select><br><br>';
		echo '<textarea required class="large-text" rows="3" name="uccm[purpose]" placeholder="' . esc_attr__( 'Purpose', 'uk-cookie-consent-manager' ) . '"></textarea>';
		echo '<input class="regular-text" maxlength="100" name="uccm[duration]" placeholder="' . esc_attr__( 'Duration', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input class="regular-text" type="url" name="uccm[source_url]" placeholder="https://">';
		submit_button( __( 'Save inventory item', 'uk-cookie-consent-manager' ) );
		self::form_close();
	}

	/**
	 * Render simple pagination.
	 *
	 * @param int                   $page    Current page.
	 * @param int                   $pages   Total pages.
	 * @param array<string, string> $filters Current filters.
	 */
	private static function render_pagination( int $page, int $pages, array $filters ): void {
		if ( 1 >= $pages ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( array( 'page' => 'uccm-inventory', 'uccm_category' => $filters['category'], 'uccm_status' => $filters['status'], 's' => $filters['search'], 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
				'current'   => $page,
				'total'     => $pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'uk-cookie-consent-manager' ),
				'next_text' => __( 'Next', 'uk-cookie-consent-manager' ),
			)
		);

		if ( is_string( $links ) ) {
			echo '<nav aria-label="' . esc_attr__( 'Inventory pages', 'uk-cookie-consent-manager' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/**
	 * Return current inventory filters.
	 *
	 * @return array{category: string, status: string, search: string}
	 */
	private static function inventory_filters(): array {
		return array(
			'category' => sanitize_key( self::request_value( $_GET, 'uccm_category' ) ),
			'status'   => sanitize_key( self::request_value( $_GET, 'uccm_status' ) ),
			'search'   => sanitize_text_field( self::request_value( $_GET, 's' ) ),
		);
	}

	/**
	 * Return a scalar request value after unslashing.
	 *
	 * @param array<string, mixed> $source Request source.
	 * @param string               $key    Field key.
	 */
	private static function request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		return is_scalar( $value ) ? (string) wp_unslash( $value ) : '';
	}

	/**
	 * Enforce a dedicated capability before rendering or mutating.
	 *
	 * @param string $capability Required capability.
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to access this Cookie Consent screen.', 'uk-cookie-consent-manager' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to a successful administration screen.
	 *
	 * @param string $page   Screen slug.
	 * @param string $notice Notice code.
	 */
	private static function redirect( string $page, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'uccm_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Open standard page markup.
	 *
	 * @param string $title Page title.
	 */
	private static function open_page( string $title ): void {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	/**
	 * Close standard page markup.
	 */
	private static function close_page(): void {
		echo '</div>';
	}

	/**
	 * Open a protected admin-post form.
	 *
	 * @param string $action       Admin-post action.
	 * @param string $nonce_action Nonce action.
	 */
	private static function form_open( string $action, string $nonce_action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce_action );
	}

	/**
	 * Close a form.
	 */
	private static function form_close(): void {
		echo '</form>';
	}

	/**
	 * Render a saved notice from a bounded query value.
	 */
	private static function saved_notice(): void {
		if ( 'saved' === self::request_value( $_GET, 'uccm_notice' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'uk-cookie-consent-manager' ) . '</p></div>';
		}
	}

	/**
	 * Render a number field.
	 */
	private static function number_field( string $name, string $label, int $value, int $min, int $max ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="number" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"></label></p>';
	}

	/**
	 * Render a text field.
	 */
	private static function text_field( string $name, string $label, string $value ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input class="regular-text" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"></label></p>';
	}

	/**
	 * Render a checkbox with explanatory text.
	 */
	private static function checkbox_field( string $name, string $label, bool $checked, string $description ): void {
		echo '<p><label><input type="checkbox" name="uccm[' . esc_attr( $name ) . ']" value="1" ' . checked( $checked, true, false ) . '> <strong>' . esc_html( $label ) . '</strong></label><br><span class="description">' . esc_html( $description ) . '</span></p>';
	}

	/**
	 * Render allowlisted select options.
	 *
	 * @param string[] $values  Option values.
	 * @param string   $current Selected value.
	 */
	private static function options( array $values, string $current ): void {
		foreach ( $values as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $value ) ) ) . '</option>';
		}
	}
}
